<?php

namespace Native\Api\Client\Asgard;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Native\Api\Client\Asgard\Events\StreamMessage;
use Native\Mobile\Edge\Asgard\Contracts\FragmentSource;
use Native\Mobile\Edge\Asgard\ServerRegion;

/**
 * Binds the HTTP fragment source when the app runs nativephp/mobile and has
 * an Asgard server configured. Otherwise it does nothing, and mobile's own
 * null source keeps every region on its placeholder.
 */
class AsgardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/midgard.php', 'midgard');

        if (! interface_exists(FragmentSource::class)) {
            return;
        }

        $url = config('midgard.asgard.url');

        if (! is_string($url) || $url === '') {
            return;
        }

        $path = (string) config('midgard.asgard.path', '_asgard');

        if (config('midgard.asgard.stream')) {
            $this->app->singleton(RegionStreams::class, fn () => new RegionStreams(
                rtrim($url, '/').'/'.trim($path, '/').'/stream',
                static fn (string $component, string $region, array $fragment) => ServerRegion::push($component, $region, $fragment),
            ));
        }

        $this->app->singleton(FragmentSource::class, fn ($app) => new HttpFragmentSource(
            $url,
            $path,
            (float) config('midgard.asgard.timeout', 10),
            $app->bound(RegionStreams::class) ? $app->make(RegionStreams::class) : null,
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../../config/midgard.php' => config_path('midgard.php')], 'midgard-config');

        if ($this->app->bound(RegionStreams::class)) {
            Event::listen(StreamMessage::class, fn (StreamMessage $message) => $this->app->make(RegionStreams::class)->receive($message));
        }
    }
}
