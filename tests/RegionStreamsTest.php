<?php

use Native\Api\Client\Asgard\AsgardClient;
use Native\Api\Client\Asgard\Events\StreamClosed;
use Native\Api\Client\Asgard\Events\StreamMessage;
use Native\Api\Client\Asgard\Events\StreamOpened;
use Native\Api\Client\Asgard\RegionStreams;
use Native\Api\Client\Asgard\Security\Wire;
use Native\Api\Client\Asgard\Stream;
use Native\Api\Client\Tests\Fixtures\FakeAsgardServer;

const HOME = 'asgard:app.native.screens.home';

beforeEach(function () {
    config()->set('cache.default', 'array');
    $this->stream = Stream::fake();
    $this->server = new FakeAsgardServer;
    $this->client = AsgardClient::make(
        ['url' => 'https://demo.test', 'path' => '_asgard', 'key' => 'test-key', 'timeout' => 5, 'store' => null],
        $this->server->client(),
    );
    $this->client->handshake();
    $this->pushed = [];
    $this->streams = new RegionStreams($this->client, function ($component, $region, $fragment) {
        $this->pushed[] = [$component, $region, $fragment];

        return 1;
    });

    // What the server reads from the last open, and an event sealed the way it would seal one.
    $this->opened = fn () => $this->server->openStream(
        $this->stream->lastOpen(HOME)['url'],
        array_change_key_case($this->stream->lastOpen(HOME)['headers']),
        $this->stream->lastOpen(HOME)['body'],
    );
    $this->event = function (array $payload, string $id = '3', ?string $signature = null, bool $compress = false) {
        $open = ($this->opened)();

        return new StreamMessage(HOME, 'fragment', Wire::sealEvent(
            $this->server->sessions[$open['session']], $open['session'], $signature ?? $open['signature'], $id, 'fragment', json_encode($payload), null, $compress,
        ), $id);
    };
});

afterEach(fn () => Stream::unfake());

function regionRequest(string $region, array $state = ['name' => 'Simon']): object
{
    return (object) ['component' => 'app.native.screens.home', 'region' => $region, 'state' => $state, 'platform' => 'ios'];
}

it('opens one signed, encrypted stream per component carrying every region that asked', function () {
    $this->streams->subscribe(regionRequest('greeting'));
    $this->streams->subscribe(regionRequest('stats'));

    $open = $this->stream->lastOpen(HOME);

    expect($open['url'])->toBe('https://demo.test/_asgard/stream')
        ->and($open['method'])->toBe('POST')
        ->and($open['body'])->not->toContain('Simon')
        ->and($open['headers'])->toMatchArray([
            'content-type' => 'application/json',
            'content-encoding' => 'aes128gcm',
            'x-asgard-method' => 'QUERY',
            'accept' => 'text/event-stream',
        ])
        ->and($open['headers']['signature-input'])->not->toContain('nonce')
        ->and(($this->opened)()['body'])->toBe([
            'protocol' => 1,
            'component' => 'app.native.screens.home',
            'regions' => ['greeting', 'stats'],
            'state' => ['name' => 'Simon'],
            'platform' => 'ios',
        ]);
    expect($this->stream->opens)->toHaveCount(2);
});

it('does not reopen when nothing changed, and does when the state did', function () {
    $this->streams->subscribe(regionRequest('greeting'));
    $this->streams->subscribe(regionRequest('greeting'));
    expect($this->stream->opens)->toHaveCount(1);

    $this->streams->subscribe(regionRequest('greeting', ['name' => 'Ada']));
    expect($this->stream->opens)->toHaveCount(2);
});

it('hands a verified, decrypted fragment for a subscribed region to the pusher', function () {
    $this->streams->subscribe(regionRequest('stats'));

    $this->streams->receive(($this->event)(['protocol' => 1, 'region' => 'stats', 'fragment' => ['type' => 'text']]));

    expect($this->pushed)->toBe([['app.native.screens.home', 'stats', ['type' => 'text']]]);
});

it('unzips a compressed event before painting it', function () {
    $this->streams->subscribe(regionRequest('stats'));
    $rows = array_map(fn ($i) => ['type' => 'text', 'props' => ['text' => "Row {$i}"]], range(1, 200));
    $event = ($this->event)(['protocol' => 1, 'region' => 'stats', 'fragment' => ['type' => 'column', 'children' => $rows]], '3', null, true);

    expect(json_decode($event->data, true)['enc'])->toBe('gzip');

    $this->streams->receive($event);

    expect($this->pushed)->toBe([['app.native.screens.home', 'stats', ['type' => 'column', 'children' => $rows]]]);
});

it('drops an event whose coding marker was flipped or stripped', function () {
    $this->streams->subscribe(regionRequest('stats'));
    $rows = array_map(fn ($i) => ['type' => 'text', 'props' => ['text' => "Row {$i}"]], range(1, 200));
    $event = ($this->event)(['protocol' => 1, 'region' => 'stats', 'fragment' => $rows], '3', null, true);
    $data = json_decode($event->data, true);

    $this->streams->receive(new StreamMessage(HOME, 'fragment', json_encode(['body' => $data['body'], 'enc' => 'identity', 'sig' => $data['sig']]), '3'));
    $this->streams->receive(new StreamMessage(HOME, 'fragment', json_encode(['body' => $data['body'], 'sig' => $data['sig']]), '3'));

    expect($this->pushed)->toBe([]);
});

it('drops a compressed event that inflates past the cap', function () {
    $this->streams->subscribe(regionRequest('stats'));
    $open = ($this->opened)();
    $bomb = Wire::sealEvent($this->server->sessions[$open['session']], $open['session'], $open['signature'], '3', 'fragment', str_repeat(' ', 9 * 1024 * 1024), null, true);

    $this->streams->receive(new StreamMessage(HOME, 'fragment', $bomb, '3'));

    expect($this->pushed)->toBe([]);
});

it('drops an event that does not verify, and never paints it', function (Closure $mangle) {
    $this->streams->subscribe(regionRequest('stats'));
    $event = ($this->event)(['protocol' => 1, 'region' => 'stats', 'fragment' => ['type' => 'text']]);

    $this->streams->receive($mangle($event));

    expect($this->pushed)->toBe([]);
})->with([
    'plaintext, as before' => [fn ($e) => new StreamMessage($e->id, 'fragment', json_encode(['protocol' => 1, 'region' => 'stats', 'fragment' => []]), '3')],
    'another id' => [fn ($e) => new StreamMessage($e->id, 'fragment', $e->data, '4')],
    'no id' => [fn ($e) => new StreamMessage($e->id, 'fragment', $e->data, null)],
    'body changed' => [function ($e) {
        $data = json_decode($e->data, true);
        $data['body'][30] = $data['body'][30] === 'A' ? 'B' : 'A';

        return new StreamMessage($e->id, 'fragment', json_encode($data), '3');
    }],
    'sig changed' => [function ($e) {
        $data = json_decode($e->data, true);
        $data['sig'] = str_repeat('A', 43);

        return new StreamMessage($e->id, 'fragment', json_encode($data), '3');
    }],
]);

it('drops an event sealed for another stream request', function () {
    $this->streams->subscribe(regionRequest('stats'));

    $this->streams->receive(($this->event)(['protocol' => 1, 'region' => 'stats', 'fragment' => []], '3', str_repeat("\1", 32)));

    expect($this->pushed)->toBe([]);
});

it('drops events from before a reopen, because they were bound to the old request', function () {
    $this->streams->subscribe(regionRequest('stats'));
    $old = ($this->event)(['protocol' => 1, 'region' => 'stats', 'fragment' => []]);

    $this->streams->closed(new StreamClosed(HOME, 'timed out', reconnect: true));
    $this->streams->receive($old);

    expect($this->pushed)->toBe([]);
});

it('ignores events that are not fragments for a subscribed region', function () {
    $this->streams->subscribe(regionRequest('stats'));

    $this->streams->receive(($this->event)(['protocol' => 1, 'region' => 'greeting', 'fragment' => []]));
    $this->streams->receive(new StreamMessage(HOME, 'message', '{}', '3'));
    $this->streams->receive(new StreamMessage('asgard:app.other', 'fragment', '{}', '3'));
    $this->streams->receive(new StreamMessage('someone-else', 'fragment', '{}', '3'));

    expect($this->pushed)->toBe([]);
});

it('reopens with a fresh signature when the native side asks to reconnect', function () {
    $this->streams->subscribe(regionRequest('stats'));
    $first = $this->stream->lastOpen(HOME)['headers']['signature'];

    $this->streams->closed(new StreamClosed(HOME, 'HTTP 503', reconnect: true));

    expect($this->stream->opens)->toHaveCount(2)
        ->and($this->stream->lastOpen(HOME)['headers']['signature'])->not->toBe($first);

    $this->streams->receive(($this->event)(['protocol' => 1, 'region' => 'stats', 'fragment' => ['type' => 'text']]));
    expect($this->pushed)->toHaveCount(1);
});

it('handshakes again once on a 419, and gives up on a second one', function () {
    $this->streams->subscribe(regionRequest('stats'));
    $this->server->forgetSessions();

    $this->streams->closed(new StreamClosed(HOME, 'HTTP 419', false, 419));

    expect($this->server->handshakes)->toBe(2)
        ->and($this->stream->opens)->toHaveCount(2)
        ->and(($this->opened)()['session'])->toBe(array_key_first($this->server->sessions));

    $this->streams->closed(new StreamClosed(HOME, 'HTTP 419', false, 419));

    expect($this->server->handshakes)->toBe(2)
        ->and($this->streams->subscriptions())->toBe([]);
});

it('allows another 419 retry once the stream has opened again', function () {
    $this->streams->subscribe(regionRequest('stats'));
    $this->streams->closed(new StreamClosed(HOME, 'HTTP 419', false, 419));
    $this->streams->opened(new StreamOpened(HOME));
    $this->streams->closed(new StreamClosed(HOME, 'HTTP 419', false, 419));

    expect($this->server->handshakes)->toBe(3)
        ->and($this->stream->opens)->toHaveCount(3);
});

it('stops on a 403 or any other final close, until a region asks again', function () {
    $this->streams->subscribe(regionRequest('stats'));

    $this->streams->closed(new StreamClosed(HOME, 'HTTP 403', false, 403));

    expect($this->streams->subscriptions())->toBe([])
        ->and($this->stream->opens)->toHaveCount(1);

    $this->streams->subscribe(regionRequest('stats'));
    expect($this->stream->opens)->toHaveCount(2);
});

it('closes every stream it opened', function () {
    $this->streams->subscribe(regionRequest('stats'));
    $this->streams->close();

    $this->stream->assertClosed(HOME);
    expect($this->streams->subscriptions())->toBe([]);
});
