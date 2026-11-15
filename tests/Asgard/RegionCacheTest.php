<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Native\Api\Client\Asgard\AsgardClient;
use Native\Api\Client\Asgard\ComponentAddress;
use Native\Api\Client\Asgard\Events\RegionCacheInvalidation;
use Native\Api\Client\Asgard\Events\ServerRegionFailed;
use Native\Api\Client\Asgard\RegionCache;
use Native\Api\Client\Asgard\ServerMethods;
use Native\Api\Client\Asgard\ServerRegion;
use Native\Api\Client\Asgard\ServerScreen;
use Native\Api\Client\Asgard\Testing\Asgard;
use Native\Api\Client\Tests\Asgard\Fixtures\AppPromoScreen;
use Native\Api\Client\Tests\Asgard\Fixtures\CachedRegionScreen;
use Native\Api\Client\Tests\Fixtures\FakeAsgardServer;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

/**
 * The region cache: fragments kept across launches, painted at once when a
 * region loads again, declared to the server in a `cache` member, and
 * answered with `{"cached": true}` or a signed `Asgard-Cache: invalidate`.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/Fixtures/views');
    config([
        'app.debug' => false,
        'cache.default' => 'array',
        'midgard.asgard.base' => 'https://asgard.test/_asgard',
        'midgard.asgard.cache.ttl' => 300,
        'midgard.asgard.cache.store' => null,
        'midgard.asgard.state' => [],
    ]);
    NativeRouter::clearRoutes();
    ServerRegion::flush();
    ServerMethods::flush();
    ServerScreen::flushChrome();
    RegionCache::flush();
    Carbon::setTestNow(Carbon::createFromTimestampMs(1763150000000));
});

afterEach(function () {
    NativeRouter::clearRoutes();
    RegionCache::flush();
    Carbon::setTestNow();
});

const MADE_AT = 1763149990000;

function statsText(string $text): array
{
    return ['type' => 'text', 'props' => ['text' => $text]];
}

/** Forget everything in memory, as quitting the app does. The cache store stays. */
function relaunch(): void
{
    ServerRegion::flush();
    ServerMethods::flush();
    ServerScreen::flushChrome();
}

function cachedFrame(TestableComponent $screen): TestableComponent
{
    return Closure::bind(function () {
        $this->startInteraction();

        return $this->afterInteraction();
    }, $screen, TestableComponent::class)();
}

/** A screen whose `stats` got a fragment the server made at MADE_AT. */
function screenWithStoredStats(int $maxAge = 60): TestableComponent
{
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(CachedRegionScreen::class);
    $source->respond('stats', statsText('Stats from the server'), 'v1', madeAt: MADE_AT, maxAge: $maxAge);

    return cachedFrame($screen)->assertSee('Stats from the server');
}

function statsKey(array $state = ['range' => '7d']): ?string
{
    return RegionCache::key(ComponentAddress::for(new CachedRegionScreen), 'stats', $state);
}

// ── Storing ─────────────────────────────────────────

it('stores nothing and declares nothing while the ttl is 0', function () {
    config(['midgard.asgard.cache.ttl' => 0]);
    $source = Asgard::fakeFragmentSource();

    $screen = Native::test(CachedRegionScreen::class);
    $source->respond('stats', statsText('Fresh'), madeAt: MADE_AT);
    cachedFrame($screen)->call('refreshStats');

    expect(RegionCache::count())->toBe(0)
        ->and($source->lastRequest('stats')->cache)->toBe([])
        ->and($source->lastRequest('stats')->toArray())->not->toHaveKey('cache');
});

it('stores a fragment with made_at, max_age, version, stored_at and its effective ttl', function () {
    screenWithStoredStats(maxAge: 60);

    expect(RegionCache::get(statsKey()))->toBe([
        'fragment' => statsText('Stats from the server'),
        'chrome' => null,
        'version' => 'v1',
        'made_at' => MADE_AT,
        'max_age' => 60,
        'stored_at' => 1763150000000,
        'ttl' => 60,
    ]);
});

it('does not store a fragment the server gave a max age of 0, and drops the old copy', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = screenWithStoredStats();

    $screen->call('refreshStats');
    $source->respond('stats', statsText('Do not keep'), madeAt: MADE_AT + 1, maxAge: 0);

    expect(RegionCache::get(statsKey()))->toBeNull();
});

it('does not store a fragment without made_at', function () {
    $source = Asgard::fakeFragmentSource();
    Native::test(CachedRegionScreen::class);
    $source->respond('stats', statsText('Old server'));

    expect(RegionCache::count())->toBe(0);
});

it('keeps a region with cacheTtl 0 out of the cache, and uses its own ttl otherwise', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(CachedRegionScreen::class);
    $source->respond('news', statsText('News'), madeAt: MADE_AT);

    $newsKey = RegionCache::key(ComponentAddress::for(new CachedRegionScreen), 'news', ['range' => '7d']);
    expect(RegionCache::get($newsKey))->toBeNull();

    $screen->set('newsTtl', 30);
    $source->respond('news', statsText('News'), madeAt: MADE_AT);
    expect($source->respond($source->lastRequest('news'), statsText('News'), madeAt: MADE_AT))->toBeFalse();

    relaunch();
    $screen = Native::test(CachedRegionScreen::class);
    $screen->set('newsTtl', 30)->call('refreshStats');
    $source->respond('news', statsText('News'), madeAt: MADE_AT);

    expect(RegionCache::get($newsKey)['ttl'])->toBe(30);
});

// ── Painting and declaring ──────────────────────────

it('paints the cached copy at once after a relaunch and still asks, declaring it', function () {
    $source = Asgard::fakeFragmentSource();
    screenWithStoredStats();
    relaunch();

    Carbon::setTestNow(Carbon::now()->addSeconds(10));
    $before = count($source->requests('stats'));

    Native::test(CachedRegionScreen::class)
        ->assertSee('Stats from the server')
        ->assertDontSee('Loading stats…');

    expect($source->requests('stats'))->toHaveCount($before + 1)
        ->and($source->lastRequest('stats')->cache)->toBe(['stats' => ['made_at' => MADE_AT, 'ttl' => 60]])
        ->and($source->lastBatch()->toArray()['cache'])->toBe(['stats' => ['made_at' => MADE_AT, 'ttl' => 60]]);
});

it('paints an expired copy but does not declare it', function () {
    $source = Asgard::fakeFragmentSource();
    screenWithStoredStats(maxAge: 60);
    relaunch();

    Carbon::setTestNow(Carbon::now()->addSeconds(61));

    Native::test(CachedRegionScreen::class)->assertSee('Stats from the server');

    expect($source->lastRequest('stats')->cache)->toBe([]);
});

it('keys copies by state, so other state paints the placeholder', function () {
    $source = Asgard::fakeFragmentSource();
    screenWithStoredStats();
    relaunch();

    expect(statsKey(['range' => '30d']))->not->toBe(statsKey(['range' => '7d']));

    $screen = Native::test(CachedRegionScreen::class);
    $screen->call('pick', '30d')->call('refreshStats');

    expect($source->lastRequest('stats')->state)->toBe(['range' => '30d'])
        ->and($source->lastRequest('stats')->cache)->toBe([]);

    relaunch();
    $screen = Native::test(CachedRegionScreen::class);
    expect($screen->instance()->range)->toBe('7d');
    $screen->assertSee('Stats from the server');
});

it('keys copies by base url', function () {
    screenWithStoredStats();
    $key = statsKey();

    config(['midgard.asgard.base' => 'https://other.test/_asgard']);

    expect(statsKey())->not->toBe($key)
        ->and(RegionCache::get(statsKey()))->toBeNull();
});

it('does not watch a region painted from the cache until the server answers', function () {
    $source = Asgard::fakeFragmentSource();
    screenWithStoredStats();
    relaunch();

    $screen = Native::test(CachedRegionScreen::class)->assertSee('Stats from the server');

    expect(collect(ServerRegion::subscriptions())->pluck('regions')->flatten()->all())->not->toContain('stats');

    expect($source->respondCached('stats'))->toBeTrue();
    cachedFrame($screen)->assertSee('Stats from the server');

    expect(ServerRegion::subscriptions()[0]['regions'])->toContain('stats')
        ->and(ServerRegion::subscriptions()[0]['versions'])->toBe(['stats' => 'v1']);
});

// ── cached: true ────────────────────────────────────

it('keeps the painted copy and the entry as they are on a cached answer', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = screenWithStoredStats();
    $entry = RegionCache::get(statsKey());

    Carbon::setTestNow(Carbon::now()->addSeconds(5));
    $screen->call('refreshStats');
    expect($source->lastRequest('stats')->cache)->toBe(['stats' => ['made_at' => MADE_AT, 'ttl' => 60]]);

    expect($source->respondCached('stats'))->toBeTrue();
    cachedFrame($screen)->assertSee('Stats from the server');

    expect(RegionCache::get(statsKey()))->toBe($entry);
});

it('asks again without declaring when the device lost the copy, and fails a second cached answer', function () {
    $heard = [];
    Event::listen(ServerRegionFailed::class, function ($e) use (&$heard) {
        $heard[] = $e->reason;
    });

    $source = Asgard::fakeFragmentSource();
    $screen = screenWithStoredStats();
    $screen->call('refreshStats');
    $first = $source->lastRequest('stats');
    expect($first->cache)->toHaveKey('stats');

    // Flushed while the request was out.
    RegionCache::flush();

    expect($source->respondCached($first))->toBeFalse();

    $retry = $source->lastRequest('stats');
    expect($retry->id)->not->toBe($first->id)
        ->and($retry->cache)->toBe([])
        ->and($heard)->toBe([]);

    expect($source->respondCached($retry))->toBeFalse();
    $screen->wait();

    expect($heard)->toBe(['invalid'])
        ->and($source->lastRequest('stats')->id)->toBe($retry->id);
});

it('takes a fragment after the retry as a normal answer', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(CachedRegionScreen::class);

    $source->respondCached('stats');
    expect($source->respond('stats', statsText('Rendered after all'), madeAt: MADE_AT))->toBeTrue();

    cachedFrame($screen)->assertSee('Rendered after all');
});

it('asks again for a cached part of a batch or a call answer the device lost', function () {
    $source = Asgard::fakeFragmentSource();
    $server = Asgard::fakeServerTransport();
    $screen = Native::test(CachedRegionScreen::class);
    $before = count($source->requests('stats'));

    $source->respondToBatch(fragments: ['stats' => ['cached' => true], 'news' => ['fragment' => statsText('News'), 'made_at' => MADE_AT]]);
    expect($source->requests('stats'))->toHaveCount($before + 1);

    $source->respond('stats', statsText('Stats'), madeAt: MADE_AT);
    RegionCache::flush();

    $screen->call('save');
    $server->resolve(fragments: ['stats' => ['cached' => true]]);

    expect($source->requests('stats'))->toHaveCount($before + 2)
        ->and($source->lastRequest('stats')->cache)->toBe([]);
});

it('takes a fragment sent inside the grace window as a normal one, with no event', function () {
    $heard = 0;
    Event::listen(RegionCacheInvalidation::class, function () use (&$heard) {
        $heard++;
    });

    $source = Asgard::fakeFragmentSource();
    screenWithStoredStats(maxAge: 60);
    relaunch();

    // 50 of 60 seconds gone: still declared, but inside the server's grace
    // window, so the server renders it and sends it with no header.
    Carbon::setTestNow(Carbon::now()->addSeconds(50));
    $screen = Native::test(CachedRegionScreen::class)->assertSee('Stats from the server');
    expect($source->lastRequest('stats')->cache)->toHaveKey('stats');

    $source->respond('stats', statsText('Rendered in grace'), 'v2', madeAt: MADE_AT + 50000, maxAge: 60);

    cachedFrame($screen)->assertSee('Rendered in grace')->assertDontSee('Stats from the server');
    expect($heard)->toBe(0)
        ->and(ServerRegion::hasHeld())->toBeFalse()
        ->and(RegionCache::get(statsKey())['made_at'])->toBe(MADE_AT + 50000);
});

it('takes cached parts in a batch', function () {
    $source = Asgard::fakeFragmentSource();
    screenWithStoredStats();
    relaunch();

    $screen = Native::test(CachedRegionScreen::class);

    expect($source->respondToBatch(fragments: [
        'stats' => ['cached' => true],
        'news' => ['fragment' => statsText('News today'), 'made_at' => MADE_AT],
    ]))->toBe(2);

    cachedFrame($screen)->assertSee('Stats from the server')->assertSee('News today');
});

it('takes a cached answer to an action, and declares the cache on actions and calls', function () {
    $source = Asgard::fakeFragmentSource();
    $server = Asgard::fakeServerTransport();
    $screen = screenWithStoredStats();

    $action = ServerRegion::action($screen->instance(), 'stats', 'like', []);
    expect($action->toArray()['cache'])->toBe(['stats' => ['made_at' => MADE_AT, 'ttl' => 60]]);
    expect($action->toArray())->not->toHaveKey('reason');

    expect($source->respondToAction($action, null))->toBeTrue();
    cachedFrame($screen)->assertSee('Stats from the server');

    $screen->call('save');
    expect($server->lastRequest()->toArray()['cache'])->toBe(['stats' => ['made_at' => MADE_AT, 'ttl' => 60]]);
    expect($server->lastRequest()->toArray())->not->toHaveKey('reason');

    $server->resolve(fragments: ['stats' => ['cached' => true]]);
    cachedFrame($screen)->assertSee('Stats from the server');
});

// ── Asgard-Cache: invalidate ────────────────────────

it('fires RegionCacheInvalidation and applies the new fragment on the next turn by default', function () {
    $heard = [];
    Event::listen(RegionCacheInvalidation::class, function (RegionCacheInvalidation $e) use (&$heard) {
        $heard[] = [$e->region, $e->oldMadeAt, $e->newMadeAt, $e->fragment];
    });

    $source = Asgard::fakeFragmentSource();
    $screen = screenWithStoredStats();
    $screen->call('refreshStats');

    expect($source->respond('stats', statsText('Forced'), 'v2', madeAt: MADE_AT + 5000, invalidate: true))->toBeTrue();

    expect($heard)->toBe([['stats', MADE_AT, MADE_AT + 5000, statsText('Forced')]])
        ->and($screen->get('invalidations'))->toBe([['oldMadeAt' => MADE_AT, 'newMadeAt' => MADE_AT + 5000]]);

    // Held for the turn, so every listener has had its say.
    cachedFrame($screen)->assertSee('Stats from the server');

    $screen->wait()->assertSee('Forced');
    expect(RegionCache::get(statsKey())['made_at'])->toBe(MADE_AT + 5000);
});

it('lets a Laravel listener keep the cached copy', function () {
    Event::listen(RegionCacheInvalidation::class, fn (RegionCacheInvalidation $e) => $e->ignore());

    $source = Asgard::fakeFragmentSource();
    $screen = screenWithStoredStats();
    $entry = RegionCache::get(statsKey());
    $screen->call('refreshStats');

    $source->respond('stats', statsText('Forced'), 'v2', madeAt: MADE_AT + 5000, invalidate: true);

    $screen->wait()->assertSee('Stats from the server')->assertDontSee('Forced');
    expect(RegionCache::get(statsKey()))->toBe($entry);
});

it('lets an #[On] listener keep the cached copy through its CacheDecision', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = screenWithStoredStats();
    $screen->set('keepStats', true)->call('refreshStats');
    $entry = RegionCache::get(statsKey());

    $source->respond('stats', statsText('Forced'), 'v2', madeAt: MADE_AT + 5000, invalidate: true);

    $screen->wait()->assertSee('Stats from the server')->assertDontSee('Forced');

    expect($screen->get('invalidations'))->toHaveCount(1)
        ->and(RegionCache::get(statsKey()))->toBe($entry);

    // Still a good answer: the region is watched with the server's new version.
    expect(ServerRegion::subscriptions()[0]['versions']['stats'])->toBe('v2');
});

it('does not fire for a region without a cached copy', function () {
    $heard = 0;
    Event::listen(RegionCacheInvalidation::class, function () use (&$heard) {
        $heard++;
    });

    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(CachedRegionScreen::class);
    $source->respond('stats', statsText('Forced'), madeAt: MADE_AT, invalidate: true);

    cachedFrame($screen)->assertSee('Forced');
    expect($heard)->toBe(0);
});

it('holds invalidated fragments in a #[Server] call answer too', function () {
    $heard = 0;
    Event::listen(RegionCacheInvalidation::class, function () use (&$heard) {
        $heard++;
    });

    Asgard::fakeFragmentSource();
    $server = Asgard::fakeServerTransport();
    $screen = screenWithStoredStats();

    $screen->call('save');
    $server->resolve(fragments: ['stats' => ['fragment' => statsText('Saved'), 'made_at' => MADE_AT + 1]], invalidate: true);

    expect($heard)->toBe(1);
    $screen->wait()->assertSee('Saved');
});

it('drops a held fragment a newer request superseded', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = screenWithStoredStats();
    $screen->call('refreshStats');

    $source->respond('stats', statsText('Forced'), madeAt: MADE_AT + 1, invalidate: true);
    $screen->call('refreshStats');

    $screen->wait()->assertSee('Stats from the server')->assertDontSee('Forced');
});

// ── Flush ───────────────────────────────────────────

it('forgets every copy on flush', function () {
    $source = Asgard::fakeFragmentSource();
    screenWithStoredStats();

    expect(RegionCache::count())->toBe(1);

    RegionCache::flush();
    relaunch();

    expect(RegionCache::count())->toBe(0)
        ->and(RegionCache::get(statsKey()))->toBeNull();

    Native::test(CachedRegionScreen::class)->assertSee('Loading stats…');
    expect($source->lastRequest('stats')->cache)->toBe([]);
});

it('drops the oldest copy past the limit', function () {
    for ($i = 0; $i < RegionCache::MAX_ENTRIES + 1; $i++) {
        RegionCache::put("k{$i}", ['fragment' => [], 'chrome' => null, 'version' => null, 'made_at' => 1, 'max_age' => null, 'stored_at' => 1, 'ttl' => 1]);
    }

    expect(RegionCache::count())->toBe(RegionCache::MAX_ENTRIES)
        ->and(RegionCache::get('k0'))->toBeNull()
        ->and(RegionCache::get('k1'))->not->toBeNull();
});

// ── Server screens ──────────────────────────────────

it('paints a server screen from the cache with its chrome, with no skeleton and no timer', function () {
    $source = Asgard::fakeFragmentSource();
    NativeRouter::register('/', AppPromoScreen::class);
    ServerScreen::registerRoutes([['uri' => '/promos/{id}', 'screen' => 'promos.show']]);

    $screen = Native::visit('/promos/42');
    $source->respond('screen', statsText('Promo 42'), chrome: ['nav_bar' => ['title' => 'Cached title']], madeAt: MADE_AT);
    cachedFrame($screen)->assertSee('Promo 42');

    relaunch();
    ServerScreen::registerRoutes([['uri' => '/promos/{id}', 'screen' => 'promos.show']]);

    $screen = Native::visit('/promos/42')->assertSee('Promo 42');

    expect($screen->instance()->serverChrome()['nav_bar']['title'])->toBe('Cached title')
        ->and(ServerRegion::awaitingBody($screen->instance()))->toBeFalse()
        ->and($source->lastRequest('screen')->cache)->toBe(['screen' => ['made_at' => MADE_AT, 'ttl' => 300]]);

    expect($source->respondCached('screen'))->toBeTrue();
    $screen->wait(20)->assertSee('Promo 42')->assertNoNavigation();
});

// ── Push reason ─────────────────────────────────────

it('sends reason push for a fetch a push signal caused, and leaves it out otherwise', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = screenWithStoredStats();
    $source->respond('news', statsText('News'), 'n1');

    expect($source->lastBatch()->toArray())->not->toHaveKey('reason');

    $screen->call('refreshStats');
    expect($source->lastRequest('stats')->toArray())->not->toHaveKey('reason');
    $source->respondCached('stats');

    expect(ServerRegion::signal(ComponentAddress::for($screen->instance()), ['range' => '7d'], 'stats', 'v9'))->toBe(1);
    cachedFrame($screen);

    expect($source->lastRequest('stats')->reason)->toBe('push')
        ->and($source->lastRequest('stats')->toArray()['reason'])->toBe('push');

    // A plain refresh after that one goes without it.
    $screen->call('refreshStats');
    expect($source->lastRequest('stats')->toArray())->not->toHaveKey('reason');
});

it('sends reason push for a covered screen redrawn after a signal', function () {
    $source = Asgard::fakeFragmentSource();
    NativeRouter::register('/', AppPromoScreen::class);
    ServerScreen::registerRoutes([['uri' => '/promos/{id}', 'screen' => 'promos.show']]);

    $promo = Native::visit('/promos/1');
    $source->respond('screen', statsText('One'), 'v1', madeAt: MADE_AT);
    cachedFrame($promo);

    // Covered: the signal only marks it.
    expect(ServerRegion::signal('promos.show', ['id' => '1'], 'screen', 'v2'))->toBe(1);
    $source->assertRequested('screen', 1);

    cachedFrame($promo);

    expect($source->lastRequest('screen')->toArray()['reason'])->toBe('push');
});

// ── The wire ────────────────────────────────────────

it('reads made_at, max_age, cached answers and a signed invalidate header', function () {
    $server = new FakeAsgardServer;
    $client = AsgardClient::make(['base' => 'https://asgard.test/_asgard', 'key' => 'test-key', 'timeout' => 5, 'store' => null], $server->client());
    $body = ['protocol' => 1, 'component' => 'app.home', 'region' => 'stats', 'state' => [], 'cache' => ['stats' => ['made_at' => MADE_AT, 'ttl' => 60]]];

    $server->plaintext = json_encode(['protocol' => 1, 'fragment' => statsText('x'), 'made_at' => MADE_AT, 'max_age' => 60]);
    expect($client->render($body))->toBe(['ok' => true, 'status' => 200, 'fragment' => statsText('x'), 'made_at' => MADE_AT, 'max_age' => 60])
        ->and($server->requests[0]['body']['cache'])->toBe(['stats' => ['made_at' => MADE_AT, 'ttl' => 60]]);

    $server->plaintext = json_encode(['protocol' => 1, 'cached' => true]);
    expect($client->render($body))->toBe(['ok' => true, 'status' => 200, 'cached' => true]);

    $server->plaintext = json_encode(['protocol' => 1, 'fragments' => ['stats' => ['cached' => true], 'news' => ['fragment' => statsText('n'), 'made_at' => 5]]]);
    expect($client->render([...$body, 'regions' => ['stats', 'news']])['fragments'])
        ->toBe(['stats' => ['cached' => true], 'news' => ['fragment' => statsText('n'), 'made_at' => 5]]);

    $server->plaintext = json_encode(['protocol' => 1, 'fragment' => statsText('x'), 'made_at' => MADE_AT]);
    $server->cacheHeader = 'invalidate';
    expect($client->render($body)['invalidate'] ?? null)->toBeTrue();

    $server->plaintext = json_encode(['protocol' => 1, 'delta' => (object) []]);
    expect($client->callMethod(['protocol' => 1, 'component' => 'app.home', 'method' => 'save', 'args' => [], 'state' => []])['invalidate'] ?? null)->toBeTrue();
});

it('refuses an Asgard-Cache header the signature does not cover', function () {
    $server = new FakeAsgardServer;
    $client = AsgardClient::make(['base' => 'https://asgard.test/_asgard', 'key' => 'test-key', 'timeout' => 5, 'store' => null], $server->client());
    $server->tamper = fn (array $sealed) => ['headers' => $sealed['headers'] + ['asgard-cache' => 'invalidate'], 'body' => $sealed['body']];

    $result = $client->render(['protocol' => 1, 'component' => 'app.home', 'region' => 'stats', 'state' => []]);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('response failed verification');
});

it('refuses a signature that covers asgard-cache when the header was stripped', function () {
    $server = new FakeAsgardServer;
    $client = AsgardClient::make(['base' => 'https://asgard.test/_asgard', 'key' => 'test-key', 'timeout' => 5, 'store' => null], $server->client());
    $server->cacheHeader = 'invalidate';
    $server->tamper = function (array $sealed) {
        unset($sealed['headers']['asgard-cache']);

        return $sealed;
    };

    expect($client->render(['protocol' => 1, 'component' => 'app.home', 'region' => 'stats', 'state' => []])['ok'])->toBeFalse();
});

it('takes a forced call answer with no fragments quietly', function () {
    $heard = 0;
    Event::listen(RegionCacheInvalidation::class, function () use (&$heard) {
        $heard++;
    });

    Asgard::fakeFragmentSource();
    $server = Asgard::fakeServerTransport();
    $screen = screenWithStoredStats();

    $screen->call('save');
    expect($server->resolve(invalidate: true))->toBeTrue();

    $screen->wait()->assertSee('Stats from the server');
    expect($heard)->toBe(0)->and(ServerRegion::hasHeld())->toBeFalse();
});
