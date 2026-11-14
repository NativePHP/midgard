<?php

use Native\Api\Client\Asgard\AsgardClient;
use Native\Api\Client\Asgard\Events\StreamClosed;
use Native\Api\Client\Asgard\Events\StreamMessage;
use Native\Api\Client\Asgard\Events\StreamOpened;
use Native\Api\Client\Asgard\RegionStreams;
use Native\Api\Client\Asgard\Security\Wire;
use Native\Api\Client\Asgard\Stream;
use Native\Api\Client\Tests\Fixtures\FakeAsgardServer;

const ASGARD = RegionStreams::ID;

beforeEach(function () {
    config()->set('cache.default', 'array');
    $this->stream = Stream::fake();
    $this->server = new FakeAsgardServer;
    $this->client = AsgardClient::make(
        ['base' => 'https://demo.test/_asgard', 'key' => 'test-key', 'timeout' => 5, 'store' => null],
        $this->server->client(),
    );
    $this->client->handshake();
    $this->pushed = [];
    $this->chromes = [];
    $this->signals = [];
    $this->streams = new RegionStreams(
        $this->client,
        function ($component, $state, $region, $fragment, $chrome, $version) {
            $this->pushed[] = [$component, $state, $region, $fragment, $version];
            $this->chromes[] = $chrome;

            return 1;
        },
        function ($component, $state, $region, $version) {
            $this->signals[] = [$component, $state, $region, $version];

            return 1;
        },
    );

    // What the server reads from the last open, and an event sealed the way it would seal one.
    $this->opened = fn () => $this->server->openStream(
        $this->stream->lastOpen(ASGARD)['url'],
        array_change_key_case($this->stream->lastOpen(ASGARD)['headers']),
        $this->stream->lastOpen(ASGARD)['body'],
    );
    $this->event = function (array $payload, string $id = '3', ?string $signature = null, bool $compress = false, string $event = 'fragment') {
        $open = ($this->opened)();

        return new StreamMessage(ASGARD, $event, Wire::sealEvent(
            $this->server->sessions[$open['session']], $open['session'], $signature ?? $open['signature'], $id, $event, json_encode($payload), null, $compress,
        ), $id);
    };
});

afterEach(fn () => Stream::unfake());

function sub(string $id = 'home', array $regions = ['stats'], array $state = ['name' => 'Simon'], array $versions = [], string $component = 'app.native.screens.home'): array
{
    return compact('id', 'component', 'regions', 'state', 'versions');
}

function fragmentEvent(string $region = 'stats', array $extra = [], string $subscription = 'home'): array
{
    return ['protocol' => 1, 'subscription' => $subscription, 'region' => $region, 'version' => 'v2', 'fragment' => ['type' => 'text'], ...$extra];
}

it('opens one signed, encrypted stream carrying every subscription', function () {
    $this->streams->watch([sub(versions: ['stats' => 'v1']), sub('promo', ['screen'], ['id' => '1'], [], 'promos.show')]);

    $open = $this->stream->lastOpen(ASGARD);

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
            'subscriptions' => [
                ['id' => 'home', 'component' => 'app.native.screens.home', 'regions' => ['stats'], 'state' => ['name' => 'Simon'], 'versions' => ['stats' => 'v1']],
                ['id' => 'promo', 'component' => 'promos.show', 'regions' => ['screen'], 'state' => ['id' => '1']],
            ],
        ]);
    expect($this->stream->opens)->toHaveCount(1);
});

it('sends empty state as a JSON object', function () {
    $this->streams->watch([sub(state: [])]);

    expect(($this->opened)()['raw'])->toContain('"state":{}');
});

it('does not reopen for an unchanged set, even when only versions moved', function () {
    $this->streams->watch([sub(versions: ['stats' => 'v1'])]);
    $this->streams->watch([sub(versions: ['stats' => 'v1'])]);
    $this->streams->watch([sub(versions: ['stats' => 'v9'])]);

    expect($this->stream->opens)->toHaveCount(1);
});

it('reopens when the set changes', function () {
    $this->streams->watch([sub()]);
    $this->streams->watch([sub(), sub('other', state: ['name' => 'Ada'])]);
    $this->streams->watch([sub('other', state: ['name' => 'Ada'])]);

    expect($this->stream->opens)->toHaveCount(3)
        ->and(array_column(($this->opened)()['body']['subscriptions'], 'id'))->toBe(['other']);
});

it('closes the stream for an empty set, and opens it again for a new one', function () {
    $this->streams->watch([sub()]);
    $this->streams->watch([]);

    $this->stream->assertClosed(ASGARD);
    expect($this->streams->subscriptions())->toBe([]);

    $this->streams->watch([]);
    expect($this->stream->closes)->toHaveCount(1);

    $this->streams->watch([sub()]);
    expect($this->stream->opens)->toHaveCount(2);
});

it('does not open a stream for an empty first set', function () {
    $this->streams->watch([]);

    $this->stream->assertNothingOpened();
});

it('hands a signal to the signaller with its subscription\'s address and state', function () {
    $this->streams->watch([sub(), sub('ada', state: ['name' => 'Ada'])]);

    $this->streams->receive(($this->event)(['protocol' => 1, 'subscription' => 'ada', 'region' => 'stats', 'version' => 'v2'], event: 'signal'));

    expect($this->signals)->toBe([['app.native.screens.home', ['name' => 'Ada'], 'stats', 'v2']])
        ->and($this->pushed)->toBe([]);
});

it('hands a shared region\'s fragment to the pusher, and keeps its version for a reconnect', function () {
    $this->streams->watch([sub(versions: ['stats' => 'v1'])]);

    $this->streams->receive(($this->event)(fragmentEvent()));

    expect($this->pushed)->toBe([['app.native.screens.home', ['name' => 'Simon'], 'stats', ['type' => 'text'], 'v2']]);

    $this->streams->closed(new StreamClosed(ASGARD, 'timed out', reconnect: true));

    expect(($this->opened)()['body']['subscriptions'][0]['versions'])->toBe(['stats' => 'v2']);
});

it('does not take a signal\'s version as held, since the region is not fetched yet', function () {
    $this->streams->watch([sub(versions: ['stats' => 'v1'])]);

    $this->streams->receive(($this->event)(['protocol' => 1, 'subscription' => 'home', 'region' => 'stats', 'version' => 'v2'], event: 'signal'));
    $this->streams->closed(new StreamClosed(ASGARD, 'timed out', reconnect: true));

    expect(($this->opened)()['body']['subscriptions'][0]['versions'])->toBe(['stats' => 'v1']);
});

it('hands a server screen\'s chrome to the pusher with its fragment', function () {
    $this->streams->watch([sub(regions: ['screen'])]);

    $this->streams->receive(($this->event)(fragmentEvent('screen', ['chrome' => ['nav_bar' => ['title' => 'P']]])));
    $this->streams->receive(($this->event)(fragmentEvent('screen', ['chrome' => 'nope'])));

    expect($this->pushed)->toHaveCount(2)
        ->and($this->chromes)->toBe([['nav_bar' => ['title' => 'P']], null]);
});

it('unzips a compressed event before painting it', function () {
    $this->streams->watch([sub()]);
    $rows = array_map(fn ($i) => ['type' => 'text', 'props' => ['text' => "Row {$i}"]], range(1, 200));
    $event = ($this->event)(fragmentEvent('stats', ['fragment' => ['type' => 'column', 'children' => $rows]]), '3', null, true);

    expect(json_decode($event->data, true)['enc'])->toBe('gzip');

    $this->streams->receive($event);

    expect($this->pushed[0][3])->toBe(['type' => 'column', 'children' => $rows]);
});

it('drops an event whose coding marker was flipped or stripped', function () {
    $this->streams->watch([sub()]);
    $rows = array_map(fn ($i) => ['type' => 'text', 'props' => ['text' => "Row {$i}"]], range(1, 200));
    $event = ($this->event)(fragmentEvent('stats', ['fragment' => $rows]), '3', null, true);
    $data = json_decode($event->data, true);

    $this->streams->receive(new StreamMessage(ASGARD, 'fragment', json_encode(['body' => $data['body'], 'enc' => 'identity', 'sig' => $data['sig']]), '3'));
    $this->streams->receive(new StreamMessage(ASGARD, 'fragment', json_encode(['body' => $data['body'], 'sig' => $data['sig']]), '3'));

    expect($this->pushed)->toBe([]);
});

it('drops a compressed event that inflates past the cap', function () {
    $this->streams->watch([sub()]);
    $open = ($this->opened)();
    $bomb = Wire::sealEvent($this->server->sessions[$open['session']], $open['session'], $open['signature'], '3', 'fragment', str_repeat(' ', 9 * 1024 * 1024), null, true);

    $this->streams->receive(new StreamMessage(ASGARD, 'fragment', $bomb, '3'));

    expect($this->pushed)->toBe([]);
});

it('drops an event that does not verify, and never acts on it', function (Closure $mangle) {
    $this->streams->watch([sub()]);
    $event = ($this->event)(fragmentEvent());

    $this->streams->receive($mangle($event));

    expect($this->pushed)->toBe([])->and($this->signals)->toBe([]);
})->with([
    'plaintext, as before' => [fn ($e) => new StreamMessage($e->id, 'fragment', json_encode(fragmentEvent()), '3')],
    'another id' => [fn ($e) => new StreamMessage($e->id, 'fragment', $e->data, '4')],
    'no id' => [fn ($e) => new StreamMessage($e->id, 'fragment', $e->data, null)],
    'renamed to a signal' => [fn ($e) => new StreamMessage($e->id, 'signal', $e->data, '3')],
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
    $this->streams->watch([sub()]);

    $this->streams->receive(($this->event)(fragmentEvent(), '3', str_repeat("\1", 32)));

    expect($this->pushed)->toBe([]);
});

it('drops events from before a reopen, because they were bound to the old request', function () {
    $this->streams->watch([sub()]);
    $old = ($this->event)(fragmentEvent());

    $this->streams->closed(new StreamClosed(ASGARD, 'timed out', reconnect: true));
    $this->streams->receive($old);

    expect($this->pushed)->toBe([]);
});

it('drops events for a subscription or region the stream does not carry', function (array $payload, string $event) {
    $this->streams->watch([sub()]);

    $this->streams->receive(($this->event)($payload, event: $event));

    expect($this->pushed)->toBe([])->and($this->signals)->toBe([]);
})->with([
    'unknown subscription' => [fragmentEvent(subscription: 'nope'), 'fragment'],
    'no subscription' => [array_diff_key(fragmentEvent(), ['subscription' => 1]), 'fragment'],
    'region not in it' => [fragmentEvent('greeting'), 'fragment'],
    'signal for an unknown subscription' => [['protocol' => 1, 'subscription' => 'nope', 'region' => 'stats', 'version' => 'v2'], 'signal'],
    'signal for a region not in it' => [['protocol' => 1, 'subscription' => 'home', 'region' => 'greeting', 'version' => 'v2'], 'signal'],
    'signal without a version' => [['protocol' => 1, 'subscription' => 'home', 'region' => 'stats'], 'signal'],
    'fragment without a fragment' => [array_diff_key(fragmentEvent(), ['fragment' => 1]), 'fragment'],
]);

it('ignores other events and other streams', function () {
    $this->streams->watch([sub()]);

    $this->streams->receive(new StreamMessage(ASGARD, 'message', '{}', '3'));
    $this->streams->receive(new StreamMessage('asgard:app.other', 'fragment', '{}', '3'));
    $this->streams->receive(new StreamMessage('someone-else', 'fragment', '{}', '3'));

    expect($this->pushed)->toBe([]);
});

it('drops events once the stream is closed', function () {
    $this->streams->watch([sub()]);
    $event = ($this->event)(fragmentEvent());

    $this->streams->watch([]);
    $this->streams->receive($event);

    expect($this->pushed)->toBe([]);
});

it('reopens with a fresh signature when the native side asks to reconnect', function () {
    $this->streams->watch([sub()]);
    $first = $this->stream->lastOpen(ASGARD)['headers']['signature'];

    $this->streams->closed(new StreamClosed(ASGARD, 'HTTP 503', reconnect: true));

    expect($this->stream->opens)->toHaveCount(2)
        ->and($this->stream->lastOpen(ASGARD)['headers']['signature'])->not->toBe($first);

    $this->streams->receive(($this->event)(fragmentEvent()));
    expect($this->pushed)->toHaveCount(1);
});

it('handshakes again once on a 419, and gives up on a second one', function () {
    $this->streams->watch([sub()]);
    $this->server->forgetSessions();

    $this->streams->closed(new StreamClosed(ASGARD, 'HTTP 419', false, 419));

    expect($this->server->handshakes)->toBe(2)
        ->and($this->stream->opens)->toHaveCount(2)
        ->and(($this->opened)()['session'])->toBe(array_key_first($this->server->sessions));

    $this->streams->closed(new StreamClosed(ASGARD, 'HTTP 419', false, 419));

    expect($this->server->handshakes)->toBe(2)
        ->and($this->streams->subscriptions())->toBe([]);
});

it('allows another 419 retry once the stream has opened again', function () {
    $this->streams->watch([sub()]);
    $this->streams->closed(new StreamClosed(ASGARD, 'HTTP 419', false, 419));
    $this->streams->opened(new StreamOpened(ASGARD));
    $this->streams->closed(new StreamClosed(ASGARD, 'HTTP 419', false, 419));

    expect($this->server->handshakes)->toBe(3)
        ->and($this->stream->opens)->toHaveCount(3);
});

it('stops on a 403 or any other final close, until the device hands over a set again', function () {
    $this->streams->watch([sub()]);

    $this->streams->closed(new StreamClosed(ASGARD, 'HTTP 403', false, 403));

    expect($this->streams->subscriptions())->toBe([])
        ->and($this->stream->opens)->toHaveCount(1);

    $this->streams->watch([sub()]);
    expect($this->stream->opens)->toHaveCount(2);
});

it('closes the stream', function () {
    $this->streams->watch([sub()]);
    $this->streams->close();

    $this->stream->assertClosed(ASGARD);
    expect($this->streams->subscriptions())->toBe([]);
});
