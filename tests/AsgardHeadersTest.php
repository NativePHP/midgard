<?php

use Native\Api\Client\Asgard\AsgardClient;
use Native\Api\Client\Asgard\HttpFragmentSource;
use Native\Api\Client\Asgard\HttpServerTransport;
use Native\Api\Client\Asgard\HttpUploadTransport;
use Native\Api\Client\Asgard\RegionRequest;
use Native\Api\Client\Asgard\RegionStreams;
use Native\Api\Client\Asgard\ServerRegion;
use Native\Api\Client\Asgard\ServerRequest;
use Native\Api\Client\Asgard\Stream;
use Native\Api\Client\Asgard\UploadRequest;
use Native\Api\Client\Tests\Fixtures\FakeAsgardServer;

/**
 * `midgard.asgard.headers`: plain HTTP headers on every Asgard request,
 * outside the signature and the body encryption.
 */
beforeEach(function () {
    config()->set('cache.default', 'array');
    $this->config = ['base' => 'https://asgard.test/_asgard', 'key' => 'test-key', 'timeout' => 5, 'store' => null];
    $this->server = new FakeAsgardServer;
    $this->version = '1.4.0';
    config(['midgard.asgard.headers' => [
        'X-App-Version' => fn () => $this->version,
        'Accept-Language' => 'cy',
    ]]);
});

afterEach(fn () => Stream::unfake());

it('sends the headers on the handshake and on a render, and the request still verifies', function () {
    $client = AsgardClient::make($this->config, $this->server->client());

    $result = $client->render(['protocol' => 1, 'component' => 'promos.show', 'region' => 'screen', 'state' => []]);

    expect($result['ok'])->toBeTrue()
        ->and($this->server->handshakes)->toBe(1)
        ->and($this->server->headers)->each->toMatchArray(['x-app-version' => '1.4.0', 'accept-language' => 'cy']);
});

it('reads the config at each request when it was not handed resolved headers', function () {
    $client = AsgardClient::make($this->config, $this->server->client());

    $client->render(['protocol' => 1, 'component' => 'promos.show', 'region' => 'screen', 'state' => []]);
    $this->version = '1.5.0';
    $client->render(['protocol' => 1, 'component' => 'promos.show', 'region' => 'screen', 'state' => []]);

    expect(end($this->server->headers)['x-app-version'])->toBe('1.5.0');
});

it('uses the headers it was made with, as a lane does', function () {
    $client = AsgardClient::make($this->config + ['headers' => ['X-App-Version' => '0.9']], $this->server->client());

    $client->callMethod(HttpServerTransport::wireBody(new ServerRequest('app.home', 'roll', [], [])));

    expect(end($this->server->headers)['x-app-version'])->toBe('0.9')
        ->and(end($this->server->headers))->not->toHaveKey('accept-language');
});

it('hands the lane the headers as they are when the request is built', function () {
    holdLanes();
    (new HttpServerTransport($this->config))->send(new ServerRequest('app.home', 'roll', [], []));
    $call = taskPayload(lastTaskId());

    $this->version = '2.0.0';
    (new HttpFragmentSource($this->config))->request(new RegionRequest('region-1', 'app.home', 'stats', []));
    $render = taskPayload(lastTaskId());

    $file = tempnam(sys_get_temp_dir(), 'midgard-headers-');
    file_put_contents($file, 'x');
    (new HttpUploadTransport($this->config))->upload(new UploadRequest('app.home', 'save', [], [], $file, 'a.txt', 'text/plain', 1));
    $upload = taskPayload(lastTaskId());
    @unlink($file);

    expect($call)->toContain('X-App-Version')->toContain('1.4.0')
        ->and($render)->toContain('X-App-Version')->toContain('2.0.0')
        ->and($upload)->toContain('Accept-Language')->toContain('2.0.0');
});

it('sends the headers on the push stream, and the stream still verifies', function () {
    $stream = Stream::fake();
    $client = AsgardClient::make($this->config, $this->server->client());
    $client->handshake();
    $streams = new RegionStreams($client, fn () => 1, fn () => 1);

    $streams->watch([['id' => 'home', 'component' => 'app.home', 'regions' => ['stats'], 'state' => ['platform' => 'ios']]]);

    $open = $stream->lastOpen(RegionStreams::ID);
    $opened = $this->server->openStream($open['url'], array_change_key_case($open['headers']), $open['body']);

    expect($open['headers'])->toMatchArray(['X-App-Version' => '1.4.0', 'Accept-Language' => 'cy'])
        ->and($opened['body'])->toBe([
            'protocol' => 1,
            'subscriptions' => [['id' => 'home', 'component' => 'app.home', 'regions' => ['stats'], 'state' => ['platform' => 'ios']]],
        ]);
});

it('works out a callable and leaves a header out when it gives null', function () {
    config(['midgard.asgard.headers' => [
        'X-Platform' => [ServerRegion::class, 'platform'],
        'X-Build' => 42,
        'X-Nothing' => fn () => null,
    ]]);

    expect(AsgardClient::configuredHeaders())->toBe(['X-Platform' => ServerRegion::platform(), 'X-Build' => '42']);
});

it('refuses a header the transport or the protocol already uses', function (string $name) {
    config(['midgard.asgard.headers' => [$name => 'x']]);

    AsgardClient::configuredHeaders();
})->with([
    'Accept', 'Authorization', 'Connection', 'Content-Type', 'Content-Encoding', 'Content-Digest',
    'Content-Length', 'Cookie', 'Host', 'Last-Event-ID', 'Signature', 'Signature-Input',
    'Transfer-Encoding', 'X-Asgard-Method', 'X-HTTP-Method-Override', 'X-HTTP-Method',
    'X-Method-Override', 'Asgard-Protocol', 'asgard-session-id', 'X-Asgard-Anything',
])->throws(InvalidArgumentException::class, 'Asgard\'s transport sets it, signs it or reads it itself');

it('refuses a bad header', function (array $headers, string $message) {
    config(['midgard.asgard.headers' => $headers]);

    expect(fn () => AsgardClient::configuredHeaders())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a name with a space' => [['X App' => 'x'], 'is not a header name'],
    'a name with a colon' => [['X-App:' => 'x'], 'is not a header name'],
    'a line break' => [['X-App' => "a\r\nX-Evil: 1"], 'has a line break in it'],
    'an array' => [['X-App' => ['a', 'b']], 'must be a string'],
    'a closure giving an object' => [['X-App' => fn () => new stdClass], 'must be a string'],
]);

it('sends nothing extra by default', function () {
    config(['midgard.asgard.headers' => (require __DIR__.'/../config/midgard.php')['asgard']['headers']]);

    expect(AsgardClient::configuredHeaders())->toBe([]);
});
