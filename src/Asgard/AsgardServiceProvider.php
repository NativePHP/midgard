<?php

namespace Native\Api\Client\Asgard;

use Illuminate\Support\ServiceProvider;
use Native\Mobile\Edge\Asgard\Contracts\FragmentSource;

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

        $this->app->singleton(FragmentSource::class, fn () => new HttpFragmentSource(
            $url,
            (string) config('midgard.asgard.path', '_asgard'),
            (float) config('midgard.asgard.timeout', 10),
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../../config/midgard.php' => config_path('midgard.php')], 'midgard-config');
    }
}
