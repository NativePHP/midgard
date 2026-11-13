<?php

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Native\Api\Client\Asgard\AsgardClient;
use Native\Api\Client\Asgard\ServerRoutes;
use Native\Api\Client\Tests\Fixtures\FakeAsgardServer;

beforeEach(function () {
    config()->set('cache.default', 'array');
    $this->server = new FakeAsgardServer;
    $this->client = AsgardClient::make(
        ['url' => 'https://asgard.test', 'path' => '_asgard', 'key' => 'test-key', 'timeout' => 5, 'store' => null],
        $this->server->client(),
    );

    $this->registered = [];
    $this->fetches = 0;
    $this->pending = null;

    // The fetch a provider would run on an AsyncTask lane, held until the test lets it finish.
    $this->routes = new ServerRoutes(
        Cache::store(),
        ServerRoutes::keyFor('https://asgard.test', 'test-key'),
        function (array $routes) {
            $this->registered[] = $routes;

            return count($routes);
        },
        function (Closure $then) {
            $this->fetches++;
            $this->pending = fn () => $then($this->client->routes());
        },
    );
});

// ── The client call ─────────────────────────────────

it('fetches the route list with a sealed call', function () {
    expect($this->client->routes())->toBe([
        'ok' => true,
        'status' => 200,
        'routes' => [['uri' => '/promos/{id}', 'screen' => 'promos.show']],
        'version' => 'v1',
    ]);

    expect($this->server->requests[0]['path'])->toBe('/_asgard/routes')
        ->and($this->server->requests[0]['body'])->toBe(['protocol' => 1])
        ->and($this->server->requests[0]['nonce'])->toBeNull();
});

it('refuses a malformed route list', function (string $plaintext) {
    $this->server->plaintext = $plaintext;

    expect($this->client->routes())->toMatchArray(['ok' => false, 'error' => 'route list is malformed']);
})->with([
    'no version' => ['{"protocol":1,"routes":[]}'],
    'routes not a list' => ['{"protocol":1,"version":"v","routes":{"a":1}}'],
    'a route without a screen' => ['{"protocol":1,"version":"v","routes":[{"uri":"/a"}]}'],
]);

it('keeps a valid title template on a route', function () {
    $this->server->routes = [['uri' => '/promos/{id}', 'screen' => 'promos.show', 'title' => 'Promo #{id}']];

    expect($this->client->routes()['routes'])->toBe([['uri' => '/promos/{id}', 'screen' => 'promos.show', 'title' => 'Promo #{id}']]);
});

it('drops a bad title and keeps its route', function (mixed $title) {
    $this->server->routes = [
        ['uri' => '/promos/{id}', 'screen' => 'promos.show', 'title' => $title],
        ['uri' => '/inbox', 'screen' => 'inbox', 'title' => 'Inbox'],
    ];

    expect($this->client->routes())->toMatchArray(['ok' => true, 'routes' => [
        ['uri' => '/promos/{id}', 'screen' => 'promos.show'],
        ['uri' => '/inbox', 'screen' => 'inbox', 'title' => 'Inbox'],
    ]]);
})->with([
    'not a string' => [['Promo']],
    'null' => [null],
    'blank' => ['  '],
    'a control character' => ["Promo\n#{id}"],
    'a bell' => ["Promo\x07"],
    'over 100 characters' => [str_repeat('a', 101)],
]);

it('takes a title of exactly 100 characters, counted as characters', function () {
    $title = str_repeat('é', 100);
    $this->server->routes = [['uri' => '/a', 'screen' => 'a', 'title' => $title]];

    expect($this->client->routes()['routes'][0]['title'])->toBe($title);
});

it('caches and registers titles with the routes', function () {
    $this->server->routes = [['uri' => '/promos/{id}', 'screen' => 'promos.show', 'title' => 'Promo #{id}'], ['uri' => '/x', 'screen' => 'x', 'title' => "bad\x00"]];
    $this->routes->refresh();
    ($this->pending)();

    $expected = [['uri' => '/promos/{id}', 'screen' => 'promos.show', 'title' => 'Promo #{id}'], ['uri' => '/x', 'screen' => 'x']];

    expect($this->registered)->toBe([$expected])
        ->and($this->routes->cached())->toBe(['version' => 'v1', 'routes' => $expected]);
});

it('hands back chrome with a fragment', function () {
    $this->server->chrome = ['nav_bar' => ['title' => 'Promo']];

    expect($this->client->render(['protocol' => 1, 'component' => 'promos.show', 'region' => 'screen', 'state' => [], 'platform' => 'ios']))
        ->toMatchArray(['ok' => true, 'chrome' => ['nav_bar' => ['title' => 'Promo']]]);
});

it('refuses chrome that is not an object', function () {
    $this->server->plaintext = '{"protocol":1,"region":"screen","fragment":[],"chrome":"x"}';

    expect($this->client->render(['protocol' => 1, 'component' => 'promos.show', 'region' => 'screen', 'state' => [], 'platform' => 'ios']))
        ->toMatchArray(['ok' => false]);
});

// ── Caching and refreshing ──────────────────────────

it('has no server routes before it has ever connected', function () {
    $this->routes->boot();

    expect($this->registered)->toBe([])
        ->and($this->routes->cached())->toBeNull();
});

it('fetches in the background and registers and caches what arrives', function () {
    $this->routes->refresh();

    // Nothing is registered until the background fetch answers.
    expect($this->fetches)->toBe(1)
        ->and($this->registered)->toBe([]);

    ($this->pending)();

    expect($this->registered)->toBe([[['uri' => '/promos/{id}', 'screen' => 'promos.show']]])
        ->and($this->routes->cached())->toBe(['version' => 'v1', 'routes' => [['uri' => '/promos/{id}', 'screen' => 'promos.show']]]);
});

it('registers the cached list at the next boot, with no network', function () {
    $this->routes->refresh();
    ($this->pending)();
    $requests = count($this->server->requests);

    $this->registered = [];
    $this->routes->boot();

    expect($this->registered)->toBe([[['uri' => '/promos/{id}', 'screen' => 'promos.show']]])
        ->and($this->server->requests)->toHaveCount($requests);
});

it('fetches once per process', function () {
    $this->routes->refresh();
    $this->routes->refresh();
    ($this->pending)();
    $this->routes->refresh();

    expect($this->fetches)->toBe(1);
});

it('does not register again when the version has not changed', function () {
    Cache::forever(ServerRoutes::keyFor('https://asgard.test', 'test-key'), ['version' => 'v1', 'routes' => []]);

    $this->routes->refresh();
    ($this->pending)();

    expect($this->registered)->toBe([]);
});

it('keeps the cached list when a fetch fails, and tries again a minute later', function () {
    Cache::forever(ServerRoutes::keyFor('https://asgard.test', 'test-key'), ['version' => 'v0', 'routes' => [['uri' => '/old', 'screen' => 'old']]]);
    $this->server->refuseWith = 500;

    $this->routes->refresh(1000);
    ($this->pending)();

    expect($this->registered)->toBe([])
        ->and($this->routes->cached()['version'])->toBe('v0');

    $this->routes->refresh(1030);
    expect($this->fetches)->toBe(1);

    $this->server->refuseWith = null;
    $this->routes->refresh(1061);
    ($this->pending)();

    expect($this->fetches)->toBe(2)
        ->and($this->registered)->toHaveCount(1)
        ->and($this->routes->cached()['version'])->toBe('v1');
});

it('treats a fetch that throws as a failure', function () {
    $routes = new ServerRoutes(Cache::store(), 'k', fn () => 0, fn () => throw new RuntimeException('no lane'));

    $routes->refresh(10);

    expect($routes->cached())->toBeNull();
});

it('keys the cache per server and Asgard key', function () {
    expect(ServerRoutes::keyFor('https://a.test/', 'k'))->toBe(ServerRoutes::keyFor('https://a.test', 'k'))
        ->and(ServerRoutes::keyFor('https://a.test', 'k'))->not->toBe(ServerRoutes::keyFor('https://b.test', 'k'))
        ->and(ServerRoutes::keyFor('https://a.test', 'k'))->not->toBe(ServerRoutes::keyFor('https://a.test', 'j'));
});

it('boots with no server routes when the cache cannot be read', function () {
    $broken = Mockery::mock(Repository::class);
    $broken->shouldReceive('get')->andThrow(new RuntimeException('no database yet'));
    $registered = 0;

    $routes = new ServerRoutes($broken, 'k', function () use (&$registered) {
        $registered++;
    }, fn () => null);

    $routes->boot();

    expect($registered)->toBe(0)->and($routes->cached())->toBeNull();
});

it('still registers a fetched list when the cache cannot be written', function () {
    $broken = Mockery::mock(Repository::class);
    $broken->shouldReceive('get')->andReturn(null);
    $broken->shouldReceive('forever')->andThrow(new RuntimeException('read-only'));
    $got = null;

    $routes = new ServerRoutes($broken, 'k', function (array $list) use (&$got) {
        $got = $list;
    }, fn () => null);

    $routes->receive(['ok' => true, 'version' => 'v1', 'routes' => [['uri' => '/promos/{id}', 'screen' => 'heimdall.promo']]]);

    expect($got)->toBe([['uri' => '/promos/{id}', 'screen' => 'heimdall.promo']]);
});
