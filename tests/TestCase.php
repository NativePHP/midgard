<?php

namespace Native\Api\Client\Tests;

use Native\Api\Client\ServiceProvider;
use Native\Api\Client\Support\Contracts\Client;
use Native\Api\Client\Tests\Fixtures\RecordingClient;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
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
