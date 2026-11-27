<?php

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Native\Api\Client\Asgard\AsgardClient;
use Native\Api\Client\Asgard\Security\ContentDigest;
use Native\Api\Client\Asgard\Security\Gzip;
use Native\Api\Client\Asgard\SessionCache;
use Native\Api\Client\Tests\Fixtures\FakeAsgardServer;

beforeEach(function () {
    config()->set('cache.default', 'array');
    $this->server = new FakeAsgardServer;
    $this->config = ['base' => 'https://asgard.test/_asgard', 'key' => 'test-key', 'timeout' => 5, 'store' => null];
    $this->client = AsgardClient::make($this->config, $this->server->client());
});

function renderBody(array $overrides = []): array
{
    return ['protocol' => 1, 'component' => 'app.native.screens.home', 'regions' => ['stats'], 'state' => [], ...$overrides];
}

it('handshakes once, then signs, encrypts and verifies every render', function () {
    expect($this->client->render(renderBody()))->toBe(['ok' => true, 'status' => 200, 'delta' => [], 'fragments' => ['stats' => ['fragment' => ['type' => 'text', 'props' => ['text' => 'hi']]]]])
        ->and($this->client->render(renderBody()))->toMatchArray(['ok' => true]);

    expect($this->server->handshakes)->toBe(1)
        ->and($this->server->requests)->toHaveCount(2)
        ->and($this->server->requests[0]['body'])->toBe(renderBody())
        ->and($this->server->requests[0]['nonce'])->toBeNull();
});

it('hands back a well-formed version token and drops a malformed one', function (mixed $version, ?string $expected) {
    $this->server->plaintext = json_encode(['protocol' => 1, 'delta' => (object) [], 'fragments' => ['stats' => ['version' => $version, 'fragment' => ['type' => 'text']]]]);

    $result = $this->client->render(renderBody());

    expect($result['ok'])->toBeTrue()
        ->and($result['fragments']['stats']['version'] ?? null)->toBe($expected);
})->with([
    'a token' => ['q3V0bG9yZW1pcHN1', 'q3V0bG9yZW1pcHN1'],
    'not a string' => [5, null],
    'empty' => ['', null],
    'too long' => [str_repeat('a', 65), null],
    'other characters' => ['a b', null],
]);

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
        ->and($result)->not->toHaveKey('fragments')
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
    }, 'the answer is not signed'],
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

it('offers the versions it speaks and refuses an answer that picks another', function () {
    $this->server->answerProtocol = 2;

    expect($this->client->render(renderBody()))->toMatchArray(['ok' => false, 'error' => 'Handshake answer is malformed.'])
        ->and($this->server->handshakeBodies[0]['protocols'])->toBe([1])
        ->and($this->server->handshakeBodies[0])->not->toHaveKey('protocol');
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
    $client = AsgardClient::make(['base' => 'http://asgard.test:8080/_asgard'] + $this->config, $server->client());
    expect($client->render(renderBody()))->toMatchArray(['ok' => true]);

    $client = AsgardClient::make(['base' => 'http://ASGARD.test:80/_asgard'] + $this->config, $server->client());
    expect($client->render(renderBody()))->toMatchArray(['ok' => true]);
});

it('undoes gzip after decrypting a compressed answer', function () {
    $rows = array_map(fn ($i) => ['type' => 'text', 'props' => ['text' => "Row {$i}"]], range(1, 200));
    $this->server->fragment = ['type' => 'column', 'children' => $rows];
    $this->server->compress = true;
    $seen = null;
    $this->server->tamper = function ($sealed) use (&$seen) {
        return $seen = $sealed;
    };

    expect($this->client->render(renderBody()))->toMatchArray(['ok' => true, 'fragments' => ['stats' => ['fragment' => ['type' => 'column', 'children' => $rows]]]])
        ->and($seen['headers']['content-encoding'])->toBe('aes128gcm')
        ->and($seen['headers']['asgard-content-coding'])->toBe('gzip');
});

it('fails, and never hands back a fragment, when an answer inflates past the cap', function () {
    $this->server->compress = true;
    $this->server->plaintext = '{"fragments":{"stats":{"fragment":"'.str_repeat('a', Gzip::MAX_INFLATED).'"}}}';

    $result = $this->client->render(renderBody());

    expect($result['ok'])->toBeFalse()
        ->and($result)->not->toHaveKey('fragments')
        ->and($result['error'])->toContain('inflates past');
});

it('refuses an answer whose compression marker was flipped or stripped', function (Closure $change) {
    $this->server->fragment = ['type' => 'column', 'children' => array_map(fn ($i) => ['type' => 'text', 'props' => ['text' => "Row {$i}"]], range(1, 200))];
    $this->server->compress = true;
    $this->server->tamper = $change;

    $result = $this->client->render(renderBody());

    expect($result['ok'])->toBeFalse()->and($result)->not->toHaveKey('fragments');
})->with([
    'flipped' => [function ($s) {
        $s['headers']['asgard-content-coding'] = 'identity';

        return $s;
    }],
    'stripped' => [function ($s) {
        unset($s['headers']['asgard-content-coding']);

        return $s;
    }],
    'stacked content coding, as a proxy might rewrite it' => [function ($s) {
        $s['headers']['content-encoding'] = 'gzip, aes128gcm';

        return $s;
    }],
]);

// ── Parts, inline fragments and hints ───────────

it('hands back the action\'s own region next to the regions and hints its answer carries', function () {
    $this->server->plaintext = json_encode([
        'protocol' => 1,
        'delta' => ['count' => 2],
        'fragments' => [
            'stats' => ['version' => 'v1', 'made_at' => 1763150000123, 'fragment' => ['type' => 'text']],
            'greeting' => ['version' => 'v2', 'fragment' => ['type' => 'column'], 'chrome' => []],
        ],
        'refresh' => [['component' => 'app.inbox']],
    ]);

    expect($this->client->render(renderBody(['action' => ['call' => 'bump', 'args' => []]])))->toBe([
        'ok' => true, 'status' => 200,
        'delta' => ['count' => 2],
        'fragments' => [
            'stats' => ['fragment' => ['type' => 'text'], 'version' => 'v1', 'made_at' => 1763150000123],
            'greeting' => ['fragment' => ['type' => 'column'], 'version' => 'v2', 'chrome' => []],
        ],
        'refresh' => [['component' => 'app.inbox']],
    ]);
});

it('sends several regions and hands back one part per region', function () {
    $body = renderBody(['regions' => ['stats', 'chart']]);

    $this->server->plaintext = json_encode(['protocol' => 1, 'delta' => (object) [], 'fragments' => [
        'stats' => ['version' => 'v1', 'fragment' => ['type' => 'text']],
        'chart' => ['version' => 'not a token!', 'fragment' => []],
    ]]);

    expect($this->client->render($body))->toBe(['ok' => true, 'status' => 200, 'delta' => [], 'fragments' => [
        'stats' => ['fragment' => ['type' => 'text'], 'version' => 'v1'],
        'chart' => ['fragment' => []],
    ]]);

    expect($this->server->requests[0]['body'])->toBe($body)
        ->and($this->server->requests[0]['nonce'])->toBeNull();
});

it('refuses an answer that is not a fragments map', function (string $plaintext) {
    $this->server->plaintext = $plaintext;

    expect($this->client->render(renderBody()))->toBe(['ok' => false, 'status' => 200, 'error' => 'bad response']);
})->with([
    'the old single answer' => ['{"protocol":1,"region":"stats","fragment":{"type":"text"}}'],
    'the old cached answer' => ['{"protocol":1,"cached":true}'],
    'a part without a fragment' => ['{"protocol":1,"delta":{},"fragments":{"stats":{"version":"v1"}}}'],
    'chrome that is not an object' => ['{"protocol":1,"delta":{},"fragments":{"stats":{"fragment":{},"chrome":"x"}}}'],
    'no delta' => ['{"protocol":1,"fragments":{"stats":{"fragment":{}}}}'],
    'a delta that is a list' => ['{"protocol":1,"delta":[1],"fragments":{"stats":{"fragment":{}}}}'],
    'a list' => ['{"protocol":1,"fragments":[{"fragment":{}}]}'],
    'an empty map' => ['{"protocol":1,"fragments":{}}'],
    'a bare fragment for a part' => ['{"protocol":1,"fragments":{"stats":{"type":"text"}}}'],
    'a hint that is a string' => ['{"protocol":1,"fragments":{"stats":{"fragment":{}}},"refresh":["app.inbox"]}'],
    'chrome that is a list' => ['{"protocol":1,"delta":{},"fragments":{"stats":{"fragment":{},"chrome":["a"]}}}'],
    'wrong protocol version' => ['{"protocol":2,"delta":{},"fragments":{"stats":{"fragment":{}}}}'],
    'missing protocol version' => ['{"delta":{},"fragments":{"stats":{"fragment":{}}}}'],
]);

it('sends empty state as a JSON object on render, call and upload', function () {
    $file = tempnam(sys_get_temp_dir(), 'midgard-empty-state-');
    file_put_contents($file, 'abc');

    try {
        expect($this->client->render(renderBody(['state' => []])))->toMatchArray(['ok' => true])
            ->and($this->client->callMethod(['protocol' => 1, 'component' => 'app.home', 'method' => 'roll', 'args' => [], 'state' => []]))->toMatchArray(['ok' => true])
            ->and($this->client->upload([
                'protocol' => 1, 'component' => 'app.home', 'method' => 'save', 'args' => [], 'state' => [],
                'filename' => 'a.txt', 'mime' => 'text/plain', 'size' => 3,
            ], $file))->toMatchArray(['ok' => true]);

        expect($this->server->requests[0]['raw'])->toContain('"state":{}')
            ->and($this->server->requests[1]['raw'])->toContain('"state":{}')
            ->and($this->server->requests[2]['raw'])->toContain('"state":{}');
    } finally {
        @unlink($file);
    }
});

it('never retries a 419 whose signature failed verification', function () {
    $config = $this->config + ['elements' => []];
    $client = AsgardClient::make($config, $this->server->client());
    $client->render(renderBody());

    $attempts = 0;
    $mock = new Client(['handler' => HandlerStack::create(function () use (&$attempts) {
        $attempts++;

        return new FulfilledPromise(new Response(
            419,
            ['Signature' => 'asgard=:'.base64_encode(str_repeat('x', 32)).':', 'Signature-Input' => 'asgard=()'],
            'tampered',
        ));
    }), 'http_errors' => false]);

    $tampered = AsgardClient::make($config, $mock);
    $result = $tampered->render(renderBody());

    expect($result['ok'])->toBeFalse()
        ->and($result['status'])->toBe(419)
        ->and($result['error'])->toContain('response failed verification')
        ->and($attempts)->toBe(1)
        ->and($this->server->handshakes)->toBe(1);
});

it('refuses a cached part when Asgard-Cache: invalidate is present', function () {
    $this->server->cacheHeader = 'invalidate';
    $this->server->plaintext = '{"protocol":1,"delta":{},"fragments":{"stats":{"cached":true}}}';

    expect($this->client->render(renderBody()))->toBe(['ok' => false, 'status' => 200, 'error' => 'bad response']);
});

it('hands back a too_many_regions refusal with its status and error', function () {
    $this->server->plaintext = null;
    $this->server->tooManyRegions = true;

    expect($this->client->render(renderBody(['regions' => ['a', 'b']])))
        ->toBe(['ok' => false, 'status' => 422, 'error' => 'too_many_regions']);
});

it('shakes hands again when the app can render element types its session did not list', function () {
    $server = new FakeAsgardServer;
    $config = ['base' => 'https://asgard.test/_asgard', 'key' => 'test-key', 'store' => null];

    AsgardClient::make($config + ['elements' => ['list_item']], $server->client())->render(renderBody());
    AsgardClient::make($config + ['elements' => ['list_item']], $server->client())->render(renderBody());
    AsgardClient::make($config + ['elements' => ['lazy_stack', 'list_item']], $server->client())->render(renderBody());

    expect($server->handshakes)->toBe(2)
        ->and($server->handshakeBodies[1]['elements'])->toBe(['lazy_stack', 'list_item']);
});
