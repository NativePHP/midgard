<?php

use Illuminate\Support\Facades\Event;
use Native\Api\Client\Asgard\AsgardServiceProvider;
use Native\Api\Client\Asgard\Events\StreamClosed;
use Native\Api\Client\Asgard\Events\StreamMessage;
use Native\Api\Client\Asgard\Events\StreamOpened;
use Native\Api\Client\Asgard\Stream;
use Native\Mobile\Events\Contracts\DispatchableFromNative;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function () {
    $GLOBALS['nativephp_test_calls'] = [];
    $GLOBALS['nativephp_test_raw'] = [];
    $GLOBALS['nativephp_test_response'] = null;
});

afterEach(function () {
    Stream::unfake();
});

// The real bridge path

it('opens a GET stream through the bridge', function () {
    Stream::open('regions', 'https://api.test/stream', ['Authorization' => 'Bearer abc']);

    expect($GLOBALS['nativephp_test_calls'])->toHaveCount(1);

    $call = $GLOBALS['nativephp_test_calls'][0];

    expect($call['method'])->toBe('Asgard.Stream.Open')
        ->and($call['params'])->toBe([
            'id' => 'regions',
            'url' => 'https://api.test/stream',
            'method' => 'GET',
            'headers' => ['Authorization' => 'Bearer abc'],
            'body' => null,
        ]);
});

it('opens a POST stream with a JSON body', function () {
    Stream::open('regions', 'https://api.test/stream', ['X-Team' => '7'], ['regions' => ['home'], 'n' => 1]);

    $params = $GLOBALS['nativephp_test_calls'][0]['params'];

    expect($params['method'])->toBe('POST')
        ->and($params['body'])->toBe('{"regions":["home"],"n":1}')
        ->and($params['headers'])->toBe(['X-Team' => '7', 'Content-Type' => 'application/json']);
});

it('keeps a content type the caller set, whatever its case', function () {
    Stream::open('a', 'https://api.test/s', ['content-type' => 'application/vnd.api+json'], ['x' => 1]);

    expect($GLOBALS['nativephp_test_calls'][0]['params']['headers'])
        ->toBe(['content-type' => 'application/vnd.api+json']);
});

it('sends no content type for a GET', function () {
    Stream::open('a', 'https://api.test/s');

    expect($GLOBALS['nativephp_test_calls'][0]['params']['headers'])->toBe([]);
});

it('sends empty headers as a JSON object', function () {
    Stream::open('a', 'https://api.test/s');

    expect($GLOBALS['nativephp_test_raw'][0])->toContain('"headers":{}');
});

it('treats a POST with an empty body array as a POST', function () {
    Stream::open('a', 'https://api.test/s', [], []);

    $params = $GLOBALS['nativephp_test_calls'][0]['params'];

    expect($params['method'])->toBe('POST')
        ->and($params['body'])->toBe('[]');
});

it('closes a stream through the bridge', function () {
    Stream::close('regions');

    expect($GLOBALS['nativephp_test_calls'])->toBe([
        ['method' => 'Asgard.Stream.Close', 'params' => ['id' => 'regions']],
    ]);
});

it('throws when the native side reports an error', function () {
    $GLOBALS['nativephp_test_response'] = json_encode([
        'status' => 'error',
        'code' => 'INVALID_PARAMETERS',
        'message' => 'url must be an http or https URL',
    ]);

    Stream::open('a', 'ftp://nope');
})->throws(RuntimeException::class, 'url must be an http or https URL');

it('ignores a successful native answer', function () {
    $GLOBALS['nativephp_test_response'] = json_encode(['id' => 'a']);

    Stream::open('a', 'https://api.test/s');

    expect($GLOBALS['nativephp_test_calls'])->toHaveCount(1);
});

// The events

it('has native events that carry the stream details', function () {
    $message = new StreamMessage('regions', 'tick', '{"n":1}', '12');
    $opened = new StreamOpened('regions');
    $closed = new StreamClosed('regions', 'HTTP 503', true);

    expect($message)->toBeInstanceOf(DispatchableFromNative::class)
        ->and($message->id)->toBe('regions')
        ->and($message->event)->toBe('tick')
        ->and($message->data)->toBe('{"n":1}')
        ->and($message->lastEventId)->toBe('12')
        ->and($opened)->toBeInstanceOf(DispatchableFromNative::class)
        ->and($opened->id)->toBe('regions')
        ->and($closed)->toBeInstanceOf(DispatchableFromNative::class)
        ->and($closed->error)->toBe('HTTP 503')
        ->and($closed->willReconnect)->toBeTrue();
});

it('builds the events from a native payload the way the framework does', function () {
    // The native side sends named keys, and the framework unpacks them into the constructor.
    $message = new StreamMessage(...['id' => 'a', 'event' => 'message', 'data' => 'x', 'lastEventId' => null]);
    $closed = new StreamClosed(...['id' => 'a', 'error' => null, 'willReconnect' => false]);

    expect($message->lastEventId)->toBeNull()
        ->and($closed->error)->toBeNull()
        ->and($closed->willReconnect)->toBeFalse();
});

it('lists every event in the plugin manifest', function () {
    $manifest = json_decode(file_get_contents(__DIR__.'/../nativephp.json'), true);

    expect($manifest['events'])->toBe([
        StreamMessage::class,
        StreamOpened::class,
        StreamClosed::class,
    ]);
});

it('declares both bridge functions for both platforms', function () {
    $manifest = json_decode(file_get_contents(__DIR__.'/../nativephp.json'), true);
    $functions = collect($manifest['bridge_functions'])->keyBy('name');

    expect($functions->keys()->all())->toBe(['Asgard.Stream.Open', 'Asgard.Stream.Close'])
        ->and($functions['Asgard.Stream.Open']['android'])->toBe('com.nativephp.plugins.asgard.AsgardStreamFunctions.Open')
        ->and($functions['Asgard.Stream.Open']['ios'])->toBe('AsgardStreamFunctions.Open')
        ->and($functions['Asgard.Stream.Close']['android'])->toBe('com.nativephp.plugins.asgard.AsgardStreamFunctions.Close')
        ->and($functions['Asgard.Stream.Close']['ios'])->toBe('AsgardStreamFunctions.Close');
});

it('puts the asgard provider first so it is the one a plugin registration matches', function () {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['type'])->toBe('nativephp-plugin')
        ->and($composer['extra']['laravel']['providers'][0])->toBe(AsgardServiceProvider::class);
});

// The fake

it('records opens and closes without touching the bridge', function () {
    $fake = Stream::fake();

    Stream::open('regions', 'https://api.test/stream', ['A' => 'b'], ['x' => 1]);
    Stream::close('other');

    expect($GLOBALS['nativephp_test_calls'])->toBe([])
        ->and($fake->opens)->toHaveCount(1)
        ->and($fake->opens[0])->toBe([
            'id' => 'regions',
            'url' => 'https://api.test/stream',
            'method' => 'POST',
            'headers' => ['A' => 'b', 'Content-Type' => 'application/json'],
            'body' => '{"x":1}',
        ])
        ->and($fake->closes)->toBe(['other']);
});

it('tracks which streams are open', function () {
    $fake = Stream::fake();

    Stream::open('a', 'https://api.test/s');
    Stream::open('b', 'https://api.test/s');
    Stream::close('a');

    expect($fake->isOpen('a'))->toBeFalse()
        ->and($fake->isOpen('b'))->toBeTrue()
        ->and($fake->isOpen('c'))->toBeFalse();
});

it('returns the latest open for an id', function () {
    $fake = Stream::fake();

    Stream::open('a', 'https://api.test/one');
    Stream::open('a', 'https://api.test/two');

    expect($fake->lastOpen('a')['url'])->toBe('https://api.test/two')
        ->and($fake->lastOpen('missing'))->toBeNull();
});

it('lets a test emit events to listeners', function () {
    $fake = Stream::fake();
    $seen = [];

    Event::listen(StreamOpened::class, function (StreamOpened $e) use (&$seen) {
        $seen[] = ['opened', $e->id];
    });
    Event::listen(StreamMessage::class, function (StreamMessage $e) use (&$seen) {
        $seen[] = ['message', $e->id, $e->event, $e->data, $e->lastEventId];
    });
    Event::listen(StreamClosed::class, function (StreamClosed $e) use (&$seen) {
        $seen[] = ['closed', $e->id, $e->error, $e->willReconnect];
    });

    Stream::open('regions', 'https://api.test/stream');

    $fake->emitOpened('regions')
        ->emit('regions', '{"n":1}')
        ->emit('regions', 'two', 'tick', '9')
        ->emitClosed('regions', 'HTTP 503', willReconnect: true)
        ->emitClosed('regions');

    expect($seen)->toBe([
        ['opened', 'regions'],
        ['message', 'regions', 'message', '{"n":1}', null],
        ['message', 'regions', 'tick', 'two', '9'],
        ['closed', 'regions', 'HTTP 503', true],
        ['closed', 'regions', null, false],
    ]);
});

it('treats a final close as the end of the stream, and a reconnecting one as not', function () {
    $fake = Stream::fake();

    Stream::open('a', 'https://api.test/s');
    $fake->emitClosed('a', 'dropped', willReconnect: true);

    expect($fake->isOpen('a'))->toBeTrue();

    $fake->emitClosed('a', 'HTTP 404');

    expect($fake->isOpen('a'))->toBeFalse();
});

it('asserts on opens and closes', function () {
    $fake = Stream::fake();

    $fake->assertNothingOpened()->assertNotOpened('a')->assertNotClosed('a');

    Stream::open('a', 'https://api.test/s', [], ['q' => 1]);
    Stream::close('a');

    $fake->assertOpened('a')
        ->assertOpened('a', fn (array $open) => $open['method'] === 'POST')
        ->assertClosed('a');
});

it('fails an assertion that does not hold', function () {
    $fake = Stream::fake();

    Stream::open('a', 'https://api.test/s');

    expect(fn () => $fake->assertOpened('a', fn (array $open) => $open['method'] === 'POST'))
        ->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertOpened('b'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertClosed('a'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertNothingOpened())->toThrow(AssertionFailedError::class);
});

it('goes back to the real bridge after unfake', function () {
    Stream::fake();
    Stream::unfake();

    Stream::close('a');

    expect($GLOBALS['nativephp_test_calls'])->toHaveCount(1);
});
