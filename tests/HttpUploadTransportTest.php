<?php

use Laravel\SerializableClosure\SerializableClosure;
use Native\Api\Client\Asgard\AsgardClient;
use Native\Api\Client\Asgard\AsgardServiceProvider;
use Native\Api\Client\Asgard\HttpUploadTransport;
use Native\Api\Client\Tests\Fixtures\FakeAsgardServer;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\Asgard\Contracts\UploadTransport;
use Native\Mobile\Edge\Asgard\ServerMethods;
use Native\Mobile\Edge\Asgard\UploadRequest;

require_once __DIR__.'/Fixtures/AsgardRegionStandIns.php';
require_once __DIR__.'/Fixtures/AsgardServerMethodStandIns.php';

// Uploads to #[Upload] methods (protocol §10.6).
beforeEach(function () {
    if (! property_exists(ServerMethods::class, 'delivered')) {
        $this->markTestSkipped('nativephp/mobile is installed, so the stand-ins are not in use.');
    }

    config()->set('cache.default', 'array');
    ServerMethods::$delivered = [];
    AsyncTask::$dispatched = [];
    AsyncTask::$available = true;

    $this->config = ['url' => 'https://asgard.test', 'path' => '_asgard', 'key' => 'test-key', 'timeout' => 5, 'upload_timeout' => 90, 'store' => null];
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
    return new UploadRequest('app.native.screens.home', 'savePhoto', ['Sunset'], $state, 'ios', $path, 'IMG_0001.jpg', 'image/jpeg', $size);
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
            'platform' => 'ios',
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

it('starts the upload on a lane, past the HTTP timeout, and returns its id', function () {
    $id = (new HttpUploadTransport($this->config))->upload(uploadRequest($this->file, strlen($this->bytes)));

    expect($id)->toMatch('/^upload-[0-9a-f]{16}$/')
        ->and(AsyncTask::$dispatched)->toHaveCount(1)
        ->and(AsyncTask::$dispatched[0]->started)->toBeTrue()
        ->and(AsyncTask::$dispatched[0]->anyScreen)->toBeTrue()
        ->and(AsyncTask::$dispatched[0]->timeout)->toBe(120)
        ->and(ServerMethods::$delivered)->toBe([]);
});

it('ships only the path to the lane, never the bytes', function () {
    (new HttpUploadTransport($this->config))->upload(uploadRequest($this->file, strlen($this->bytes)));

    $code = serialize(new SerializableClosure(AsyncTask::$dispatched[0]->work));

    expect($code)->toContain('RenderCall::upload($config, $header, $path)')
        ->not->toContain('->finished(')
        ->and(strlen($code))->toBeLessThan(strlen($this->bytes));
});

it('delivers the answer under the id it returned, like a call', function () {
    $id = (new HttpUploadTransport($this->config))->upload(uploadRequest($this->file, strlen($this->bytes)));

    (AsyncTask::$dispatched[0]->finished)(['ok' => true, 'status' => 200, 'delta' => ['photo' => 'a.jpg']]);

    expect(ServerMethods::$delivered)->toBe([['id' => $id, 'ok' => true, 'delta' => ['photo' => 'a.jpg'], 'fragments' => [], 'refresh' => []]]);
});

it('maps failures to codes the device reads as reasons', function (array $result, ?int $code) {
    $id = (new HttpUploadTransport($this->config))->upload(uploadRequest($this->file, strlen($this->bytes)));

    (AsyncTask::$dispatched[0]->finished)($result);

    expect(ServerMethods::$delivered[0])->toMatchArray(['id' => $id, 'ok' => false, 'code' => $code]);
})->with([
    'too large' => [['ok' => false, 'status' => 413, 'error' => 'too_large'], 413],
    'wrong type' => [['ok' => false, 'status' => 415, 'error' => 'unsupported_type'], 415],
    'no response' => [['ok' => false, 'status' => 0, 'error' => 'cURL error 28'], null],
    'file changed' => [['ok' => false, 'status' => 0, 'error' => 'The file changed after it was checked.'], null],
]);

it('reports a lane that died as a network failure', function () {
    $id = (new HttpUploadTransport($this->config))->upload(uploadRequest($this->file, strlen($this->bytes)));

    (AsyncTask::$dispatched[0]->failed)(new RuntimeException('The async task did not complete within 180 seconds.'));

    expect(ServerMethods::$delivered)->toBe([['id' => $id, 'ok' => false, 'error' => 'The async task did not complete within 180 seconds.', 'code' => null]]);
});

it('reports a lane that cannot start through deliver, before upload returns', function () {
    AsyncTask::$available = false;

    $id = (new HttpUploadTransport($this->config))->upload(uploadRequest($this->file, strlen($this->bytes)));

    expect(ServerMethods::$delivered)->toBe([['id' => $id, 'ok' => false, 'error' => 'The background lane is not available.', 'code' => null]]);
});

it('throws on state JSON cannot carry, so the send fails on the UI thread', function () {
    HttpUploadTransport::header(uploadRequest($this->file, 1, ['bad' => "\xB1\x31"]));
})->throws(JsonException::class);

// ── Binding ─────────────────────────────────────────

it('is bound as the upload transport when an Asgard server is configured', function () {
    config()->set('midgard.asgard.url', 'https://asgard.test');
    config()->set('midgard.asgard.key', 'test-key');

    (new AsgardServiceProvider(app()))->register();

    expect(app(UploadTransport::class))->toBeInstanceOf(HttpUploadTransport::class);
});

it('defaults the upload timeout above the device upload timeout', function () {
    config()->set('midgard.asgard.url', 'https://asgard.test');
    config()->set('midgard.asgard.key', 'test-key');

    expect(AsgardServiceProvider::clientConfig()['upload_timeout'])->toBe(150.0)
        ->and(AsgardServiceProvider::clientConfig()['upload_timeout'])->toBeGreaterThan(120.0);
});
