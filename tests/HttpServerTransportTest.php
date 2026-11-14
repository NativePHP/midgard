<?php

use Native\Api\Client\Asgard\AsgardClient;
use Native\Api\Client\Asgard\AsgardServiceProvider;
use Native\Api\Client\Asgard\Contracts\ServerTransport;
use Native\Api\Client\Asgard\HttpServerTransport;
use Native\Api\Client\Asgard\ServerRequest;
use Native\Api\Client\Asgard\ServerResponse;
use Native\Api\Client\Asgard\Testing\Asgard;
use Native\Api\Client\Tests\Fixtures\CallRecorderScreen;
use Native\Api\Client\Tests\Fixtures\FakeAsgardServer;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Support\AsyncTaskRegistry;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

// #[Server] method calls (ASGARD-SERVER-METHODS.md §3, protocol §10.5).
beforeEach(function () {
    config()->set('cache.default', 'array');
    app('view')->addLocation(__DIR__.'/Fixtures/views');
    CallRecorderScreen::$responses = [];

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

/**
 * A screen with a #[Server] call in flight through HttpServerTransport,
 * its lane held until the test finishes it.
 *
 * @return array{0: TestableComponent, 1: string}
 */
function callThroughHttp(array $config, bool $laneAvailable = true): array
{
    holdLanes($laneAvailable);
    Asgard::fakeFragmentSource();
    app()->instance(ServerTransport::class, new HttpServerTransport($config));

    $screen = Native::test(CallRecorderScreen::class)->call('roll');

    return [$screen, lastTaskId()];
}

/**
 * A screen with a #[Server] call in flight, the fake transport holding it.
 *
 * @return array{0: TestableComponent, 1: string}
 */
function callInFlight(): array
{
    $server = Asgard::fakeServerTransport();
    Asgard::fakeFragmentSource();

    $screen = Native::test(CallRecorderScreen::class)->call('roll');

    return [$screen, $server->lastId()];
}

/** The one answer the screen's call got. */
function onlyResponse(): ServerResponse
{
    expect(CallRecorderScreen::$responses)->toHaveCount(1);

    return CallRecorderScreen::$responses[0];
}

it('starts the call on a lane taken on any screen and returns its id', function () {
    holdLanes();
    $transport = new HttpServerTransport($this->config);

    // The screen on show when the call goes out. A lane taken on any screen
    // is not tied to it.
    $previous = NativeComponent::markActive(new CallRecorderScreen);

    try {
        $id = $transport->send(serverRequest());
    } finally {
        NativeComponent::restoreActive($previous);
    }

    $task = lastTaskId();

    expect($id)->toMatch('/^call-[0-9a-f]{16}$/')
        ->and(heldTasks())->toHaveCount(1)
        ->and(AsyncTaskRegistry::scope($task))->not->toBeNull()
        ->and(AsyncTaskRegistry::origin($task))->toBeNull()
        ->and(taskPayload($task))->toContain('RenderCall::serverMethod($config, $body)');

    expect($transport->send(serverRequest()))->not->toBe($id);
});

it('delivers the answer under the id it returned', function () {
    [$screen, $task] = callThroughHttp($this->config);

    expect(CallRecorderScreen::$responses)->toBe([]);

    finishTask($task, ['ok' => true, 'status' => 200, 'delta' => ['roll' => 4]]);

    expect(onlyResponse()->ok)->toBeTrue()
        ->and(onlyResponse()->id)->toMatch('/^call-[0-9a-f]{16}$/')
        ->and(onlyResponse()->applied)->toBe(['roll' => 4])
        ->and($screen->get('roll'))->toBe(4);
});

it('hands the regions and hints a call answer carries to deliver', function () {
    $source = Asgard::fakeFragmentSource();
    [$screen, $id] = callInFlight();
    $asked = count($source->requests('stats'));

    $fragments = ['stats' => ['fragment' => ['type' => 'text', 'props' => ['text' => 'Ten sales']], 'version' => 'v1']];
    $refresh = [['component' => 'native.api.client.tests.fixtures.call_recorder_screen', 'region' => 'stats']];

    HttpServerTransport::answered($id, ['ok' => true, 'status' => 200, 'delta' => [], 'fragments' => $fragments, 'refresh' => $refresh], 'https://a.test/_asgard/call', 'roll');

    expect(onlyResponse()->ok)->toBeTrue()
        ->and(onlyResponse()->refusedFragments)->toBe([]);

    // The fragment paints, and the hint fetches the region again once the
    // screen has drawn its next frame.
    $screen->wait()->assertSee('Ten sales');

    expect($source->requests('stats'))->toHaveCount($asked + 1);
});

it('reports a lane that cannot start through deliver, before send returns', function () {
    callThroughHttp($this->config, laneAvailable: false);

    expect(onlyResponse()->ok)->toBeFalse()
        ->and(onlyResponse()->error)->toContain('the background lane is not available')
        ->and(onlyResponse()->code)->toBeNull();
});

// ── Failures ────────────────────────────────────────

it('maps a failed answer to a code the device reads as a reason', function (mixed $result, ?int $code) {
    [, $id] = callInFlight();

    HttpServerTransport::answered($id, $result, 'https://a.test/_asgard/call', 'roll');

    expect(onlyResponse()->id)->toBe($id)
        ->and(onlyResponse()->ok)->toBeFalse()
        ->and(onlyResponse()->code)->toBe($code);
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
    [, $task] = callThroughHttp($this->config);

    failTask($task, new RuntimeException('The async task did not complete within 60 seconds.'));

    expect(onlyResponse()->ok)->toBeFalse()
        ->and(onlyResponse()->error)->toBe('The async task did not complete within 60 seconds.')
        ->and(onlyResponse()->code)->toBeNull();
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

    expect(AsgardServiceProvider::clientConfig()['timeout'])->toBeGreaterThan((float) config('midgard.asgard.action_timeout'));
});
