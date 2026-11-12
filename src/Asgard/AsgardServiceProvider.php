<?php

namespace Native\Api\Client\Asgard;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Native\Api\Client\Asgard\Events\StreamClosed;
use Native\Api\Client\Asgard\Events\StreamMessage;
use Native\Api\Client\Asgard\Events\StreamOpened;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\Asgard\Contracts\FragmentSource;
use Native\Mobile\Edge\Asgard\ServerRegion;

/**
 * Binds the HTTP fragment source when the app runs nativephp/mobile and has
 * an Asgard server and key configured. Otherwise it does nothing, and
 * mobile's own null source keeps every region on its placeholder.
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
                static fn (string $component, string $region, array $fragment) => ServerRegion::push($component, $region, $fragment),
                // The handshake is network, so it runs on an AsyncTask lane.
                static fn (\Closure $then) => AsyncTask::dispatch(static fn () => RenderCall::handshake($config))
                    ->finished(fn ($ok) => $then($ok === true))
                    ->failed(fn () => $then(false)),
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
}
