<?php

use Illuminate\Support\Carbon;
use Native\Api\Client\Asgard\ActionRequest;
use Native\Api\Client\Asgard\Attributes\Server;
use Native\Api\Client\Asgard\ComponentState;
use Native\Api\Client\Asgard\HttpFragmentSource;
use Native\Api\Client\Asgard\RegionBatch;
use Native\Api\Client\Asgard\RegionCache;
use Native\Api\Client\Asgard\RegionRequest;
use Native\Api\Client\Asgard\ServerMethods;
use Native\Api\Client\Asgard\ServerRegion;
use Native\Api\Client\Asgard\Testing\Asgard;
use Native\Api\Client\Tests\Asgard\Fixtures\LocalOnlyScreen;
use Native\Api\Client\Tests\Asgard\Fixtures\StateScreen;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

/**
 * Every answer sends state back: a render, a batch, a `{cached: true}` part
 * and an action carry a `delta`, applied before their fragments are painted.
 * `read_only` names the keys the server may not change, and
 * `#[Server(render: ...)]` and `uploadToServer(render: ...)` ask the server
 * to redraw regions after the method.
 *
 * StateScreen draws two regions, `stats` and `feed`.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/Fixtures/views');
    config(['midgard.asgard.state' => [], 'app.debug' => false, 'midgard.asgard.cache.ttl' => 0]);
    ServerRegion::flush();
    ServerMethods::flush();
    Carbon::setTestNow(Carbon::now());

    $this->photo = tempnam(sys_get_temp_dir(), 'state-everywhere-');
    file_put_contents($this->photo, 'x');
});

afterEach(function () {
    ServerRegion::flush();
    ServerMethods::flush();
    Carbon::setTestNow();
    @unlink($this->photo);
});

function stateText(string $text): array
{
    return ['type' => 'text', 'props' => ['text' => $text]];
}

function stateFrame(TestableComponent $screen): TestableComponent
{
    return Closure::bind(function () {
        $this->startInteraction();

        return $this->afterInteraction();
    }, $screen, TestableComponent::class)();
}

/** A StateScreen whose two regions have their first answers. */
function stateScreen(array $delta = []): TestableComponent
{
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(StateScreen::class);

    expect($source->respondToBatch(fragments: [
        'stats' => ['fragment' => stateText('Stats 1'), 'version' => 'v1'],
        'feed' => ['fragment' => [
            'type' => 'pressable',
            'key' => 'go',
            'on_press' => ['call' => 'shuffle', 'args' => [], 'target' => 'server'],
            'children' => [stateText('Shuffle')],
        ]],
    ], delta: $delta))->toBe(2);

    return stateFrame($screen);
}

/** Run a callback with the router's debug log on, and return what it logged. */
function stateDebugLog(Closure $callback): string
{
    $debugLogging = new ReflectionProperty(NativeRouter::class, 'debugLogging');
    $debugLogging->setValue(null, true);
    $log = storage_path('logs/edge-nav.log');
    @mkdir(dirname($log), 0777, true);
    @unlink($log);

    try {
        $callback();
    } finally {
        $debugLogging->setValue(null, null);
    }

    return (string) @file_get_contents($log);
}

// ── read_only ───────────────────────────────────────

it('sends read_only with every request that sends state', function () {
    $source = Asgard::fakeFragmentSource();
    $server = Asgard::fakeServerTransport();
    $screen = Native::test(StateScreen::class);

    expect($source->lastBatch()->toArray()['read_only'])->toBe(['userId', 'label']);

    stateScreen();
    $screen = Native::test(StateScreen::class);
    $source->respondToBatch(fragments: [
        'stats' => ['fragment' => stateText('Stats 1')],
        'feed' => ['fragment' => ['type' => 'pressable', 'key' => 'go', 'on_press' => ['call' => 'shuffle', 'args' => [], 'target' => 'server'], 'children' => [stateText('Shuffle')]]],
    ]);
    stateFrame($screen)->tap('Shuffle');

    expect($source->lastAction('feed')->toArray()['read_only'])->toBe(['userId', 'label']);

    $screen->instance()->callServerMethod('save');
    $screen->instance()->uploadToServer('savePhoto', $this->photo);

    expect($server->lastRequest()->toArray()['read_only'])->toBe(['userId', 'label'])
        ->and($server->lastUpload()->toArray()['read_only'])->toBe(['userId', 'label'])
        ->and($server->lastRequest()->state)->toHaveKeys(['userId', 'label'])
        ->and($server->lastRequest()->state)->not->toHaveKey('draft');
});

it('leaves read_only off a request with no read-only keys', function () {
    $source = Asgard::fakeFragmentSource();
    $server = Asgard::fakeServerTransport();
    $screen = Native::test(LocalOnlyScreen::class);

    $screen->instance()->callServerMethod('save');

    expect($source->lastBatch()->toArray())->not->toHaveKey('read_only')
        ->and($server->lastRequest()->toArray())->not->toHaveKey('read_only');
});

it('names only read-only keys the state carries', function () {
    expect(new RegionRequest('r', 'app.x', 'stats', ['userId' => 'u1'], readOnly: ['userId']))
        ->toArray()->toMatchArray(['read_only' => ['userId']]);

    $component = new StateScreen;
    unset($component->userId);

    expect(ComponentState::readOnly($component, ['range' => '7d', 'label' => 'count:0']))
        ->toBe(['label']);
});

it('ignores a read-only key in any delta, and logs it', function () {
    $server = Asgard::fakeServerTransport();
    $screen = stateScreen();

    $log = stateDebugLog(function () use ($screen, $server) {
        $screen->instance()->callServerMethod('save');
        $server->resolve(delta: ['userId' => 'u2', 'count' => 3]);

        Asgard::fakeFragmentSource();
        $action = ServerRegion::action($screen->instance(), 'feed', 'shuffle', []);
        Asgard::fakeFragmentSource()->respondToAction($action, stateText('Shuffled'), delta: ['userId' => 'u3', 'label' => 'x', 'draft' => 'theirs']);
    });

    expect($screen->get('userId'))->toBe('u1')
        ->and($screen->get('count'))->toBe(3)
        ->and($screen->get('draft'))->toBe('')
        ->and($log)->toContain('delta prop [userId] is #[ServerReadOnly]')
        ->toContain('delta prop [label] is #[Computed]')
        ->toContain('delta prop [draft] is #[LocalOnly]');
});

// ── The delta on render answers ─────────────────────

it('applies a batch answer\'s delta before its fragments are painted', function () {
    $screen = stateScreen(delta: ['count' => 5, 'range' => '30d']);

    $screen->assertSee('Count 5')->assertSee('Stats 1');

    // The regions are answered for the state the delta left behind, which
    // is what the next request carries.
    expect($screen->get('range'))->toBe('30d')
        ->and(ServerRegion::subscriptions())->toHaveCount(1)
        ->and(ServerRegion::subscriptions()[0]['state'])->toMatchArray(['range' => '30d', 'count' => 5, 'label' => 'count:5']);
});

it('applies a single region answer\'s delta', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = stateScreen();
    $screen->instance()->refreshServerRegions('stats');
    stateFrame($screen);

    expect($source->respond('stats', stateText('Stats 2'), 'v2', delta: ['count' => 7]))->toBeTrue();

    stateFrame($screen)->assertSee('Count 7')->assertSee('Stats 2');
});

it('applies an action answer\'s delta, then paints its region and the others it carries', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = stateScreen()->tap('Shuffle');

    expect($source->respondToAction('feed', stateText('Shuffled'), ['stats' => ['fragment' => stateText('Stats 9')]], delta: ['count' => 2]))->toBeTrue();

    stateFrame($screen)->assertSee('Count 2')->assertSee('Shuffled')->assertSee('Stats 9');
    expect(collect(ServerRegion::subscriptions())->pluck('state.count')->all())->toBe([2]);
});

it('applies the delta of a cached answer', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(StateScreen::class);

    $source->respondToBatch(fragments: ['stats' => ['cached' => true], 'feed' => ['fragment' => stateText('Feed 1')]], delta: ['count' => 4]);

    expect($screen->get('count'))->toBe(4);
});

it('refuses a delta prop the user changed while the request was out', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(StateScreen::class)->tap('bump');

    $source->respondToBatch($source->batches()[0], fragments: [
        'stats' => ['fragment' => stateText('Stats 1')],
        'feed' => ['fragment' => stateText('Feed 1')],
    ], delta: ['count' => 10, 'range' => '30d']);

    expect($screen->get('count'))->toBe(1)
        ->and($screen->get('range'))->toBe('30d');
});

it('applies the delta of a superseded answer, and drops its fragment', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = stateScreen();
    $first = ServerRegion::action($screen->instance(), 'feed', 'shuffle', []);
    $second = ServerRegion::action($screen->instance(), 'feed', 'shuffle', []);

    expect($source->respondToAction($first, stateText('Old'), delta: ['count' => 1]))->toBeFalse()
        ->and($screen->get('count'))->toBe(1);

    // The second went out with count 0 as well, so compare-and-set keeps
    // the first answer's 1 rather than letting it be undone.
    expect($source->respondToAction($second, stateText('New'), delta: ['count' => 2]))->toBeTrue();
    stateFrame($screen)->assertSee('New')->assertDontSee('Old')->assertSee('Count 1');
});

it('drops the delta of an answer that is no longer in flight', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = stateScreen();
    $action = ServerRegion::action($screen->instance(), 'feed', 'shuffle', []);
    $source->failAction($action, ServerRegion::SERVER, 500);

    expect($source->respondToAction($action, stateText('Late'), delta: ['count' => 9]))->toBeFalse()
        ->and($screen->get('count'))->toBe(0);
});

it('hands the delta of an HTTP render answer over', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(StateScreen::class);

    HttpFragmentSource::answered($source->lastBatch(), [
        'ok' => true, 'status' => 200, 'delta' => ['count' => 6],
        'fragments' => ['stats' => ['fragment' => stateText('Stats 1')], 'feed' => ['fragment' => stateText('Feed 1')]],
    ], 'https://a.test/_asgard/render', 'regions [stats, feed]');

    stateFrame($screen)->assertSee('Count 6')->assertSee('Feed 1');
});

it('costs no repaint and keeps the push stream set for an empty or unchanged delta', function () {
    config([
        'midgard.asgard.base' => 'https://asgard.test/_asgard',
        'midgard.asgard.cache.ttl' => 300,
    ]);
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(StateScreen::class);
    $batch = $source->lastBatch();
    $source->respondToBatch(fragments: [
        'stats' => ['fragment' => stateText('Stats 1'), 'made_at' => Carbon::now()->getTimestampMs()],
        'feed' => ['fragment' => stateText('Feed 1')],
    ]);
    stateFrame($screen);
    $watching = $source->watching();

    // A cached answer for a copy the device lost after declaring it paints
    // nothing (it asks again), so only the delta could ask for a frame.
    $screen->instance()->refreshServerRegions('stats');
    stateFrame($screen);
    RegionCache::flush();
    $source->respondCached('stats', delta: ['count' => 0, 'range' => '7d']);

    expect(ServerRegion::repaintPending())->toBeFalse()
        ->and($screen->get('count'))->toBe(0);

    stateFrame($screen);
    expect($source->watching())->toBe($watching)
        ->and(ServerRegion::subscriptions()[0]['state'])->toBe($batch->state());

    // A delta that changes something asks for a frame.
    $source->respond('stats', stateText('Stats 1'), madeAt: Carbon::now()->getTimestampMs());
    stateFrame($screen);
    $screen->instance()->refreshServerRegions('stats');
    stateFrame($screen);
    RegionCache::flush();
    $source->respondCached('stats', delta: ['count' => 1]);

    expect(ServerRegion::repaintPending())->toBeTrue()
        ->and($screen->get('count'))->toBe(1);
    RegionCache::flush();
});

it('runs a delta against a server screen through the same rules, which set nothing', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(StateScreen::class);
    $request = $source->lastRequest('stats');

    // A request whose state has no such prop: compare-and-set refuses it.
    expect($source->respond($request, stateText('Stats 1'), delta: ['nope' => 1]))->toBeTrue()
        ->and(property_exists($screen->instance(), 'nope'))->toBeFalse();
});

// ── render: on #[Server] and uploads ───────────────

it('asks for no regions by default', function () {
    $server = Asgard::fakeServerTransport();
    $screen = stateScreen();

    $screen->instance()->callServerMethod('save');

    expect($server->lastRequest()->toArray())->not->toHaveKey('regions');
});

it('expands render: true to every region the component has drawn', function () {
    $server = Asgard::fakeServerTransport();
    $screen = stateScreen();

    $screen->instance()->callServerMethod('saveAll');

    expect($server->lastRequest()->toArray()['regions'])->toBe(['stats', 'feed']);
});

it('sends a named region, or a list without repeats', function () {
    $server = Asgard::fakeServerTransport();
    $screen = stateScreen();

    $screen->instance()->callServerMethod('saveStats');
    expect($server->lastRequest()->regions)->toBe(['stats']);

    $screen->instance()->callServerMethod('saveBoth');
    expect($server->lastRequest()->regions)->toBe(['feed', 'stats']);
});

it('paints the regions a call asked for after applying its delta', function () {
    $server = Asgard::fakeServerTransport();
    $screen = stateScreen();

    $screen->instance()->callServerMethod('saveAll');
    $server->resolve(delta: ['count' => 8], fragments: [
        'stats' => ['fragment' => stateText('Stats 8'), 'version' => 'v8'],
        'feed' => ['fragment' => stateText('Feed 8')],
    ]);

    stateFrame($screen)->assertSee('Count 8')->assertSee('Stats 8')->assertSee('Feed 8');

    expect(ServerRegion::subscriptions())->toHaveCount(1)
        ->and(ServerRegion::subscriptions()[0]['state']['count'])->toBe(8)
        ->and(ServerRegion::subscriptions()[0]['versions'])->toBe(['stats' => 'v8']);
});

it('takes render: on uploadToServer, and keeps the other args positional', function () {
    $server = Asgard::fakeServerTransport();
    $screen = stateScreen();

    $screen->instance()->uploadToServer('savePhoto', $this->photo, 'Sunset', 3, render: 'stats');
    expect($server->lastUpload()->toArray())->toMatchArray(['args' => ['Sunset', 3], 'regions' => ['stats']]);

    $screen->instance()->uploadToServer('savePhoto', $this->photo, render: true);
    expect($server->lastUpload()->regions)->toBe(['stats', 'feed']);

    $screen->instance()->uploadToServer('savePhoto', $this->photo);
    expect($server->lastUpload()->toArray())->not->toHaveKey('regions');

    $server->resolve(delta: ['count' => 2], fragments: ['stats' => ['fragment' => stateText('Uploaded')]]);
    stateFrame($screen)->assertSee('Count 2')->assertSee('Uploaded');
});

it('refuses a render that names no region', function (mixed $render) {
    expect(fn () => new Server(render: $render))->toThrow(InvalidArgumentException::class);
})->with([
    'an empty name' => [''],
    'an empty list' => [[]],
    'a map' => [['a' => 'stats']],
    'a list with a non-string' => [['stats', 3]],
    'a duplicate suffix' => ['stats#2'],
]);

it('refuses a bad render on an upload before anything is sent', function () {
    $server = Asgard::fakeServerTransport();
    $screen = stateScreen();

    expect(fn () => $screen->instance()->uploadToServer('savePhoto', $this->photo, render: []))->toThrow(InvalidArgumentException::class);
    expect($server->uploads())->toBe([]);
});

it('keeps an action request an action when its state carries read_only', function () {
    $action = new ActionRequest('a', 'app.x', 'stats', ['userId' => 'u1'], 'go', readOnly: ['userId']);

    expect((new RegionBatch([$action]))->toArray())->toMatchArray([
        'read_only' => ['userId'],
        'action' => ['call' => 'go', 'args' => []],
    ]);
});
