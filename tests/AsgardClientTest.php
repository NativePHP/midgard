<?php

use Illuminate\Support\Facades\Cache;
use Native\Api\Client\Asgard\AsgardClient;
use Native\Api\Client\Asgard\Security\ContentDigest;
use Native\Api\Client\Asgard\SessionCache;
use Native\Api\Client\Tests\Fixtures\FakeAsgardServer;

beforeEach(function () {
    config()->set('cache.default', 'array');
    $this->server = new FakeAsgardServer;
    $this->config = ['url' => 'https://asgard.test', 'path' => '_asgard', 'key' => 'test-key', 'timeout' => 5, 'store' => null];
    $this->client = AsgardClient::make($this->config, $this->server->client());
});

function renderBody(array $overrides = []): array
{
    return ['protocol' => 1, 'component' => 'app.native.screens.home', 'region' => 'stats', 'state' => [], 'platform' => 'ios', ...$overrides];
}

it('handshakes once, then signs, encrypts and verifies every render', function () {
    expect($this->client->render(renderBody()))->toBe(['ok' => true, 'status' => 200, 'fragment' => ['type' => 'text', 'props' => ['text' => 'hi']]])
        ->and($this->client->render(renderBody()))->toMatchArray(['ok' => true]);

    expect($this->server->handshakes)->toBe(1)
        ->and($this->server->requests)->toHaveCount(2)
        ->and($this->server->requests[0]['body'])->toBe(renderBody())
        ->and($this->server->requests[0]['nonce'])->toBeNull();
});

it('keeps the session in the cache, where every lane can read it', function () {
    $this->client->render(renderBody());

    $other = AsgardClient::make($this->config, $this->server->client());

    expect($other->currentSession()?->id)->toBe(array_key_first($this->server->sessions));
    $other->render(renderBody());
    expect($this->server->handshakes)->toBe(1);
});

it('sends a nonce with an action', function () {
    $this->client->render(renderBody(['action' => ['call' => 'shuffle', 'args' => [3]]]));

    expect($this->server->requests[0]['nonce'])->toMatch('/^[A-Za-z0-9_-]{22}$/');
});

it('handshakes again and retries once when the server lost the session', function () {
    $this->client->render(renderBody());
    $this->server->forgetSessions();

    expect($this->client->render(renderBody()))->toMatchArray(['ok' => true])
        ->and($this->server->handshakes)->toBe(2)
        ->and($this->server->requests)->toHaveCount(3);
});

it('retries a 419 only once', function () {
    $this->client->render(renderBody());
    $this->server->refuseWith = 419;

    expect($this->client->render(renderBody()))->toMatchArray(['ok' => false, 'status' => 419])
        ->and($this->server->handshakes)->toBe(2)
        ->and($this->server->requests)->toHaveCount(3);
});

it('never retries a 403', function () {
    $this->server->refuseWith = 403;

    expect($this->client->render(renderBody()))->toBe(['ok' => false, 'status' => 403, 'error' => 'unsigned response'])
        ->and($this->server->requests)->toHaveCount(1);
});

it('never hands back a fragment whose response does not verify', function (Closure $tamper, string $error) {
    $this->server->tamper = $tamper;

    $result = $this->client->render(renderBody());

    expect($result['ok'])->toBeFalse()
        ->and($result)->not->toHaveKey('fragment')
        ->and($result['error'])->toContain($error);
})->with([
    'body changed' => [function ($s) {
        $s['body'][30] = chr(ord($s['body'][30]) ^ 1);

        return $s;
    }, 'Content-Digest'],
    'body and digest changed' => [function ($s) {
        $s['body'][30] = chr(ord($s['body'][30]) ^ 1);
        $s['headers']['content-digest'] = ContentDigest::header($s['body']);

        return $s;
    }, 'signature does not verify'],
    'signature changed' => [function ($s) {
        $s['headers']['signature'] = 'asgard=:'.base64_encode(str_repeat('x', 32)).':';

        return $s;
    }, 'signature does not verify'],
    'no signature' => [function ($s) {
        unset($s['headers']['signature']);

        return $s;
    }, 'unsigned response'],
]);

it('refuses a response replayed from another request', function () {
    $captured = null;
    $this->server->tamper = function ($sealed) use (&$captured) {
        return $captured ??= $sealed;
    };

    expect($this->client->render(renderBody()))->toMatchArray(['ok' => true])
        ->and($this->client->render(renderBody()))->toMatchArray(['ok' => false, 'error' => 'response failed verification: The response signature does not verify.']);
});

it('reports a refused handshake without retrying it', function () {
    $client = AsgardClient::make(['key' => 'wrong'] + $this->config, $this->server->client());

    expect($client->render(renderBody()))->toBe(['ok' => false, 'status' => 403, 'error' => 'Handshake refused: unknown_key'])
        ->and($this->server->handshakes)->toBe(1);
});

it('treats a session as gone shortly before the server would', function () {
    $cache = new SessionCache(Cache::store(), 'k');
    $cache->put('session-id-0000000', str_repeat('s', 32), 1800, 1000);

    expect($cache->get(1000 + 1800 - 61)?->id)->toBe('session-id-0000000')
        ->and($cache->get(1000 + 1800 - 60))->toBeNull();

    $cache->touch('session-id-0000000', 5000);
    expect($cache->get(5000 + 1700))->not->toBeNull();

    $cache->forget('another-session-00');
    expect($cache->get(5000))->not->toBeNull();
    $cache->forget('session-id-0000000');
    expect($cache->get(5000))->toBeNull();
});

it('signs the authority with a non-default port and without a default one', function () {
    $server = new FakeAsgardServer;
    $client = AsgardClient::make(['url' => 'http://asgard.test:8080'] + $this->config, $server->client());
    expect($client->render(renderBody()))->toMatchArray(['ok' => true]);

    $client = AsgardClient::make(['url' => 'http://ASGARD.test:80'] + $this->config, $server->client());
    expect($client->render(renderBody()))->toMatchArray(['ok' => true]);
});
