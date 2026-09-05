<?php

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use League\OAuth2\Client\Provider\GenericProvider as OAuthProvider;
use Native\Api\Client\Auth\UserProvider;
use Native\Api\Client\Support\Contracts\Client;
use Native\Api\Client\Support\Eloquent\ApiConnection;
use Native\Api\Client\Support\Eloquent\ApiConnector;

it('merges the package config', function () {
    expect(config('api.session_keys.oauth_state'))->toBe('oauth.state')
        ->and(config('api.session_keys.oauth_access_token'))->toBe('oauth.access_token');
});

it('registers the api database connection', function () {
    expect(config('database.connections.api.driver'))->toBe('api')
        ->and(DB::connection('api'))->toBeInstanceOf(ApiConnection::class)
        ->and(DB::connection('api')->getDriverName())->toBe('api');
});

it('binds the api connector', function () {
    expect(app('db.connector.api'))->toBeInstanceOf(ApiConnector::class);
});

it('always has a client bound', function () {
    expect(app()->bound(Client::class))->toBeTrue()
        ->and(app(Client::class))->toBeInstanceOf(Client::class);
});

it('adds a paginate macro to eloquent collections', function () {
    $page = Collection::make([1, 2, 3, 4, 5])->paginate(2, null, 1);

    expect($page->items())->toBe([1, 2])
        ->and($page->total())->toBe(5);
});

it('registers the oauth auth provider', function () {
    config()->set('auth.providers.oauth', ['driver' => 'oauth']);

    expect(Auth::createUserProvider('oauth'))->toBeInstanceOf(UserProvider::class);
});

it('builds the oauth client from config', function () {
    $provider = app(OAuthProvider::class);

    expect($provider)->toBeInstanceOf(OAuthProvider::class)
        ->and($provider->getBaseAuthorizationUrl())->toBe('https://oauth.test/oauth/authorize');
});

it('registers the api view namespace', function () {
    expect(View::exists('api::auth.failure'))->toBeTrue();
});

it('resolves an api connection that talks to the bound client', function () {
    $connection = DB::connection('api');

    expect($connection->getClient())->toBe(app(Client::class));
});
