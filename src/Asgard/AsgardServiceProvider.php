<?php

namespace Native\Api\Client\Asgard;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Native\Api\Client\Asgard\Concerns\UsesServer;
use Native\Api\Client\Asgard\Contracts\FragmentSource;
use Native\Api\Client\Asgard\Contracts\ServerTransport;
use Native\Api\Client\Asgard\Contracts\UploadTransport;
use Native\Api\Client\Asgard\Events\StreamClosed;
use Native\Api\Client\Asgard\Events\StreamMessage;
use Native\Api\Client\Asgard\Events\StreamOpened;
use Native\Api\Client\Asgard\Http\OpensServerRoutes;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\ComponentMethodInvoker;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Screen\ScreenMounted;
use Native\Mobile\Events\Screen\ScreenResumed;
use Native\Mobile\Events\Screen\ScreenUnmounted;

/**
 * Plugs Asgard into nativephp/mobile.
 *
 * It hooks AsgardExtension into every screen's runloop, adds the
 * `@server` / `@endserver` Blade directives and the `server_region`
 * element they emit, keeps UsesServer's methods out of reach of templates
 * and fragments, and on device opens server routes that arrive as
 * requests (OpensServerRoutes).
 *
 * With an Asgard server and key configured it binds the HTTP fragment
 * source, the HTTP `#[Server]` transport and the HTTP upload transport,
 * and registers the server's screen routes: the cached list at boot, and
 * a fresh one fetched in the background once the first screen has
 * mounted (ServerRoutes). Without them it binds the null source and
 * transports: every region keeps its placeholder and every `#[Server]`
 * call fails as it would with no network.
 */
class AsgardServiceProvider extends ServiceProvider
{
    /**
     * One extension for the process. NativeComponent keeps its extensions
     * statically, so a second app instance (each test boots one) must hand
     * over the same object, which extend() then ignores.
     */
    protected static ?AsgardExtension $extension = null;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/midgard.php', 'midgard');

        $config = static::clientConfig();

        if ($config === null) {
            // Defaults: a source that asks nobody and transports that fail
            // every send. `bindIf`, so an app or a test that bound its own
            // first keeps it.
            $this->app->bindIf(FragmentSource::class, NullFragmentSource::class);
            $this->app->bindIf(ServerTransport::class, NullServerTransport::class);
            $this->app->bindIf(UploadTransport::class, NullUploadTransport::class);

            return;
        }

        if (config('midgard.asgard.stream')) {
            $this->app->singleton(RegionStreams::class, fn () => new RegionStreams(
                AsgardClient::make($config),
                static fn (string $component, array $state, string $region, array $fragment, ?array $chrome, string $version) => ServerRegion::push($component, $state, $region, $fragment, $chrome, $version),
                static fn (string $component, array $state, string $region, string $version) => ServerRegion::signal($component, $state, $region, $version),
                // The handshake is network, so it runs on an AsyncTask lane.
                static fn (\Closure $then) => AsyncTask::dispatch(self::handshakeWork($config))
                    ->finished(fn ($ok) => $then($ok === true))
                    ->failed(fn () => $then(false)),
            ));
        }

        // Server screens: their routes come from the server, cached so they
        // work offline, and are fetched again in the background.
        $this->app->singleton(ServerRoutes::class, fn () => new ServerRoutes(
            Cache::store($config['store']),
            ServerRoutes::keyFor($config['base'], $config['key']),
            static fn (array $routes) => ServerScreen::registerRoutes($routes),
            // The fetch is network, so it runs on an AsyncTask lane. Its
            // answer is taken on whichever screen is showing by then.
            static fn (\Closure $then) => AsyncTask::dispatch(self::routesWork($config))
                ->anyScreen()
                ->finished(fn ($result) => $then(is_array($result) ? $result : ['ok' => false, 'error' => 'no answer']))
                ->failed(fn (\Throwable $e) => $then(['ok' => false, 'error' => $e->getMessage()])),
        ));

        $this->app->singleton(FragmentSource::class, fn ($app) => new HttpFragmentSource(
            $config,
            $app->bound(RegionStreams::class) ? $app->make(RegionStreams::class) : null,
        ));

        // #[Server] methods go to the same server, on AsyncTask lanes too.
        $this->app->singleton(ServerTransport::class, fn () => new HttpServerTransport($config));

        // So do uploads to #[Upload] methods, with their own timeout.
        $this->app->singleton(UploadTransport::class, fn () => new HttpUploadTransport($config));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../../config/midgard.php' => config_path('midgard.php')], 'midgard-config');

        NativeComponent::extend(static::$extension ??= new AsgardExtension);

        // UsesServer puts its plumbing on the app's own class. No template
        // or fragment may name any of it.
        ComponentMethodInvoker::reserveTrait(UsesServer::class);

        // The container `@server(...)` opens. Registered as an element so
        // `<native:*>` resolution and fragment validation both see it. It
        // is only ever emitted by the directive.
        ElementRegistry::register('server_region', Elements\ServerRegion::class);

        $this->registerBladeDirectives();
        $this->registerScreenListeners();
        $this->registerServerRouteMiddleware();

        if ($this->app->bound(ServerRoutes::class)) {
            $routes = $this->app->make(ServerRoutes::class);

            // The cached list, now: no network, so boot never waits on it.
            $routes->boot();

            // A fresh list once the first screen is up, in the background.
            Event::listen(ScreenMounted::class, fn () => $routes->refresh());
        }

        if ($this->app->bound(RegionStreams::class)) {
            $streams = fn () => $this->app->make(RegionStreams::class);

            Event::listen(StreamMessage::class, fn (StreamMessage $event) => $streams()->receive($event));
            Event::listen(StreamOpened::class, fn (StreamOpened $event) => $streams()->opened($event));
            Event::listen(StreamClosed::class, fn (StreamClosed $event) => $streams()->closed($event));
        }
    }

    /**
     * Asgard regions (protocol §3):
     *
     *     @server('sales-summary')
     *         <x-skeleton-card />
     *
     *     @endserver
     *
     * open() emits the `server_region` node and returns whether the
     * placeholder body should run. False means it already painted a
     * fragment in its place. The body is guarded rather than always
     * rendered because a fragment replaces the placeholder: rendering both
     * and dropping one would run the placeholder's own Blade (and any
     * component it mounts) for nothing.
     */
    protected function registerBladeDirectives(): void
    {
        Blade::directive('server', function ($expression) {
            return "<?php if (\\Native\\Api\\Client\\Asgard\\ServerRegion::open({$expression})): ?>";
        });

        Blade::directive('endserver', function () {
            return '<?php endif; \\Native\\Api\\Client\\Asgard\\ServerRegion::close(); ?>';
        });
    }

    protected function registerScreenListeners(): void
    {
        // A server screen that gave up on its body left for another. Its
        // event fires once that screen is the live one, so it hears it.
        $failures = static function () {
            foreach (ServerScreen::takeFailures() as $failure) {
                ServerRegion::fire($failure);
            }
        };

        Event::listen(ScreenMounted::class, $failures);
        Event::listen(ScreenResumed::class, $failures);

        // An unmounted screen's regions leave the push stream now, with its
        // children's, rather than waiting for a frame that may not come.
        Event::listen(ScreenUnmounted::class, fn () => ServerRegion::sync());
    }

    /**
     * Server routes live only in NativeRouter, so a cold deep link or a
     * hot-reload restore to one would 404 in Laravel's router. This opens
     * them when no Laravel route claims the path.
     *
     * On device only: NATIVEPHP_PLATFORM is set by the iOS and Android
     * hosts, so a normal web app never pays for this. Not gated on
     * runningInConsole(), for the same reason nativephp/mobile gives for
     * its own screen middleware.
     */
    protected function registerServerRouteMiddleware(): void
    {
        if (! in_array(env('NATIVEPHP_PLATFORM'), ['ios', 'android'], true)) {
            return;
        }

        $this->app->make(HttpKernel::class)->pushMiddleware(OpensServerRoutes::class);
    }

    /**
     * The plain config an AsyncTask lane gets, or null when Asgard is not set up.
     *
     * @return array{base: string, key: string, timeout: float, upload_timeout: float, store: ?string}|null
     */
    public static function clientConfig(): ?array
    {
        $base = config('midgard.asgard.base');
        $key = config('midgard.asgard.key');

        if (! is_string($base) || $base === '') {
            return null;
        }

        $problem = static::baseProblem($base, (bool) config('app.debug'));

        if ($problem !== null) {
            Log::warning("Asgard: ASGARD_BASE_URL {$problem}, so server regions stay on their placeholders.");

            return null;
        }

        if (! is_string($key) || $key === '') {
            Log::warning('Asgard: ASGARD_BASE_URL is set but ASGARD_KEY is not, so server regions stay on their placeholders.');

            return null;
        }

        $store = config('midgard.asgard.session_store');

        return [
            'base' => rtrim($base, '/'),
            'key' => $key,
            'timeout' => (float) config('midgard.asgard.http_timeout', 20),
            'upload_timeout' => (float) config('midgard.asgard.http_upload_timeout', 150),
            'store' => is_string($store) && $store !== '' ? $store : null,
        ];
    }

    /**
     * Why a base URL cannot be used, or null when it can (§8): an absolute
     * https URL with a host and no query or fragment. Plain http is allowed
     * while app.debug is on, for a server on the developer's machine, as
     * media URLs are (§9.6).
     */
    public static function baseProblem(string $base, bool $debug): ?string
    {
        $parts = parse_url($base);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';

        if (! is_array($parts) || ! is_string($parts['host'] ?? null) || $parts['host'] === ''
            || ! in_array($scheme, $debug ? ['https', 'http'] : ['https'], true)) {
            return $debug ? 'must be an absolute https:// or http:// URL' : 'must be an absolute https:// URL';
        }

        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])
            || str_contains($base, '?') || str_contains($base, '#')) {
            return 'must not have a query, a fragment or credentials';
        }

        return null;
    }

    /** The background handshake an AsyncTask lane runs. See routesWork(). */
    protected static function handshakeWork(array $config): \Closure
    {
        return static fn () => RenderCall::handshake($config);
    }

    /**
     * The route fetch an AsyncTask lane runs. It is built here, on a line of
     * its own, because a serialized closure is found by the line it starts
     * on: written inline next to the closure that dispatches it, the lane
     * got that outer closure instead and called it with no arguments.
     */
    protected static function routesWork(array $config): \Closure
    {
        return static fn () => RenderCall::routes($config);
    }
}
