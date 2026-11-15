<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Native\Api\Client\Asgard\AsgardClient;
use Native\Api\Client\Asgard\ComponentAddress;
use Native\Api\Client\Asgard\Events\RegionCacheInvalidation;
use Native\Api\Client\Asgard\Events\StreamMessage;
use Native\Api\Client\Asgard\FragmentCallbacks;
use Native\Api\Client\Asgard\RegionCache;
use Native\Api\Client\Asgard\RegionStreams;
use Native\Api\Client\Asgard\Security\Wire;
use Native\Api\Client\Asgard\ServerMethods;
use Native\Api\Client\Asgard\ServerRegion;
use Native\Api\Client\Asgard\ServerScreen;
use Native\Api\Client\Asgard\Stream;
use Native\Api\Client\Asgard\Testing\Asgard;
use Native\Api\Client\Tests\Asgard\Fixtures\AppPromoScreen;
use Native\Api\Client\Tests\Asgard\Fixtures\MemoPatchFeedScreen;
use Native\Api\Client\Tests\Asgard\Fixtures\PatchFeedScreen;
use Native\Api\Client\Tests\Fixtures\FakeAsgardServer;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

/**
 * Region patches (§8.1): a `patch` event on the push stream changes one
 * list in a region the device holds, and anything it cannot apply cleanly
 * fetches the region whole, as a signal does.
 *
 * The events go through a real RegionStreams with ServerRegion behind it,
 * sealed the way a server seals them and emitted from FakeStream.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/Fixtures/views');
    config([
        'app.debug' => false,
        'cache.default' => 'array',
        'midgard.asgard.base' => 'https://asgard.test/_asgard',
        'midgard.asgard.cache.ttl' => 0,
        'midgard.asgard.cache.store' => null,
        'midgard.asgard.state' => [],
    ]);
    NativeRouter::clearRoutes();
    ServerRegion::flush();
    ServerMethods::flush();
    ServerScreen::flushChrome();
    RegionCache::flush();
    Carbon::setTestNow(Carbon::createFromTimestampMs(1763150000000));

    $this->stream = Stream::fake();
    $this->server = new FakeAsgardServer;
    $client = AsgardClient::make(
        ['base' => 'https://asgard.test/_asgard', 'key' => 'test-key', 'timeout' => 5, 'store' => null],
        $this->server->client(),
    );
    $client->handshake();
    $this->client = $client;

    $this->streams = new RegionStreams(
        $client,
        fn (...$args) => ServerRegion::push(...$args),
        fn (...$args) => ServerRegion::signal(...$args),
        null,
        fn (...$args) => ServerRegion::patch(...$args),
    );
    Event::listen(StreamMessage::class, fn (StreamMessage $e) => $this->streams->receive($e));

    $this->eventId = 0;

    // Seal a patch event for the stream that is open now, and emit it.
    $this->patch = function (array $patch, string $from, string $version, string $component, array $state, string $region = 'feed', ?array $payload = null) {
        $this->streams->watch(ServerRegion::subscriptions());
        $open = $this->stream->lastOpen(RegionStreams::ID);
        $opened = $this->server->openStream($open['url'], array_change_key_case($open['headers']), $open['body']);
        $id = (string) ++$this->eventId;

        $payload ??= [
            'protocol' => 1,
            'subscription' => ServerRegion::subscriptionId($component, $state),
            'region' => $region,
            'from' => $from,
            'version' => $version,
            'patch' => $patch,
        ];

        $this->stream->emit(RegionStreams::ID, Wire::sealEvent(
            $this->server->sessions[$opened['session']], $opened['session'], $opened['signature'], $id, 'patch', json_encode($payload), null, false,
        ), 'patch', $id);
    };

    // A patch for the feed screen, with its address and state.
    $this->feedPatch = fn (array $patch, string $from = 'v1', string $version = 'v2') => ($this->patch)(
        $patch, $from, $version, ComponentAddress::for(PatchFeedScreen::class), ['tab' => 'all'],
    );
});

afterEach(function () {
    Stream::unfake();
    NativeRouter::clearRoutes();
    RegionCache::flush();
    Carbon::setTestNow();
});

function feedItem(int|string $id): array
{
    return [
        'type' => 'pressable',
        'key' => (string) $id,
        'on_press' => ['call' => 'pick', 'args' => [(string) $id], 'target' => 'client'],
        'children' => [['type' => 'text', 'props' => ['text' => "Item {$id}"]]],
    ];
}

/** The feed: a header, then the `items` list. */
function feedTree(array $ids): array
{
    return ['type' => 'column', 'children' => [
        ['type' => 'text', 'props' => ['text' => 'Latest']],
        ['type' => 'column', 'key' => 'items', 'children' => array_map(feedItem(...), $ids)],
    ]];
}

/** Draw the next frame, as the runloop does once a fragment lands. */
function nextFrame(TestableComponent $screen): TestableComponent
{
    return Closure::bind(function () {
        $this->startInteraction();

        return $this->afterInteraction();
    }, $screen, TestableComponent::class)();
}

/** The item labels on screen, in order. */
function itemsOn(TestableComponent $screen): array
{
    $texts = Closure::bind(fn () => $this->collectText($this->tree()), $screen, TestableComponent::class)();

    return array_values(array_map(
        fn ($text) => (int) substr($text, 5),
        array_filter($texts, fn ($text) => str_starts_with($text, 'Item ')),
    ));
}

/** A feed screen showing items 1 to 3 at version v1. */
function feedScreen(array $ids = [1, 2, 3], ?int $madeAt = null): TestableComponent
{
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(PatchFeedScreen::class);
    $source->respond('feed', feedTree($ids), 'v1', madeAt: $madeAt);

    return nextFrame($screen);
}

// ── Ops ─────────────────────────────────────────────

it('prepends nodes in order before the first child', function () {
    $screen = feedScreen();

    ($this->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(5), feedItem(4)]]);

    expect(ServerRegion::repaintPending())->toBeTrue()
        ->and(itemsOn(nextFrame($screen)))->toBe([5, 4, 1, 2, 3]);
    $screen->assertSee('Latest');
    Asgard::fakeFragmentSource()->assertRequested('feed', 1);
});

it('appends nodes in order after the last child', function () {
    $screen = feedScreen();

    ($this->feedPatch)(['op' => 'append', 'target' => 'items', 'nodes' => [feedItem(4), feedItem(5)]]);

    expect(itemsOn(nextFrame($screen)))->toBe([1, 2, 3, 4, 5]);
});

it('removes direct children by key, compared as strings, and ignores keys that are not there', function () {
    $screen = feedScreen([1, 2, 3, 4]);

    ($this->feedPatch)(['op' => 'remove', 'target' => 'items', 'keys' => [2, '4', '99']]);

    expect(itemsOn(nextFrame($screen)))->toBe([1, 3]);
    Asgard::fakeFragmentSource()->assertRequested('feed', 1);
});

it('trims from the end after a prepend with keep', function () {
    $screen = feedScreen();

    ($this->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(5), feedItem(4)], 'keep' => 3]);

    expect(itemsOn(nextFrame($screen)))->toBe([5, 4, 1]);
});

it('trims from the start after an append with keep', function () {
    $screen = feedScreen();

    ($this->feedPatch)(['op' => 'append', 'target' => 'items', 'nodes' => [feedItem(4), feedItem(5)], 'keep' => 3]);

    expect(itemsOn(nextFrame($screen)))->toBe([3, 4, 5]);
});

it('leaves a list under keep as it is', function () {
    $screen = feedScreen();

    ($this->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(4)], 'keep' => 10]);

    expect(itemsOn(nextFrame($screen)))->toBe([4, 1, 2, 3]);
});

it('drops an existing child with the same key before a prepend', function () {
    $screen = feedScreen();

    ($this->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(4), feedItem(2)]]);

    expect(itemsOn(nextFrame($screen)))->toBe([4, 2, 1, 3]);
});

it('drops an existing child with the same key before an append', function () {
    $screen = feedScreen();

    ($this->feedPatch)(['op' => 'append', 'target' => 'items', 'nodes' => [feedItem(1), feedItem(4)]]);

    expect(itemsOn(nextFrame($screen)))->toBe([2, 3, 1, 4]);
});

it('inserts nodes with no key as they are', function () {
    $screen = feedScreen();
    $unkeyed = array_diff_key(feedItem(1), ['key' => true]);

    ($this->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [$unkeyed]]);

    expect(itemsOn(nextFrame($screen)))->toBe([1, 1, 2, 3]);
});

it('applies keep after dropping duplicates', function () {
    $screen = feedScreen();

    // Item 3 is already there: dropping it leaves 4, 3, 1, 2, so keep 3 cuts item 2.
    ($this->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(4), feedItem(3)], 'keep' => 3]);
    expect(itemsOn(nextFrame($screen)))->toBe([4, 3, 1]);

    ($this->feedPatch)(['op' => 'append', 'target' => 'items', 'nodes' => [feedItem(4), feedItem(5)], 'keep' => 3], 'v2', 'v3');
    expect(itemsOn(nextFrame($screen)))->toBe([1, 4, 5]);
});

it('targets the first node with the key, depth first', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(PatchFeedScreen::class);
    $source->respond('feed', ['type' => 'column', 'children' => [
        ['type' => 'column', 'children' => [['type' => 'column', 'key' => 'items', 'children' => [feedItem(1)]]]],
        ['type' => 'column', 'key' => 'items', 'children' => [feedItem(2)]],
    ]], 'v1');
    nextFrame($screen);

    ($this->feedPatch)(['op' => 'append', 'target' => 'items', 'nodes' => [feedItem(3)]]);

    expect(itemsOn(nextFrame($screen)))->toBe([1, 3, 2]);
});

it('matches an integer key with a string target', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(PatchFeedScreen::class);
    $source->respond('feed', ['type' => 'column', 'key' => 7, 'children' => [feedItem(1)]], 'v1');
    nextFrame($screen);

    ($this->feedPatch)(['op' => 'append', 'target' => '7', 'nodes' => [feedItem(2)]]);

    expect(itemsOn(nextFrame($screen)))->toBe([1, 2]);
});

it('applies patches in the order they arrive and holds each new version', function () {
    $screen = feedScreen([1]);

    foreach (range(2, 61) as $n) {
        ($this->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem($n)], 'keep' => 50], 'v'.($n - 1), 'v'.$n);
    }

    expect(itemsOn(nextFrame($screen)))->toBe(range(61, 12))
        ->and(ServerRegion::subscriptions()[0]['versions'])->toBe(['feed' => 'v61'])
        ->and($this->streams->subscriptions()[ServerRegion::subscriptionId(ComponentAddress::for(PatchFeedScreen::class), ['tab' => 'all'])]['versions'])->toBe(['feed' => 'v61']);
    Asgard::fakeFragmentSource()->assertRequested('feed', 1);
});

it('keeps patching the same target after a frame has drawn it', function () {
    $screen = feedScreen();

    ($this->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(4)]]);
    nextFrame($screen);
    ($this->feedPatch)(['op' => 'remove', 'target' => 'items', 'keys' => ['1']], 'v2', 'v3');

    expect(itemsOn(nextFrame($screen)))->toBe([4, 2, 3]);
});

it('does nothing for a patch to the version the region already holds', function () {
    $screen = feedScreen();

    ($this->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(4)]], 'v0', 'v1');

    expect(itemsOn(nextFrame($screen)))->toBe([1, 2, 3]);
    Asgard::fakeFragmentSource()->assertRequested('feed', 1);
});

// ── Falling back to a fetch ─────────────────────────

it('fetches the region whole when the patch does not apply', function (Closure $send) {
    $screen = feedScreen();
    $source = Asgard::fakeFragmentSource();

    $send($this);

    expect(ServerRegion::repaintPending())->toBeTrue();
    nextFrame($screen);

    // Nothing changed, and the region is asked for again as a signal asks.
    expect(itemsOn($screen))->toBe([1, 2, 3]);
    $source->assertRequested('feed', 2);
    expect($source->lastRequest('feed')->reason)->toBe('push');

    $source->respond('feed', feedTree([9, 1, 2, 3]), 'v2');
    expect(itemsOn(nextFrame($screen)))->toBe([9, 1, 2, 3]);
})->with([
    'a version mismatch' => [fn ($t) => ($t->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(9)]], 'v0', 'v2')],
    'a target that is not there' => [fn ($t) => ($t->feedPatch)(['op' => 'prepend', 'target' => 'nope', 'nodes' => [feedItem(9)]])],
    'an empty target' => [fn ($t) => ($t->feedPatch)(['op' => 'prepend', 'target' => '', 'nodes' => [feedItem(9)]])],
    'a target that names an item, not a list' => [fn ($t) => ($t->feedPatch)(['op' => 'remove', 'target' => '42', 'keys' => ['1']])],
    'an unknown op' => [fn ($t) => ($t->feedPatch)(['op' => 'replace', 'target' => 'items', 'nodes' => [feedItem(9)]])],
    'nodes that are not a list' => [fn ($t) => ($t->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => feedItem(9)])],
    'too many nodes' => [fn ($t) => ($t->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => array_map(feedItem(...), range(100, 200))])],
    'a keep of 0' => [fn ($t) => ($t->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(9)], 'keep' => 0])],
    'a keep that is a string' => [fn ($t) => ($t->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(9)], 'keep' => '3'])],
    'a keep over the limit' => [fn ($t) => ($t->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(9)], 'keep' => 100001])],
    'remove without keys' => [fn ($t) => ($t->feedPatch)(['op' => 'remove', 'target' => 'items'])],
    'remove with a key that is not a string' => [fn ($t) => ($t->feedPatch)(['op' => 'remove', 'target' => 'items', 'keys' => [['1']]])],
    'a node of an unknown type' => [fn ($t) => ($t->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [['type' => 'marquee']]])],
    'a node with no type' => [fn ($t) => ($t->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => ['text']])],
    'a node with a bad media url' => [fn ($t) => ($t->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [['type' => 'image', 'props' => ['src' => 'javascript:alert(1)']]]])],
    'no from' => [fn ($t) => ($t->patch)([], 'v1', 'v2', ComponentAddress::for(PatchFeedScreen::class), ['tab' => 'all'], payload: [
        'protocol' => 1, 'subscription' => ServerRegion::subscriptionId(ComponentAddress::for(PatchFeedScreen::class), ['tab' => 'all']), 'region' => 'feed', 'version' => 'v2',
        'patch' => ['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(9)]],
    ])],
    'no patch' => [fn ($t) => ($t->patch)([], 'v1', 'v2', ComponentAddress::for(PatchFeedScreen::class), ['tab' => 'all'], payload: [
        'protocol' => 1, 'subscription' => ServerRegion::subscriptionId(ComponentAddress::for(PatchFeedScreen::class), ['tab' => 'all']), 'region' => 'feed', 'from' => 'v1', 'version' => 'v2',
    ])],
]);

it('leaves the tree alone when a later node of the patch fails a check', function () {
    $screen = feedScreen();

    ($this->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(4), ['type' => 'marquee']]]);

    expect(itemsOn(nextFrame($screen)))->toBe([1, 2, 3]);
    Asgard::fakeFragmentSource()->assertRequested('feed', 2);
});

it('fetches a region that has no tree from the server yet', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(PatchFeedScreen::class);

    // The stream only carries answered regions, so hand the patch over directly.
    expect(ServerRegion::patch(ComponentAddress::for(PatchFeedScreen::class), ['tab' => 'all'], 'feed', 'v1', 'v2', ['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(1)]]))->toBe(0);
    nextFrame($screen)->assertSee('Loading feed…');

    $source->assertRequested('feed', 2);
});

it('fetches a region that shows a cached copy rather than patching it', function () {
    config(['midgard.asgard.cache.ttl' => 300]);
    feedScreen(madeAt: 1763149990000);

    // Relaunch: the copy paints from the cache, unanswered.
    ServerRegion::flush();
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(PatchFeedScreen::class);
    expect(itemsOn($screen))->toBe([1, 2, 3])
        ->and(ServerRegion::showsCachedCopy($screen->instance(), 'feed'))->toBeTrue();

    // The stream only carries answered regions, so hand the patch over directly.
    expect(ServerRegion::patch(ComponentAddress::for(PatchFeedScreen::class), ['tab' => 'all'], 'feed', 'v1', 'v2', ['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(4)]]))->toBe(0);
    nextFrame($screen);

    // Once before the relaunch, once after it, and once for the patch.
    expect(itemsOn($screen))->toBe([1, 2, 3]);
    $source->assertRequested('feed', 3);
});

it('signals when no patcher is configured', function () {
    $signals = [];
    $streams = new RegionStreams(
        $this->client,
        fn () => 1,
        function (...$args) use (&$signals) {
            $signals[] = $args;

            return 1;
        },
    );
    $this->streams = $streams;
    $screen = feedScreen();

    ($this->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(4)]]);

    expect($signals)->toBe([[ComponentAddress::for(PatchFeedScreen::class), ['tab' => 'all'], 'feed', 'v2']])
        ->and(itemsOn(nextFrame($screen)))->toBe([1, 2, 3]);
});

// ── The region cache ────────────────────────────────

it('drops the region\'s cached copy once, and never fires an invalidation', function () {
    config(['midgard.asgard.cache.ttl' => 300]);
    $fired = 0;
    Event::listen(RegionCacheInvalidation::class, function () use (&$fired) {
        $fired++;
    });

    $screen = feedScreen(madeAt: 1763149990000);
    $key = RegionCache::key(ComponentAddress::for(PatchFeedScreen::class), 'feed', ['tab' => 'all']);
    expect(RegionCache::get($key))->not->toBeNull();

    ($this->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(4)]]);

    expect(RegionCache::get($key))->toBeNull();

    // Something else stored under the key is left alone by the next patch.
    RegionCache::put($key, ['fragment' => feedTree([1]), 'chrome' => null, 'version' => 'v0', 'made_at' => 1, 'max_age' => null, 'stored_at' => 1, 'ttl' => 300]);
    ($this->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(5)]], 'v2', 'v3');

    expect(RegionCache::get($key))->not->toBeNull()
        ->and(itemsOn(nextFrame($screen)))->toBe([5, 4, 1, 2, 3])
        ->and($fired)->toBe(0);

    // The next full answer stores a fresh copy, and a patch after it drops that.
    $screen->instance()->refreshServerRegions('feed');
    nextFrame($screen);
    Asgard::fakeFragmentSource()->respond('feed', feedTree([6]), 'v4', madeAt: 1763150000000);
    expect(RegionCache::get($key)['made_at'])->toBe(1763150000000);

    ($this->feedPatch)(['op' => 'append', 'target' => 'items', 'nodes' => [feedItem(7)]], 'v4', 'v5');
    expect(RegionCache::get($key))->toBeNull();
});

// ── Callbacks ───────────────────────────────────────

it('wires taps on patched nodes like taps on rendered ones', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = feedScreen();

    ($this->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [
        feedItem(4),
        ['type' => 'pressable', 'key' => '5', 'on_press' => ['call' => 'archive', 'args' => ['5']], 'children' => [['type' => 'text', 'props' => ['text' => 'Archive 5']]]],
    ]]);
    nextFrame($screen);

    $screen->tap('Item 4')->tap('Item 1');
    expect($screen->get('picked'))->toBe(['4', '1']);

    $screen->tap('Archive 5');
    $source->assertActionSent('feed', 'archive', 1);
    expect($source->lastAction('feed')->args)->toBe(['5']);
});

it('drops a bad callback on a patched node and keeps the node', function () {
    $screen = feedScreen();

    ($this->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [
        ['type' => 'pressable', 'key' => '4', 'on_press' => 'pick', 'children' => [['type' => 'text', 'props' => ['text' => 'Item 4']]]],
    ]]);

    expect(itemsOn(nextFrame($screen)))->toBe([4, 1, 2, 3]);
    $screen->assertElement('pressable', fn ($node) => ($node['props']['on_press'] ?? $node['on_press'] ?? null) === null);
});

it('keeps taps on what is on screen working until the next frame, then forgets removed nodes', function () {
    $screen = feedScreen();
    $owner = $screen->instance();
    $expression = FragmentCallbacks::expressionFor(['call' => 'pick', 'args' => ['1'], 'target' => 'client']);
    $id = ServerRegion::registryFor('feed', $owner)->lookup($expression);
    expect($id)->not->toBeNull();

    ($this->feedPatch)(['op' => 'remove', 'target' => 'items', 'keys' => ['1']]);

    // Not drawn yet: item 1 is still on screen, and still answers.
    expect(ServerRegion::registryFor('feed', $owner)?->resolve($id))->not->toBeNull();

    nextFrame($screen);

    expect(ServerRegion::registryFor('feed', $owner)->lookup($expression))->toBeNull()
        ->and(ServerRegion::registryFor('feed', $owner)->resolve($id))->toBeNull()
        ->and(itemsOn($screen))->toBe([2, 3]);
});

// ── Server screens ──────────────────────────────────

it('patches a server screen\'s body, and keeps its chrome', function () {
    $source = Asgard::fakeFragmentSource();
    NativeRouter::register('/', AppPromoScreen::class);
    ServerScreen::registerRoutes([['uri' => '/promos/{id}', 'screen' => 'promos.show']]);

    $promo = Native::visit('/promos/1');
    $source->respond('screen', feedTree([1, 2]), 'v1', ['nav_bar' => ['title' => 'Promo 1']]);
    nextFrame($promo);

    ($this->patch)(['op' => 'append', 'target' => 'items', 'nodes' => [feedItem(3)], 'keep' => 2], 'v1', 'v2', 'promos.show', ['id' => '1'], 'screen');

    expect(itemsOn(nextFrame($promo)))->toBe([2, 3])
        ->and($promo->instance()->serverChrome()['nav_bar']['title'])->toBe('Promo 1');
    $source->assertRequested('screen', 1);

    // A mismatch on a server screen fetches its body.
    ($this->patch)(['op' => 'append', 'target' => 'items', 'nodes' => [feedItem(4)]], 'v1', 'v3', 'promos.show', ['id' => '1'], 'screen');
    nextFrame($promo);
    $source->assertRequested('screen', 2);
});

// ── Built elements ──────────────────────────────────

/** The elements a screen's feed region keeps between frames. */
function builtFeed(TestableComponent|PatchFeedScreen $screen): ?array
{
    $owner = $screen instanceof TestableComponent ? $screen->instance() : $screen;

    return ((new ReflectionMethod(ServerRegion::class, 'built'))->invoke(null)[$owner] ?? [])['feed'] ?? null;
}

/** The items list element of a screen's feed, from what the region keeps. */
function builtItems(TestableComponent|PatchFeedScreen $screen): Element
{
    return builtFeed($screen)['roots'][0]->getChildren()[1];
}

it('paints a patched list exactly as a fresh render of the same tree', function (array $ops, array $expected) {
    $screen = feedScreen();

    foreach ($ops as $n => $op) {
        ($this->feedPatch)($op, 'v'.($n + 1), 'v'.($n + 2));
        nextFrame($screen);
    }

    expect(itemsOn($screen))->toBe($expected);

    $patched = $screen->tree();
    expect($patched)->toBe(feedScreen($expected)->tree());
})->with([
    'prepend twice, then keep' => [[
        ['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(4)]],
        ['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(5)], 'keep' => 4],
    ], [5, 4, 1, 2]],
    'append with keep' => [[
        ['op' => 'append', 'target' => 'items', 'nodes' => [feedItem(4), feedItem(5)], 'keep' => 3],
    ], [3, 4, 5]],
    'remove' => [[
        ['op' => 'remove', 'target' => 'items', 'keys' => ['2']],
    ], [1, 3]],
    'prepend a key already there' => [[
        ['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(2)]],
    ], [2, 1, 3]],
    'mixed' => [[
        ['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(4)]],
        ['op' => 'remove', 'target' => 'items', 'keys' => ['1', '3']],
        ['op' => 'append', 'target' => 'items', 'nodes' => [feedItem(6)]],
    ], [4, 2, 6]],
]);

it('puts back the same elements on a frame with no change', function () {
    $screen = feedScreen();
    $roots = builtFeed($screen)['roots'];

    expect(builtFeed(nextFrame($screen))['roots'])->toBe($roots);
});

it('builds only the new rows of a patch and keeps the rest', function () {
    $screen = feedScreen();
    $before = builtItems($screen)->getChildren();

    ($this->feedPatch)(['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(4)], 'keep' => 3]);
    nextFrame($screen);

    $after = builtItems($screen)->getChildren();

    expect($after)->toHaveCount(3)
        ->and(in_array($after[0], $before, true))->toBeFalse()
        ->and($after[1])->toBe($before[0])
        ->and($after[2])->toBe($before[1])
        ->and(itemsOn($screen))->toBe([4, 1, 2]);
});

it('builds every element again for a full fragment', function () {
    $screen = feedScreen();
    $roots = builtFeed($screen)['roots'];

    ServerRegion::applyFragment('feed', feedTree([7, 8]), $screen->instance());
    nextFrame($screen);

    expect(builtFeed($screen)['roots'][0])->not->toBe($roots[0])
        ->and(itemsOn($screen))->toBe([7, 8]);
});

it('keeps a callback another node still carries when a patch removes one', function () {
    $screen = feedScreen();
    $owner = $screen->instance();
    $twin = ['type' => 'pressable', 'key' => 'twin', 'on_press' => ['call' => 'pick', 'args' => ['1'], 'target' => 'client'],
        'children' => [['type' => 'text', 'props' => ['text' => 'Item 9']]]];

    ($this->feedPatch)(['op' => 'append', 'target' => 'items', 'nodes' => [$twin]]);
    nextFrame($screen);
    ($this->feedPatch)(['op' => 'remove', 'target' => 'items', 'keys' => ['1']], 'v2', 'v3');
    nextFrame($screen);

    $expression = FragmentCallbacks::expressionFor(['call' => 'pick', 'args' => ['1'], 'target' => 'client']);
    expect(ServerRegion::registryFor('feed', $owner)->lookup($expression))->not->toBeNull();

    $screen->tap('Item 9');
    expect($screen->get('picked'))->toBe(['1']);
});

it('keeps a callback a patch removes and the next one brings back before a frame', function () {
    $screen = feedScreen();
    $owner = $screen->instance();

    ($this->feedPatch)(['op' => 'remove', 'target' => 'items', 'keys' => ['1']]);
    ($this->feedPatch)(['op' => 'append', 'target' => 'items', 'nodes' => [feedItem(1)]], 'v2', 'v3');
    nextFrame($screen);

    $expression = FragmentCallbacks::expressionFor(['call' => 'pick', 'args' => ['1'], 'target' => 'client']);
    expect(ServerRegion::registryFor('feed', $owner)->lookup($expression))->not->toBeNull()
        ->and(itemsOn($screen))->toBe([2, 3, 1]);

    $screen->tap('Item 1');
    expect($screen->get('picked'))->toBe(['1']);
});

/** A memo screen holding the feed tree, painted once. */
function memoFeed(array $tree): MemoPatchFeedScreen
{
    $screen = new MemoPatchFeedScreen;
    expect(ServerRegion::applyFragment('feed', $tree, $screen))->toBeTrue();
    MemoPatchFeedScreen::frame($screen);

    return $screen;
}

function applyMemoPatch(MemoPatchFeedScreen $screen, array $op): void
{
    expect((new ReflectionMethod(ServerRegion::class, 'applyPatch'))->invoke(null, $screen, 'feed', 'v1', 'v1', $op))->toBeNull();
}

/** The node with id `$id` in a published frame. The fragment's root column is unkeyed, so paths run `/feed/0/…`. */
function frameNode(array $node, int $id): ?array
{
    if ($node['id'] === $id) {
        return $node;
    }

    foreach ($node['children'] ?? [] as $child) {
        if (($found = frameNode($child, $id)) !== null) {
            return $found;
        }
    }

    return null;
}

it('never answers for a patched list with a REUSE marker when native holds the old one', function () {
    $screen = memoFeed(feedTree([1, 2, 3]));
    MemoPatchFeedScreen::frame($screen);

    applyMemoPatch($screen, ['op' => 'prepend', 'target' => 'items', 'nodes' => [feedItem(4)]]);
    $frame = MemoPatchFeedScreen::frame($screen);

    $items = frameNode($frame, Element::fnv1a32('/feed/0/items'));
    $new = frameNode($frame, Element::fnv1a32('/feed/0/items/4'));
    $kept = frameNode($frame, Element::fnv1a32('/feed/0/items/1'));

    expect(($items['flags'] ?? 0) & 1)->toBe(0)
        ->and($items['children'])->toHaveCount(4)
        ->and(($new['flags'] ?? 0) & 1)->toBe(0)
        ->and($new['children'] ?? [])->not->toBe([])
        ->and(($kept['flags'] ?? 0) & 1)->toBe(1);
});

it('patches a second list after the first, with neither answered from a stale marker', function () {
    $tree = ['type' => 'column', 'children' => [
        ['type' => 'column', 'key' => 'pinned', 'children' => [feedItem('p1')]],
        ['type' => 'column', 'key' => 'items', 'children' => array_map(feedItem(...), [1, 2])],
    ]];
    $screen = memoFeed($tree);
    MemoPatchFeedScreen::frame($screen);

    applyMemoPatch($screen, ['op' => 'append', 'target' => 'items', 'nodes' => [feedItem(3)]]);
    MemoPatchFeedScreen::frame($screen);
    applyMemoPatch($screen, ['op' => 'prepend', 'target' => 'pinned', 'nodes' => [feedItem('p0')]]);
    $frame = MemoPatchFeedScreen::frame($screen);

    $pinned = frameNode($frame, Element::fnv1a32('/feed/0/pinned'));
    expect(($pinned['flags'] ?? 0) & 1)->toBe(0)
        ->and(array_map(fn ($child) => $child['id'], $pinned['children']))->toBe([
            Element::fnv1a32('/feed/0/pinned/p0'),
            Element::fnv1a32('/feed/0/pinned/p1'),
        ]);

    // And the first list, shown again unchanged, comes back whole.
    $items = frameNode($frame, Element::fnv1a32('/feed/0/items'));
    expect($items['flags'] & 1)->toBe(1);

    $fresh = new MemoPatchFeedScreen;
    ServerRegion::applyFragment('feed', ['type' => 'column', 'children' => [
        ['type' => 'column', 'key' => 'pinned', 'children' => [feedItem('p0'), feedItem('p1')]],
        ['type' => 'column', 'key' => 'items', 'children' => array_map(feedItem(...), [1, 2, 3])],
    ]], $fresh);
    MemoPatchFeedScreen::frame($fresh);

    // Without memo, both serialize every node: the elements must match.
    $plain = Closure::bind(fn () => $this->renderToElement()->toArray(new CallbackRegistry), $screen, NativeComponent::class)();
    $want = Closure::bind(fn () => $this->renderToElement()->toArray(new CallbackRegistry), $fresh, NativeComponent::class)();
    expect($plain)->toBe($want);
});
