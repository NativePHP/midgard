<?php

namespace Native\Api\Client\Asgard;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Native\Api\Client\Asgard\Events\StreamClosed;
use Native\Api\Client\Asgard\Events\StreamMessage;
use Native\Api\Client\Asgard\Events\StreamOpened;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\Asgard\Contracts\FragmentSource;
use Native\Mobile\Edge\Asgard\ServerRegion;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Events\Screen\ScreenMounted;

/**
 * Binds the HTTP fragment source when the app runs nativephp/mobile and has
 * an Asgard server and key configured. Otherwise it does nothing, and
 * mobile's own null source keeps every region on its placeholder.
 *
 * With a server configured it also registers the server's screen routes:
 * the cached list at boot, and a fresh one fetched in the background once
 * the first screen has mounted (ServerRoutes).
 */
class AsgardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/midgard.php', 'midgard');

        if (! interface_exists(FragmentSource::class)) {
            return;
        }

        $config = static::clientConfig();

        if ($config === null) {
            return;
        }

        if (config('midgard.asgard.stream')) {
            $this->app->singleton(RegionStreams::class, fn () => new RegionStreams(
                AsgardClient::make($config),
                static fn (string $component, string $region, array $fragment, ?array $chrome = null) => ServerRegion::push($component, $region, $fragment, $chrome),
                // The handshake is network, so it runs on an AsyncTask lane.
                static fn (\Closure $then) => AsyncTask::dispatch(self::handshakeWork($config))
                    ->finished(fn ($ok) => $then($ok === true))
                    ->failed(fn () => $then(false)),
            ));
        }

        // Server screens: their routes come from the server, cached so they
        // work offline, and are fetched again in the background.
        if (method_exists(NativeRouter::class, 'registerServerRoutes')) {
            $this->app->singleton(ServerRoutes::class, fn () => new ServerRoutes(
                Cache::store($config['store']),
                ServerRoutes::keyFor($config['url'], $config['key']),
                static fn (array $routes) => NativeRouter::registerServerRoutes($routes),
                // The fetch is network, so it runs on an AsyncTask lane. Its
                // answer is taken on whichever screen is showing by then.
                static fn (\Closure $then) => AsyncTask::dispatch(self::routesWork($config))
                    ->anyScreen()
                    ->finished(fn ($result) => $then(is_array($result) ? $result : ['ok' => false, 'error' => 'no answer']))
                    ->failed(fn (\Throwable $e) => $then(['ok' => false, 'error' => $e->getMessage()])),
            ));
        }

        $this->app->singleton(FragmentSource::class, fn ($app) => new HttpFragmentSource(
            $config,
            $app->bound(RegionStreams::class) ? $app->make(RegionStreams::class) : null,
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../../config/midgard.php' => config_path('midgard.php')], 'midgard-config');

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
     * The plain config an AsyncTask lane gets, or null when Asgard is not set up.
     *
     * @return array{url: string, path: string, key: string, timeout: float, store: ?string}|null
     */
    public static function clientConfig(): ?array
    {
        $url = config('midgard.asgard.url');
        $key = config('midgard.asgard.key');

        if (! is_string($url) || $url === '') {
            return null;
        }

        if (! is_string($key) || $key === '') {
            Log::warning('Asgard: ASGARD_SERVER_URL is set but ASGARD_KEY is not, so server regions stay on their placeholders.');

            return null;
        }

        $store = config('midgard.asgard.session_store');

        return [
            'url' => $url,
            'path' => (string) config('midgard.asgard.path', '_asgard'),
            'key' => $key,
            'timeout' => (float) config('midgard.asgard.timeout', 10),
            'store' => is_string($store) && $store !== '' ? $store : null,
        ];
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
