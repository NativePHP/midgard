<?php

use Native\Api\Client\Asgard\AsgardClient;
use Native\Api\Client\Asgard\AsgardServiceProvider;
use Native\Api\Client\Tests\Fixtures\FakeAsgardServer;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\Column;

/**
 * One base URL for the server (protocol §8), and the element types the
 * handshake advertises (§6.1).
 */
beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('midgard.asgard.key', 'test-key');
    config()->set('app.debug', false);
});

it('takes one base URL and puts every endpoint below it', function () {
    config()->set('midgard.asgard.base', 'https://api.example.com/asgard/');

    $config = AsgardServiceProvider::clientConfig();
    $client = AsgardClient::make($config + ['elements' => []]);

    expect($config['base'])->toBe('https://api.example.com/asgard')
        ->and($config)->not->toHaveKeys(['url', 'path']);

    foreach (['session', 'render', 'stream', 'call', 'upload', 'routes'] as $name) {
        expect($client->endpoint($name))->toBe("https://api.example.com/asgard/{$name}");
    }
});

it('is off without a base URL', function () {
    config()->set('midgard.asgard.base', null);

    expect(AsgardServiceProvider::clientConfig())->toBeNull();
});

it('refuses a base URL that is not https, has a query or fragment, or has no host', function (string $base) {
    config()->set('midgard.asgard.base', $base);

    expect(AsgardServiceProvider::clientConfig())->toBeNull();
})->with([
    'http outside debug' => ['http://asgard-demo-server.test/_asgard'],
    'a query' => ['https://api.example.com/asgard?x=1'],
    'a fragment' => ['https://api.example.com/asgard#x'],
    'credentials' => ['https://me:pw@api.example.com/asgard'],
    'no host' => ['https:///asgard'],
    'relative' => ['/asgard'],
    'another scheme' => ['ftp://api.example.com/asgard'],
]);

it('allows an http base URL while app.debug is on, for a server on this machine', function () {
    config()->set('app.debug', true);
    config()->set('midgard.asgard.base', 'http://asgard-demo-server.test/_asgard');

    expect(AsgardServiceProvider::clientConfig()['base'])->toBe('http://asgard-demo-server.test/_asgard');
});

it('advertises what the element registry holds beyond core', function () {
    $registered = ElementRegistry::all();
    ElementRegistry::register('list_item', Column::class);
    ElementRegistry::register('webview', Column::class);

    try {
        expect(AsgardClient::advertisedElements())->toContain('list_item', 'webview')->not->toContain('column', 'top_bar')
            ->and(AsgardClient::make(['base' => 'https://a.test/_asgard', 'key' => 'k', 'store' => null])->elements())->toBe(AsgardClient::advertisedElements());
    } finally {
        ElementRegistry::reset();
        foreach ($registered as $type => $class) {
            ElementRegistry::register($type, $class);
        }
    }
});

it('sends the extra element types in a plain POST handshake, without the override header', function () {
    $server = new FakeAsgardServer;
    $client = AsgardClient::make(['base' => 'https://asgard.test/_asgard', 'key' => 'test-key', 'store' => null, 'elements' => ['list_item', 'toggle']], $server->client());

    $client->handshake();

    expect($server->methods)->toBe(['POST'])
        ->and($server->handshakeBodies[0])->toMatchArray(['protocol' => 1, 'elements' => ['list_item', 'toggle']]);
});

it('sends an empty elements list when there is nothing beyond core', function () {
    $server = new FakeAsgardServer;
    $client = AsgardClient::make(['base' => 'https://asgard.test/_asgard', 'key' => 'test-key', 'store' => null, 'elements' => []], $server->client());

    $client->handshake();

    expect($server->handshakeBodies[0]['elements'])->toBe([]);
});
