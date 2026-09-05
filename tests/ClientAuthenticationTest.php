<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use League\OAuth2\Client\Provider\GenericProvider as OAuthProvider;
use League\OAuth2\Client\Token\AccessToken;
use Native\Api\Client\Auth\ClientAuthentication;
use Native\Api\Client\Support\Contracts\Client;

function jwt(array $scopes, ?DateTimeImmutable $expiry = null): string
{
    $config = Configuration::forSymmetricSigner(
        new Sha256,
        InMemory::plainText('a-string-secret-at-least-256-bits-long')
    );

    $builder = $config->builder()->withClaim('scopes', $scopes);

    if ($expiry) {
        $builder = $builder->expiresAt($expiry);
    }

    return $builder->getToken($config->signer(), $config->signingKey())->toString();
}

function middleware(array $defaultScopes = [], ?string $freshToken = null): array
{
    $provider = Mockery::mock(OAuthProvider::class);
    $provider->shouldReceive('getDefaultScopes')->andReturn($defaultScopes);
    $provider->shouldReceive('getAccessToken')
        ->andReturn(new AccessToken(['access_token' => $freshToken ?? jwt(['read'])]));

    return [new ClientAuthentication(app(), $provider), $provider];
}

beforeEach(function () {
    Storage::fake();
    $this->request = Request::create('/cars', 'GET');
    $this->path = md5('GET/cars');
});

it('reuses a stored token that is valid and covers the requested scopes', function () {
    $stored = jwt(['read'], new DateTimeImmutable('+1 hour'));
    Storage::put($this->path, $stored);

    [$middleware, $provider] = middleware(['read']);

    $middleware->handle($this->request, fn ($request) => 'next');

    $provider->shouldNotHaveReceived('getAccessToken');
    expect(Storage::get($this->path))->toBe($stored);
});

it('replaces an expired token', function () {
    Storage::put($this->path, jwt(['read'], new DateTimeImmutable('-1 hour')));

    $fresh = jwt(['read'], new DateTimeImmutable('+1 hour'));
    [$middleware] = middleware(['read'], $fresh);

    $middleware->handle($this->request, fn ($request) => 'next');

    expect(Storage::get($this->path))->toBe($fresh);
});

it('replaces a token that is missing a requested scope', function () {
    Storage::put($this->path, jwt(['read'], new DateTimeImmutable('+1 hour')));

    $fresh = jwt(['read', 'write'], new DateTimeImmutable('+1 hour'));
    [$middleware] = middleware([], $fresh);

    $middleware->handle($this->request, fn ($request) => 'next', 'write');

    expect(Storage::get($this->path))->toBe($fresh);
});

it('replaces a token that is not a JWT at all', function () {
    Storage::put($this->path, 'not-a-jwt');

    $fresh = jwt(['read'], new DateTimeImmutable('+1 hour'));
    [$middleware] = middleware([], $fresh);

    $middleware->handle($this->request, fn ($request) => 'next');

    expect(Storage::get($this->path))->toBe($fresh);
});

it('fetches a token when storage has none', function () {
    $fresh = jwt(['read'], new DateTimeImmutable('+1 hour'));
    [$middleware, $provider] = middleware([], $fresh);

    $response = $middleware->handle($this->request, fn ($request) => 'next');

    expect($response)->toBe('next')
        ->and(Storage::get($this->path))->toBe($fresh);
    $provider->shouldHaveReceived('getAccessToken');
});

it('keys stored tokens by request method and path', function () {
    $fresh = jwt(['read'], new DateTimeImmutable('+1 hour'));
    [$middleware] = middleware([], $fresh);

    $middleware->handle(Request::create('/vans', 'POST'), fn ($request) => 'next');

    expect(Storage::exists(md5('POST/vans')))->toBeTrue()
        ->and(Storage::exists(md5('GET/cars')))->toBeFalse();
});

it('rebinds the client with the bearer token', function () {
    $fresh = jwt(['read'], new DateTimeImmutable('+1 hour'));
    [$middleware] = middleware([], $fresh);

    app()->bind(Client::class, fn () => new class([]) implements Client
    {
        public array $config;

        public function __construct(array $config = [])
        {
            $this->config = $config;
        }

        public function get($uri, $options = []) {}

        public function post($uri, $options = []) {}

        public function patch($uri, $options = []) {}

        public function delete($uri, $options = []) {}

        public function put() {}

        public function getMeta($key = null) {}

        public function getLinks($key = null) {}

        public function getValidationErrors() {}
    });

    $middleware->handle($this->request, fn ($request) => 'next');

    expect(app(Client::class)->config)->toBe([
        'headers' => ['Authorization' => 'Bearer '.$fresh],
    ]);
});
