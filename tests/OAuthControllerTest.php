<?php

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use League\OAuth2\Client\Provider\GenericProvider as OAuthProvider;
use Native\Api\Client\Auth\OAuthController;

function oauthController(Request $request): array
{
    $provider = Mockery::mock(OAuthProvider::class);
    $provider->shouldReceive('getAuthorizationUrl')->andReturn('https://oauth.test/oauth/authorize?state=xyz');
    $provider->shouldReceive('getState')->andReturn('xyz');

    app()->instance('request', $request);

    return [new OAuthController($request, $provider), $provider];
}

it('redirects to the authorize url when there is no code', function () {
    [$controller] = oauthController(Request::create('/oauth/authenticate', 'GET'));

    $response = $controller->authenticate();

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toBe('https://oauth.test/oauth/authorize?state=xyz')
        ->and(Session::get('oauth.state'))->toBe('xyz');
});

it('redirects to the failure uri with a message when the server returns an error', function () {
    [$controller] = oauthController(
        Request::create('/oauth/authenticate', 'GET', ['error' => 'access_denied', 'message' => 'Nope'])
    );

    $response = $controller->authenticate();

    expect($response->getTargetUrl())->toBe('https://app.test/oauth/unauthenticated')
        ->and($response->getSession()->get('message'))->toBe('Nope');
});

it('rejects a mismatched state', function () {
    [$controller] = oauthController(
        Request::create('/oauth/authenticate', 'GET', ['code' => 'abc', 'state' => 'wrong'])
    );

    Session::put('oauth.state', 'xyz');

    $controller->authenticate();
})->throws(Exception::class, 'Invalid state returned');

it('renders a 401 for the unauthenticated route', function () {
    [$controller] = oauthController(Request::create('/oauth/unauthenticated', 'GET'));

    $response = $controller->unauthenticated();

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getContent())->toContain('Unauthenticated');
});
