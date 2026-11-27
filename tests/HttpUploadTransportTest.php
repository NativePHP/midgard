<?php

use Illuminate\Support\Facades\Event;
use Native\Api\Client\Asgard\AsgardClient;
use Native\Api\Client\Asgard\AsgardServiceProvider;
use Native\Api\Client\Asgard\Contracts\UploadTransport;
use Native\Api\Client\Asgard\Events\ServerActionFailed;
use Native\Api\Client\Asgard\HttpUploadTransport;
use Native\Api\Client\Asgard\Testing\Asgard;
use Native\Api\Client\Asgard\UploadRequest;
use Native\Api\Client\Tests\Fixtures\CallRecorderScreen;
use Native\Api\Client\Tests\Fixtures\FakeAsgardServer;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Support\AsyncTaskRegistry;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

// Uploads to #[Upload] methods (protocol §10.6).
beforeEach(function () {
    config()->set('cache.default', 'array');
    app('view')->addLocation(__DIR__.'/Fixtures/views');

    $this->failures = [];
    Event::listen(ServerActionFailed::class, fn (ServerActionFailed $event) => $this->failures[] = $event);

    $this->config = ['base' => 'https://asgard.test/_asgard', 'key' => 'test-key', 'timeout' => 5, 'upload_timeout' => 90, 'store' => null];
    $this->server = new FakeAsgardServer;
    $this->client = AsgardClient::make($this->config, $this->server->client());

    $this->file = tempnam(sys_get_temp_dir(), 'midgard-upload-');
    $this->bytes = "\xFF\xD8\xFF\xE0".random_bytes(9000)."\x00\x00";
    file_put_contents($this->file, $this->bytes);
});

afterEach(function () {
    @unlink($this->file);
});

function uploadRequest(string $path, int $size, array $state = ['name' => 'Simon']): UploadRequest
{
    return new UploadRequest('app.native.screens.home', 'savePhoto', ['Sunset'], $state, $path, 'IMG_0001.jpg', 'image/jpeg', $size);
}

// ── The client call ─────────────────────────────────

it('sends a sealed frame with a nonce and hands back the delta', function () {
    $header = HttpUploadTransport::header(uploadRequest($this->file, strlen($this->bytes)));

    expect($this->client->upload($header, $this->file))->toBe(['ok' => true, 'status' => 200, 'delta' => ['roll' => 4]]);

    $request = $this->server->requests[0];

    expect($request['path'])->toBe('/_asgard/upload')
        ->and($request['nonce'])->toMatch('/^[A-Za-z0-9_-]{22}$/')
        ->and($request['bytes'])->toBe($this->bytes)
        ->and($request['body'])->toBe([
            'protocol' => 1,
            'component' => 'app.native.screens.home',
            'method' => 'savePhoto',
            'args' => ['Sunset'],
            'state' => ['name' => 'Simon'],
            'filename' => 'IMG_0001.jpg',
            'mime' => 'image/jpeg',
            'size' => strlen($this->bytes),
        ]);
});

it('frames the header length big-endian ahead of the JSON header and the bytes', function () {
    $frame = AsgardClient::frame(['protocol' => 1, 'size' => 3], "\x00\x01\x02");

    expect($frame)->toBe("\x00\x00\x00\x17".'{"protocol":1,"size":3}'."\x00\x01\x02");
});

it('uses the upload timeout for the upload, not the call timeout', function () {
    $this->client->upload(HttpUploadTransport::header(uploadRequest($this->file, strlen($this->bytes))), $this->file);

    // The handshake keeps the client's own default (none is set on the
    // test client). The upload sets its own.
    expect($this->server->timeouts)->toBe([null, 90.0]);
});

it('passes fragments and hints through, like a call', function () {
    $this->server->plaintext = json_encode([
        'protocol' => 1, 'delta' => ['photo' => 'a.jpg'],
        'fragments' => ['media' => ['version' => 'v1', 'fragment' => ['type' => 'image']]],
        'refresh' => [['component' => 'app.feed']],
    ]);

    expect($this->client->upload(HttpUploadTransport::header(uploadRequest($this->file, strlen($this->bytes))), $this->file))->toBe([
        'ok' => true, 'status' => 200, 'delta' => ['photo' => 'a.jpg'],
        'fragments' => ['media' => ['fragment' => ['type' => 'image'], 'version' => 'v1']],
        'refresh' => [['component' => 'app.feed']],
    ]);
});

it('refuses an answer that is not a call answer', function () {
    $this->server->plaintext = '{"protocol":1,"delta":[1]}';

    expect($this->client->upload(HttpUploadTransport::header(uploadRequest($this->file, strlen($this->bytes))), $this->file))
        ->toBe(['ok' => false, 'status' => 200, 'error' => 'bad response']);
});

it('reports a refused upload with its status', function () {
    $this->server->refuseWith = 413;

    expect($this->client->upload(HttpUploadTransport::header(uploadRequest($this->file, strlen($this->bytes))), $this->file))
        ->toBe(['ok' => false, 'status' => 413, 'error' => 'unsigned response']);
});

it('sends nothing when the file is gone or changed since the device checked it', function (Closure $change) {
    $change($this);

    $result = $this->client->upload(HttpUploadTransport::header(uploadRequest($this->file, strlen($this->bytes))), $this->file);

    expect($result['ok'])->toBeFalse()
        ->and($result['status'])->toBe(0)
        ->and($this->server->requests)->toBe([]);
})->with([
    'deleted' => [fn ($t) => unlink($t->file)],
    'grown' => [fn ($t) => file_put_contents($t->file, 'more', FILE_APPEND)],
]);

it('handshakes again and retries once when the server lost the session', function () {
    $header = HttpUploadTransport::header(uploadRequest($this->file, strlen($this->bytes)));
    $this->client->upload($header, $this->file);
    $this->server->forgetSessions();

    expect($this->client->upload($header, $this->file))->toMatchArray(['ok' => true])
        ->and($this->server->handshakes)->toBe(2);
});

// ── Sending ─────────────────────────────────────────

/**
 * A screen with an upload in flight through HttpUploadTransport, its lane
 * held until the test finishes it.
 *
 * @return array{0: TestableComponent, 1: string, 2: string} the screen, the upload's id and its task's id
 */
function uploadThroughHttp(array $config, string $file, bool $laneAvailable = true): array
{
    holdLanes($laneAvailable);
    Asgard::fakeFragmentSource();
    app()->instance(UploadTransport::class, new HttpUploadTransport($config));

    $screen = Native::test(CallRecorderScreen::class);
    $id = $screen->instance()->uploadToServer('savePhoto', $file);

    return [$screen, (string) $id, $laneAvailable ? lastTaskId() : ''];
}

it('starts the upload on a lane, past the HTTP timeout, and returns its id', function () {
    holdLanes();
    $previous = NativeComponent::markActive(new CallRecorderScreen);

    try {
        $id = (new HttpUploadTransport($this->config))->upload(uploadRequest($this->file, strlen($this->bytes)));
    } finally {
        NativeComponent::restoreActive($previous);
    }

    $task = lastTaskId();

    expect($id)->toMatch('/^upload-[0-9a-f]{16}$/')
        ->and(heldTasks())->toHaveCount(1)
        ->and(heldTasks()[0]['timeout'])->toBe(120)
        ->and(AsyncTaskRegistry::origin($task))->toBeNull()
        ->and(AsyncTaskRegistry::scope($task))->not->toBeNull();
});

it('ships only the path to the lane, never the bytes', function () {
    holdLanes();

    (new HttpUploadTransport($this->config))->upload(uploadRequest($this->file, strlen($this->bytes)));

    $code = taskPayload(lastTaskId());

    expect($code)->toContain('RenderCall::upload($config, $header, $path)')
        ->not->toContain('->finished(')
        ->and(strlen($code))->toBeLessThan(strlen($this->bytes));
});

it('delivers the answer under the id it returned, like a call', function () {
    [$screen, $id, $task] = uploadThroughHttp($this->config, $this->file);

    expect($id)->toMatch('/^upload-[0-9a-f]{16}$/');

    finishTask($task, ['ok' => true, 'status' => 200, 'delta' => ['photo' => 'a.jpg']]);

    expect($screen->get('photo'))->toBe('a.jpg')
        ->and($this->failures)->toBe([]);
});

it('maps failures to codes the device reads as reasons', function (array $result, ?int $status, string $reason) {
    [$screen, $id, $task] = uploadThroughHttp($this->config, $this->file);

    finishTask($task, $result);

    // The failure fires on the screen's next turn.
    $screen->wait();

    expect($this->failures)->toHaveCount(1)
        ->and($this->failures[0]->requestId)->toBe($id)
        ->and($this->failures[0]->action)->toBe('savePhoto')
        ->and($this->failures[0]->status)->toBe($status)
        ->and($this->failures[0]->reason)->toBe($reason);
})->with([
    'too large' => [['ok' => false, 'status' => 413, 'error' => 'too_large'], 413, 'server'],
    'wrong type' => [['ok' => false, 'status' => 415, 'error' => 'unsupported_type'], 415, 'server'],
    'failed verification' => [['ok' => false, 'status' => 200, 'error' => 'response failed verification: bad signature'], 200, 'invalid'],
    'bad response' => [['ok' => false, 'status' => 200, 'error' => 'bad response'], 200, 'invalid'],
    'no response' => [['ok' => false, 'status' => 0, 'error' => 'cURL error 28'], null, 'network'],
    'file changed' => [['ok' => false, 'status' => 0, 'error' => 'The file changed after it was checked.'], null, 'network'],
]);

it('reports a lane that died as a network failure', function () {
    [$screen, $id, $task] = uploadThroughHttp($this->config, $this->file);

    failTask($task, new RuntimeException('The async task did not complete within 180 seconds.'));
    $screen->wait();

    expect($this->failures)->toHaveCount(1)
        ->and($this->failures[0]->requestId)->toBe($id)
        ->and($this->failures[0]->reason)->toBe('network')
        ->and($this->failures[0]->message)->toBe('The async task did not complete within 180 seconds.');
});

it('reports a lane that cannot start through deliver, before upload returns', function () {
    [$screen, $id] = uploadThroughHttp($this->config, $this->file, laneAvailable: false);
    $screen->wait();

    expect($this->failures)->toHaveCount(1)
        ->and($this->failures[0]->requestId)->toBe($id)
        ->and($this->failures[0]->reason)->toBe('network')
        ->and($this->failures[0]->message)->toContain('the background lane is not available');
});

it('throws on state JSON cannot carry, so the send fails on the UI thread', function () {
    HttpUploadTransport::header(uploadRequest($this->file, 1, ['bad' => "\xB1\x31"]));
})->throws(JsonException::class);

// ── Binding ─────────────────────────────────────────

it('is bound as the upload transport when an Asgard server is configured', function () {
    config()->set('midgard.asgard.base', 'https://asgard.test/_asgard');
    config()->set('midgard.asgard.key', 'test-key');

    (new AsgardServiceProvider(app()))->register();

    expect(app(UploadTransport::class))->toBeInstanceOf(HttpUploadTransport::class);
});

it('defaults the upload timeout above the device upload timeout', function () {
    config()->set('midgard.asgard.base', 'https://asgard.test/_asgard');
    config()->set('midgard.asgard.key', 'test-key');

    expect(AsgardServiceProvider::clientConfig()['upload_timeout'])->toBe(150.0)
        ->and(AsgardServiceProvider::clientConfig()['upload_timeout'])->toBeGreaterThan((float) config('midgard.asgard.upload_timeout'));
});
