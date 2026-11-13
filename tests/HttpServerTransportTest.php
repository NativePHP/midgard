<?php

use Native\Api\Client\Asgard\AsgardClient;
use Native\Api\Client\Asgard\AsgardServiceProvider;
use Native\Api\Client\Asgard\HttpServerTransport;
use Native\Api\Client\Tests\Fixtures\FakeAsgardServer;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\Asgard\Contracts\ServerTransport;
use Native\Mobile\Edge\Asgard\ServerMethods;
use Native\Mobile\Edge\Asgard\ServerRequest;

require_once __DIR__.'/Fixtures/AsgardRegionStandIns.php';
require_once __DIR__.'/Fixtures/AsgardServerMethodStandIns.php';

// #[Server] method calls (ASGARD-SERVER-METHODS.md §3, protocol §10.5).
beforeEach(function () {
    // Only the stand-ins record. With nativephp/mobile installed, skip.
    if (! property_exists(ServerMethods::class, 'delivered')) {
        $this->markTestSkipped('nativephp/mobile is installed, so the stand-ins are not in use.');
    }

    config()->set('cache.default', 'array');
    ServerMethods::$delivered = [];
    AsyncTask::$dispatched = [];
    AsyncTask::$available = true;

    $this->config = ['url' => 'https://asgard.test', 'path' => '_asgard', 'key' => 'test-key', 'timeout' => 5, 'store' => null];
    $this->server = new FakeAsgardServer;
    $this->client = AsgardClient::make($this->config, $this->server->client());
});

function serverRequest(array $state = ['rolling' => true]): ServerRequest
{
    return new ServerRequest('app.native.screens.home', 'roll', [], $state, 'ios');
}

// ── The client call ─────────────────────────────────

it('sends a sealed call with a nonce and hands back the delta', function () {
    $body = HttpServerTransport::wireBody(serverRequest());

    expect($this->client->callMethod($body))->toBe(['ok' => true, 'status' => 200, 'delta' => ['roll' => 4]]);

    expect($this->server->requests[0]['path'])->toBe('/_asgard/call')
        ->and($this->server->requests[0]['nonce'])->toMatch('/^[A-Za-z0-9_-]{22}$/')
        ->and($this->server->requests[0]['body'])->toBe([
            'protocol' => 1,
            'component' => 'app.native.screens.home',
            'method' => 'roll',
            'args' => [],
            'state' => ['rolling' => true],
            'platform' => 'ios',
        ]);
});

it('takes an empty delta', function () {
    $this->server->delta = [];

    expect($this->client->callMethod(HttpServerTransport::wireBody(serverRequest())))
        ->toBe(['ok' => true, 'status' => 200, 'delta' => []]);
});

it('passes fragments and hints through', function () {
    $this->server->plaintext = json_encode([
        'protocol' => 1, 'delta' => ['a' => 1],
        'fragments' => ['stats' => ['version' => 'v1', 'fragment' => ['type' => 'text']]],
        'refresh' => [['component' => 'app.inbox', 'region' => 'unread'], ['component' => 'app.feed']],
    ]);

    expect($this->client->callMethod(HttpServerTransport::wireBody(serverRequest())))->toBe([
        'ok' => true, 'status' => 200, 'delta' => ['a' => 1],
        'fragments' => ['stats' => ['fragment' => ['type' => 'text'], 'version' => 'v1']],
        'refresh' => [['component' => 'app.inbox', 'region' => 'unread'], ['component' => 'app.feed']],
    ]);
});

it('refuses an answer that is not a call answer', function (string $plaintext) {
    $this->server->plaintext = $plaintext;

    expect($this->client->callMethod(HttpServerTransport::wireBody(serverRequest())))
        ->toBe(['ok' => false, 'status' => 200, 'error' => 'bad response']);
})->with([
    'no delta' => ['{"protocol":1,"fragment":{"type":"text"}}'],
    'a list delta' => ['{"protocol":1,"delta":[1,2]}'],
    'a string delta' => ['{"protocol":1,"delta":"x"}'],
    'fragments as bare trees' => ['{"protocol":1,"delta":{},"fragments":{"stats":{"type":"text"}}}'],
    'fragments as a list' => ['{"protocol":1,"delta":{},"fragments":[{"fragment":1}]}'],
    'empty fragments' => ['{"protocol":1,"delta":{},"fragments":{}}'],
    'a hint without a component' => ['{"protocol":1,"delta":{},"refresh":[{"region":"a"}]}'],
    'a hint with a number region' => ['{"protocol":1,"delta":{},"refresh":[{"component":"a","region":1}]}'],
    'a hint with another member' => ['{"protocol":1,"delta":{},"refresh":[{"component":"a","state":{}}]}'],
    'refresh as an object' => ['{"protocol":1,"delta":{},"refresh":{"component":"a"}}'],
]);

it('handshakes again and retries once when the server lost the session', function () {
    $this->client->callMethod(HttpServerTransport::wireBody(serverRequest()));
    $this->server->forgetSessions();

    expect($this->client->callMethod(HttpServerTransport::wireBody(serverRequest())))->toMatchArray(['ok' => true])
        ->and($this->server->handshakes)->toBe(2);

    // Each attempt signs its own nonce, so the retry is not a replay.
    $nonces = array_filter(array_column($this->server->requests, 'nonce'));
    expect(array_unique($nonces))->toHaveCount(count($nonces));
});

it('reports a refused call with its status', function () {
    $this->server->refuseWith = 503;

    expect($this->client->callMethod(HttpServerTransport::wireBody(serverRequest())))
        ->toBe(['ok' => false, 'status' => 503, 'error' => 'unsigned response']);
});

// ── The wire body ───────────────────────────────────

it('turns the request into plain JSON data', function () {
    $body = HttpServerTransport::wireBody(new ServerRequest('app.home', 'save', [1.0, 'x'], ['box' => (object) ['a' => 1], 'empty' => []], 'android'));

    expect($body)->toBe([
        'protocol' => 1,
        'component' => 'app.home',
        'method' => 'save',
        'args' => [1.0, 'x'],
        'state' => ['box' => ['a' => 1], 'empty' => []],
        'platform' => 'android',
    ]);
});

it('throws on state JSON cannot carry, so the send fails on the UI thread', function () {
    HttpServerTransport::wireBody(serverRequest(['bad' => "\xB1\x31"]));
})->throws(JsonException::class);

// ── Sending ─────────────────────────────────────────

it('starts the call on a lane taken on any screen and returns its id', function () {
    $id = (new HttpServerTransport($this->config))->send(serverRequest());

    expect($id)->toMatch('/^call-[0-9a-f]{16}$/')
        ->and(AsyncTask::$dispatched)->toHaveCount(1)
        ->and(AsyncTask::$dispatched[0]->started)->toBeTrue()
        ->and(AsyncTask::$dispatched[0]->anyScreen)->toBeTrue()
        ->and(ServerMethods::$delivered)->toBe([]);

    expect((new HttpServerTransport($this->config))->send(serverRequest()))->not->toBe($id);
});

it('delivers the answer under the id it returned', function () {
    $id = (new HttpServerTransport($this->config))->send(serverRequest());

    (AsyncTask::$dispatched[0]->finished)(['ok' => true, 'status' => 200, 'delta' => ['roll' => 4]]);

    expect(ServerMethods::$delivered)->toBe([['id' => $id, 'ok' => true, 'delta' => ['roll' => 4], 'fragments' => [], 'refresh' => []]]);
});

it('hands the regions and hints a call answer carries to deliver', function () {
    $fragments = ['stats' => ['fragment' => ['type' => 'text'], 'version' => 'v1']];
    $refresh = [['component' => 'app.inbox', 'region' => 'unread']];

    HttpServerTransport::answered('call-1', ['ok' => true, 'status' => 200, 'delta' => [], 'fragments' => $fragments, 'refresh' => $refresh], 'https://a.test/_asgard/call', 'roll');

    expect(ServerMethods::$delivered)->toBe([['id' => 'call-1', 'ok' => true, 'delta' => [], 'fragments' => $fragments, 'refresh' => $refresh]]);
});

it('reports a lane that cannot start through deliver, before send returns', function () {
    AsyncTask::$available = false;

    $id = (new HttpServerTransport($this->config))->send(serverRequest());

    expect(ServerMethods::$delivered)->toBe([['id' => $id, 'ok' => false, 'error' => 'The background lane is not available.', 'code' => null]]);
});

// ── Failures ────────────────────────────────────────

it('maps a failed answer to a code the device reads as a reason', function (mixed $result, ?int $code) {
    HttpServerTransport::answered('call-1', $result, 'https://a.test/_asgard/call', 'roll');

    expect(ServerMethods::$delivered)->toHaveCount(1)
        ->and(ServerMethods::$delivered[0]['id'])->toBe('call-1')
        ->and(ServerMethods::$delivered[0]['ok'])->toBeFalse()
        ->and(ServerMethods::$delivered[0]['code'])->toBe($code);
})->with([
    'no response' => [['ok' => false, 'status' => 0, 'error' => 'cURL error 28'], null],
    'a handshake that got nothing' => [['ok' => false, 'status' => 0, 'error' => 'Handshake failed: timeout'], null],
    'a server error' => [['ok' => false, 'status' => 500, 'error' => 'invalid_delta'], 500],
    'a 419 after its retry' => [['ok' => false, 'status' => 419, 'error' => 'session_expired'], 419],
    'a signed 404' => [['ok' => false, 'status' => 404, 'error' => 'unknown_method'], 404],
    'failed verification' => [['ok' => false, 'status' => 200, 'error' => 'response failed verification: bad signature'], 200],
    'not a call answer' => [['ok' => false, 'status' => 200, 'error' => 'bad response'], 200],
    'not an array at all' => [null, null],
]);

it('reports a call that never came back as a network failure', function () {
    HttpServerTransport::lost('call-2', new RuntimeException('The async task did not complete within 60 seconds.'), 'roll');

    expect(ServerMethods::$delivered)->toBe([['id' => 'call-2', 'ok' => false, 'error' => 'The async task did not complete within 60 seconds.', 'code' => null]]);
});

// ── Binding ─────────────────────────────────────────

it('is bound as the #[Server] transport when an Asgard server is configured', function () {
    config()->set('midgard.asgard.url', 'https://asgard.test');
    config()->set('midgard.asgard.key', 'test-key');

    (new AsgardServiceProvider(app()))->register();

    expect(app(ServerTransport::class))->toBeInstanceOf(HttpServerTransport::class);
});

it('defaults the HTTP timeout above the device timeouts', function () {
    config()->set('midgard.asgard.url', 'https://asgard.test');
    config()->set('midgard.asgard.key', 'test-key');

    expect(AsgardServiceProvider::clientConfig()['timeout'])->toBeGreaterThan(15.0);
});
