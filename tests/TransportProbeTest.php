<?php

use Illuminate\Support\Facades\Cache;
use Native\Api\Client\Asgard\AsgardClient;
use Native\Api\Client\Asgard\RegionStreams;
use Native\Api\Client\Asgard\Stream;
use Native\Api\Client\Asgard\TransportMemory;
use Native\Api\Client\Tests\Fixtures\FakeAsgardServer;

/**
 * The QUERY probe (protocol §8): the handshake is a plain POST, the first
 * request after it goes as real QUERY, and the client falls back to POST
 * plus `X-Asgard-Method: QUERY` when QUERY does not get an Asgard answer.
 * The form that worked is remembered for the installation.
 */
beforeEach(function () {
    config()->set('cache.default', 'array');
    $this->server = new FakeAsgardServer;
    $this->config = ['base' => 'https://asgard.test/_asgard', 'key' => 'test-key', 'timeout' => 5, 'store' => null, 'elements' => []];
    $this->client = AsgardClient::make($this->config, $this->server->client());
});

afterEach(fn () => Stream::unfake());

function probeBody(): array
{
    return ['protocol' => 1, 'component' => 'promos.show', 'regions' => ['screen'], 'state' => []];
}

it('handshakes with a plain POST, then sends real QUERY and remembers it', function () {
    expect($this->client->render(probeBody()))->toMatchArray(['ok' => true])
        ->and($this->client->render(probeBody()))->toMatchArray(['ok' => true]);

    expect($this->server->methods)->toBe(['POST', 'QUERY', 'QUERY'])
        ->and(array_column($this->server->requests, 'override'))->toBe([null, null])
        ->and($this->client->transport())->toBe(TransportMemory::QUERY);
});

it('falls back to POST plus the header, signed afresh, and remembers that', function (int $status) {
    $this->server->blockQuery = $status;

    expect($this->client->render(probeBody()))->toMatchArray(['ok' => true, 'fragments' => ['screen' => ['fragment' => $this->server->fragment]]]);

    // The fallback verified on the server, so its signature covers POST and the header.
    expect($this->server->methods)->toBe(['POST', 'QUERY', 'POST'])
        ->and($this->server->requests)->toHaveCount(1)
        ->and($this->server->requests[0])->toMatchArray(['method' => 'POST', 'override' => 'QUERY'])
        ->and($this->client->transport())->toBe(TransportMemory::POST);

    // Every later request goes straight to the POST form.
    $this->client->render(probeBody());
    expect(array_slice($this->server->methods, 3))->toBe(['POST']);
})->with([
    'a 405' => [405],
    'a 501' => [501],
    "a firewall's 403 page" => [403],
    'a 200 that is not signed' => [200],
]);

it('falls back when the QUERY answer fails verification', function () {
    $tampered = false;
    $this->server->tamper = function (array $sealed) use (&$tampered) {
        if ($tampered) {
            return $sealed;
        }
        $tampered = true;
        $sealed['headers']['signature'] = 'asgard=:'.base64_encode(str_repeat('x', 32)).':';

        return $sealed;
    };

    expect($this->client->render(probeBody()))->toMatchArray(['ok' => true])
        ->and($this->client->transport())->toBe(TransportMemory::POST);
});

it("takes the server's own unsigned early rejection as an Asgard answer", function (int $status) {
    $this->server->refuseWith = $status;

    $result = $this->client->render(probeBody());

    expect($result)->toMatchArray(['ok' => false, 'status' => $status, 'error' => 'unsigned response'])
        ->and($this->server->methods)->toBe(['POST', 'QUERY'])
        ->and($this->client->transport())->toBe(TransportMemory::QUERY);
})->with([403, 413, 415, 422]);

it('falls back on an unsigned 415 or 422 that does not carry an Asgard early rejection body', function (int $status) {
    $this->server->blockQuery = $status;

    expect($this->client->render(probeBody()))->toMatchArray(['ok' => true])
        ->and($this->server->methods)->toBe(['POST', 'QUERY', 'POST'])
        ->and($this->client->transport())->toBe(TransportMemory::POST);
})->with([415, 422]);

it('requires protocols to be a list on an unsigned 422 early rejection', function () {
    expect(AsgardClient::isEarlyRejection(422, '{"error":"unsupported_protocol","protocols":[1]}'))->toBeTrue()
        ->and(AsgardClient::isEarlyRejection(422, '{"error":"unsupported_protocol"}'))->toBeFalse()
        ->and(AsgardClient::isEarlyRejection(422, '{"error":"unsupported_protocol","protocols":{"a":1}}'))->toBeFalse()
        ->and(AsgardClient::isEarlyRejection(415, '{"error":"unsupported_coding"}'))->toBeTrue()
        ->and(AsgardClient::isEarlyRejection(415, '{"error":"unsupported_type"}'))->toBeFalse();
});

it('keeps the session when a firewall returns a 419 page during the QUERY probe', function () {
    $this->server->blockQuery = 419;

    expect($this->client->render(probeBody()))->toMatchArray(['ok' => true])
        ->and($this->server->methods)->toBe(['POST', 'QUERY', 'POST'])
        ->and($this->client->transport())->toBe(TransportMemory::POST)
        ->and($this->client->currentSession())->not->toBeNull();

    $this->client->render(probeBody());
    expect($this->server->handshakes)->toBe(1);
});

it('takes a sealed error as an Asgard answer', function () {
    $this->server->plaintext = json_encode(['protocol' => 1, 'error' => 'unknown_component']);
    $this->server->tamper = null;

    $result = $this->client->render(probeBody());

    expect($result['ok'])->toBeFalse()
        ->and($this->client->transport())->toBe(TransportMemory::QUERY)
        ->and($this->server->methods)->toBe(['POST', 'QUERY']);
});

it('remembers QUERY on a 419 and retries it after a new handshake', function () {
    $this->client->handshake();
    $this->server->forgetSessions();

    expect($this->client->render(probeBody()))->toMatchArray(['ok' => true])
        ->and($this->server->methods)->toBe(['POST', 'QUERY', 'POST', 'QUERY'])
        ->and($this->server->handshakes)->toBe(2)
        ->and($this->client->transport())->toBe(TransportMemory::QUERY);
});

it('remembers nothing and does not fall back on a network failure', function () {
    $this->server->blockQuery = 'network';

    expect($this->client->render(probeBody()))->toMatchArray(['ok' => false, 'status' => 0])
        ->and($this->server->methods)->toBe(['POST', 'QUERY'])
        ->and($this->client->transport())->toBeNull();

    // The next request tries QUERY again.
    $this->server->blockQuery = null;
    expect($this->client->render(probeBody()))->toMatchArray(['ok' => true])
        ->and(array_slice($this->server->methods, 2))->toBe(['QUERY'])
        ->and($this->client->transport())->toBe(TransportMemory::QUERY);
});

it('remembers nothing when the POST form fails too, and fails with its reason', function () {
    $this->server->blockQuery = 405;
    $this->server->blockPost = 502;

    expect($this->client->render(probeBody()))->toMatchArray(['ok' => false, 'status' => 502])
        ->and($this->server->methods)->toBe(['POST', 'QUERY', 'POST'])
        ->and($this->client->transport())->toBeNull();
});

it('remembers nothing when the POST form drops the connection', function () {
    $this->server->blockQuery = 501;
    $this->server->blockPost = 'network';

    expect($this->client->render(probeBody()))->toMatchArray(['ok' => false, 'status' => 0])
        ->and($this->client->transport())->toBeNull();
});

it('never probes with a request that changes the server', function () {
    $this->server->blockQuery = 405;

    $this->client->callMethod(['protocol' => 1, 'component' => 'dice', 'method' => 'roll', 'args' => [], 'state' => []]);
    $this->client->render([...probeBody(), 'action' => ['call' => 'like', 'args' => []]]);

    // Straight to POST plus the header, sent once each, and nothing learned.
    expect($this->server->methods)->toBe(['POST', 'POST', 'POST'])
        ->and($this->server->requests[0]['nonce'])->toMatch('/^[A-Za-z0-9_-]{22}$/')
        ->and($this->client->transport())->toBeNull();

    // The next safe request is the one that probes.
    $this->client->render(probeBody());
    expect(array_slice($this->server->methods, 3))->toBe(['QUERY', 'POST'])
        ->and($this->client->transport())->toBe(TransportMemory::POST);
});

it('probes from the route list too, whichever request comes first', function () {
    $this->server->blockQuery = 405;

    expect($this->client->routes())->toMatchArray(['ok' => true])
        ->and($this->client->transport())->toBe(TransportMemory::POST);
});

it('keeps the form for the installation, across clients and lanes', function () {
    $this->server->blockQuery = 405;
    $this->client->render(probeBody());

    $other = AsgardClient::make($this->config, $this->server->client());
    $other->render(probeBody());

    expect($other->transport())->toBe(TransportMemory::POST)
        ->and(array_slice($this->server->methods, 3))->toBe(['POST']);
});

it('keeps one form per base URL', function () {
    $this->server->blockQuery = 405;
    $this->client->render(probeBody());

    $elsewhere = AsgardClient::make(['base' => 'https://other.test/_asgard'] + $this->config, $this->server->client());

    expect($elsewhere->transport())->toBeNull();
});

it('seals the stream in the remembered form', function (?int $block, string $method) {
    $this->server->blockQuery = $block;
    $this->client->render(probeBody());

    $sealed = $this->client->sealStream($this->client->currentSession(), '{"protocol":1}');

    expect($sealed['method'])->toBe($method)
        ->and(isset($sealed['headers']['x-asgard-method']))->toBe($method === 'POST');

    // The server can open it with that method: the signature covers it.
    expect($this->server->openStream('https://asgard.test/_asgard/stream', $sealed['headers'], $sealed['body'], $method)['raw'])
        ->toBe('{"protocol":1}');
})->with([
    'QUERY worked' => [null, 'QUERY'],
    'QUERY was blocked' => [405, 'POST'],
]);

it('seals the stream as POST plus the header while the form is unknown', function () {
    $session = $this->client->handshake();

    $sealed = $this->client->sealStream($session, '{"protocol":1}');

    expect($sealed['method'])->toBe('POST')
        ->and($sealed['headers']['x-asgard-method'])->toBe('QUERY')
        ->and($this->client->transport())->toBeNull();
});

it('opens the native stream with the remembered method', function () {
    $fake = Stream::fake();
    $this->client->render(probeBody());

    $streams = new RegionStreams($this->client, fn () => 1, fn () => 1);
    $streams->watch([['id' => 'a', 'component' => 'promos.show', 'regions' => ['screen'], 'state' => []]]);

    $open = $fake->lastOpen(RegionStreams::ID);

    expect($open['method'])->toBe('QUERY')
        ->and($open['headers'])->not->toHaveKey('x-asgard-method')
        ->and($this->server->openStream($open['url'], $open['headers'], $open['body'], 'QUERY')['body']['subscriptions'][0]['id'])->toBe('a');
});

it('carries Asgard-Session-Id, under the signature, on every request after the handshake', function () {
    $this->client->render(probeBody());
    $this->client->routes();
    $this->client->callMethod(['protocol' => 1, 'component' => 'dice', 'method' => 'roll', 'args' => [], 'state' => []]);
    $sealed = $this->client->sealStream($this->client->currentSession(), '{}');

    $session = $this->client->currentSession()->id;

    expect(array_column($this->server->requests, 'session'))->toBe([$session, $session, $session])
        ->and($sealed['headers']['asgard-session-id'])->toBe($session)
        ->and($sealed['headers']['signature-input'])->toContain('"asgard-session-id"');
});

it('uses one store for the form, the one sessions use', function () {
    $this->server->blockQuery = 405;
    $this->client->render(probeBody());

    expect(Cache::store()->get(TransportMemory::keyFor('https://asgard.test/_asgard')))->toBe('POST');
});
