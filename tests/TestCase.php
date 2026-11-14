<?php

namespace Native\Api\Client\Tests;

use Native\Api\Client\Asgard\AsgardServiceProvider;
use Native\Api\Client\Asgard\ServerMethods;
use Native\Api\Client\Asgard\ServerRegion;
use Native\Api\Client\Asgard\ServerScreen;
use Native\Api\Client\ServiceProvider;
use Native\Api\Client\Support\Contracts\Client;
use Native\Api\Client\Tests\Fixtures\RecordingClient;
use Native\Mobile\AsyncTask;
use Native\Mobile\NativeServiceProvider;
use Native\Mobile\Support\AsyncTaskRegistry;
use Native\Mobile\Support\Lane;
use Native\Mobile\Support\NativeCallbacks;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [NativeServiceProvider::class, ServiceProvider::class, AsgardServiceProvider::class];
    }

    protected function tearDown(): void
    {
        // nativephp/mobile and Asgard both keep state in process statics,
        // as a persistent runtime on a device does, so each test starts clean.
        AsyncTask::clearFake();
        AsyncTaskRegistry::flush();
        NativeCallbacks::flush();
        Lane::reset();

        ServerRegion::flush();
        ServerMethods::flush();
        ServerScreen::flushChrome();

        parent::tearDown();
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('nativephp.app_id', 'com.test.app');
        $app['config']->set('nativephp.version', '1.0.0');
        $app['config']->set('nativephp.version_code', 1);
        $app['config']->set('app.name', 'Test App');

        $app['config']->set('api.server.uri', 'https://api.test');
        $app['config']->set('api.server.version', 'latest');
        $app['config']->set('api.oauth_server.uri', 'https://oauth.test');
        $app['config']->set('api.oauth_server.client_id', 'client-id');
        $app['config']->set('api.oauth_server.client_secret', 'client-secret');
        $app['config']->set('api.oauth_server.redirect_uri', 'https://app.test/oauth/authenticate');
        $app['config']->set('api.oauth_server.failure_uri', 'https://app.test/oauth/unauthenticated');

        $app->singleton(Client::class, fn () => new RecordingClient);
    }

    protected function client(): RecordingClient
    {
        return $this->app->make(Client::class);
    }
}
