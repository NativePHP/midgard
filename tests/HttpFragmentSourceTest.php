<?php

use Illuminate\Support\Facades\Event;
use Native\Api\Client\Asgard\AsgardServiceProvider;
use Native\Api\Client\Asgard\Contracts\FragmentSource;
use Native\Api\Client\Asgard\Events\ServerActionFailed;
use Native\Api\Client\Asgard\Events\ServerRegionFailed;
use Native\Api\Client\Asgard\HttpFragmentSource;
use Native\Api\Client\Asgard\RegionBatch;
use Native\Api\Client\Asgard\ServerRegion;
use Native\Api\Client\Asgard\Testing\Asgard;
use Native\Api\Client\Tests\Fixtures\RegionPairScreen;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

// What the transport tells the device once a render call is over: each
// region's part of the `fragments` map, or why there is none (protocol §3.1,
// §10.2, §12.1). The requests are
// real ones, made by a screen's first frame, so what HttpFragmentSource
// hands to ServerRegion shows on that screen.
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/Fixtures/views');

    $this->config = ['base' => 'https://a.test/_asgard', 'key' => 'k', 'timeout' => 5, 'store' => null];

    $this->failures = [];
    Event::listen(ServerRegionFailed::class, fn ($event) => $this->failures[] = $event);
    Event::listen(ServerActionFailed::class, fn ($event) => $this->failures[] = $event);
});

/**
 * A screen whose two regions were asked for in one request, held by the fake
 * source.
 *
 * @return array{0: TestableComponent, 1: RegionBatch}
 */
function regionsInFlight(): array
{
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(RegionPairScreen::class);

    return [$screen, $source->lastBatch()];
}

function text(string $text): array
{
    return ['type' => 'text', 'props' => ['text' => $text]];
}

it('delivers a part with its version, and fails a region the answer left out', function () {
    [$screen, $batch] = regionsInFlight();

    HttpFragmentSource::answered($batch, ['ok' => true, 'status' => 200, 'fragments' => ['stats' => ['fragment' => text('Ten sales'), 'version' => 'v1']]], 'https://a.test/_asgard/render', 'regions [stats, chart]');

    $screen->wait()->assertSee('Ten sales')->assertSee('Loading chart');

    expect(array_map(fn ($f) => [$f->region, $f->reason], $this->failures))->toBe([['chart', 'invalid']]);

    expect(ServerRegion::subscriptions()[0]['versions'] ?? null)->toBe(['stats' => 'v1']);
});

it('paints an action answer\'s other regions and follows its hints', function () {
    [$screen, $batch] = regionsInFlight();
    $source = Asgard::fakeFragmentSource();
    $asked = count($source->requests('chart'));

    $action = ServerRegion::action($screen->instance(), 'stats', 'bump', []);
    $fragments = ['chart' => ['fragment' => text('A chart'), 'version' => 'v2']];
    $refresh = [['component' => $action->component, 'region' => 'chart']];

    $fragments = ['stats' => ['fragment' => text('Bumped')], ...$fragments];

    HttpFragmentSource::answered(new RegionBatch([$action]), ['ok' => true, 'status' => 200, 'fragments' => $fragments, 'refresh' => $refresh], 'https://a.test/_asgard/render', 'action [bump]');

    $screen->wait()->assertSee('Bumped')->assertSee('A chart');

    // The hint asks for the region again after that frame.
    expect($source->requests('chart'))->toHaveCount($asked + 1);
});

it('still paints an action answer\'s other regions when its own is missing', function () {
    [$screen] = regionsInFlight();
    $action = ServerRegion::action($screen->instance(), 'stats', 'bump', []);

    HttpFragmentSource::answered(new RegionBatch([$action]), ['ok' => true, 'status' => 200, 'fragments' => ['chart' => ['fragment' => text('A chart')]]], 'https://a.test/_asgard/render', 'action [bump]');

    $screen->wait()->assertSee('A chart');

    expect($this->failures)->toHaveCount(1)
        ->and($this->failures[0])->toBeInstanceOf(ServerActionFailed::class)
        ->and($this->failures[0]->reason)->toBe('invalid');
});

// ── Requests ────────────────────────────────────────

it('sends the regions as one render request on a lane', function () {
    [, $batch] = regionsInFlight();
    holdLanes();

    (new HttpFragmentSource($this->config))->request($batch);

    expect(heldTasks())->toHaveCount(1)
        ->and($batch->regions())->toBe(['stats', 'chart'])
        ->and($batch->toArray())->toMatchArray(['regions' => ['stats', 'chart'], 'state' => ['id' => 1, 'platform' => 'unknown']]);
});

it('sends an action naming its one region', function () {
    [$screen] = regionsInFlight();
    holdLanes();
    $action = ServerRegion::action($screen->instance(), 'stats', 'bump', [2]);

    expect((new RegionBatch([$action]))->toArray())->toMatchArray([
        'regions' => ['stats'],
        'action' => ['call' => 'bump', 'args' => [2]],
    ])->not->toHaveKey('region');

    expect(fn () => new RegionBatch([$action, ...regionsInFlight()[1]->requests]))
        ->toThrow(InvalidArgumentException::class);
});

// ── Too many regions ────────────────────────────────

it('splits the regions in half and asks again when the server says there are too many', function () {
    [$screen, $batch] = regionsInFlight();
    holdLanes();

    (new HttpFragmentSource($this->config))->request($batch);
    finishTask(lastTaskId(), ['ok' => false, 'status' => 422, 'error' => 'too_many_regions']);

    expect(heldTasks())->toHaveCount(3)
        ->and($this->failures)->toBe([]);

    // One region each now, and each answered on its own.
    $tasks = array_column(array_slice(heldTasks(), 1), 'id');
    finishTask($tasks[0], ['ok' => true, 'status' => 200, 'fragments' => ['stats' => ['fragment' => text('Ten sales')]]]);
    finishTask($tasks[1], ['ok' => true, 'status' => 200, 'fragments' => ['chart' => ['fragment' => text('A chart')]]]);

    $screen->wait()->assertSee('Ten sales')->assertSee('A chart');
});

it('fails one region the server still says is too many as a server error', function () {
    [$screen, $batch] = regionsInFlight();
    $one = new RegionBatch([$batch->requests[0]]);

    HttpFragmentSource::answered($one, ['ok' => false, 'status' => 422, 'error' => 'too_many_regions'], 'https://a.test/_asgard/render', 'regions [stats]', $this->config);

    $screen->wait();

    expect(array_map(fn ($f) => [$f->region, $f->reason, $f->status], $this->failures))->toBe([['stats', 'server', 422]]);
});

it('halves an odd number of regions with the larger half first', function () {
    [, $batch] = regionsInFlight();
    $three = new RegionBatch([...$batch->requests, $batch->requests[0]]);

    [$first, $second] = $three->split();

    expect($first->requests)->toHaveCount(2)
        ->and($second->requests)->toHaveCount(1)
        ->and((new RegionBatch([$batch->requests[0]]))->split())->toBeNull();
});

it('paints every region an answer carries', function () {
    [$screen, $batch] = regionsInFlight();
    holdLanes();

    (new HttpFragmentSource($this->config))->request($batch);
    finishTask(lastTaskId(), ['ok' => true, 'status' => 200, 'fragments' => [
        'stats' => ['fragment' => text('Ten sales')],
        'chart' => ['fragment' => text('A chart')],
    ]]);

    $screen->wait()->assertSee('Ten sales')->assertSee('A chart');
});

it('fails every region of a request whose lane died', function () {
    [$screen, $batch] = regionsInFlight();
    holdLanes();

    (new HttpFragmentSource($this->config))->request($batch);
    failTask(lastTaskId(), new RuntimeException('lane died'));

    $screen->wait();

    expect(array_map(fn ($f) => [$f->region, $f->reason, $f->message], $this->failures))->toBe([
        ['stats', 'network', 'lane died'],
        ['chart', 'network', 'lane died'],
    ]);
});

it('fails every region of a request whose answer failed', function (array $result, string $reason, ?int $status) {
    [$screen, $batch] = regionsInFlight();

    HttpFragmentSource::answered($batch, $result, 'https://a.test/_asgard/render', 'regions [stats, chart]');

    $screen->wait();

    expect(array_map(fn ($f) => [$f->region, $f->reason, $f->status, $f->message], $this->failures))->toBe([
        ['stats', $reason, $status, $result['error'] ?? 'no answer'],
        ['chart', $reason, $status, $result['error'] ?? 'no answer'],
    ]);
})->with([
    'no response' => [['ok' => false, 'status' => 0, 'error' => 'cURL error 28'], 'network', null],
    'a server error' => [['ok' => false, 'status' => 500, 'error' => 'invalid_fragment'], 'server', 500],
    'not a fragments answer' => [['ok' => false, 'status' => 200, 'error' => 'bad response'], 'invalid', 200],
    'ok without fragments' => [['ok' => true, 'status' => 200], 'invalid', 200],
    'too many regions, with no config to split with' => [['ok' => false, 'status' => 422, 'error' => 'too_many_regions'], 'server', 422],
]);

it('reports a failed answer with its reason and status', function (?array $result, string $reason, ?int $status) {
    [$screen] = regionsInFlight();
    $action = ServerRegion::action($screen->instance(), 'stats', 'like', []);

    HttpFragmentSource::answered(new RegionBatch([$action]), $result, 'https://a.test/_asgard/render', 'action [like]', $this->config);

    $screen->wait();

    expect($this->failures)->toHaveCount(1)
        ->and($this->failures[0])->toBeInstanceOf(ServerActionFailed::class)
        ->and($this->failures[0]->requestId)->toBe($action->id)
        ->and($this->failures[0]->reason)->toBe($reason)
        ->and($this->failures[0]->status)->toBe($status);
})->with([
    'no response' => [['ok' => false, 'status' => 0, 'error' => 'cURL error 28'], 'network', null],
    'a server error' => [['ok' => false, 'status' => 503, 'error' => 'unsigned response'], 'server', 503],
    'a 419 after its retry' => [['ok' => false, 'status' => 419, 'error' => 'session_expired'], 'server', 419],
    'forbidden' => [['ok' => false, 'status' => 403, 'error' => 'unsigned response'], 'server', 403],
    'too many regions for its one region' => [['ok' => false, 'status' => 422, 'error' => 'too_many_regions'], 'server', 422],
    'a signed 404' => [['ok' => false, 'status' => 404, 'error' => 'unknown_action'], 'server', 404],
    'failed verification' => [['ok' => false, 'status' => 200, 'error' => 'response failed verification: bad signature'], 'invalid', 200],
    'not a fragment response' => [['ok' => false, 'status' => 200, 'error' => 'bad response'], 'invalid', 200],
    'a handshake that got nothing' => [['ok' => false, 'status' => 0, 'error' => 'Handshake failed: timeout'], 'network', null],
    'not an array at all' => [null, 'network', null],
]);

it('reports a render call that never came back as a network failure', function () {
    [$screen, $batch] = regionsInFlight();
    $one = new RegionBatch([$batch->requests[0]]);

    HttpFragmentSource::lost($one, new RuntimeException('The async task did not complete within 60 seconds.'), 'regions [stats]');

    $screen->wait();

    expect(array_map(fn ($f) => [$f->region, $f->reason, $f->status, $f->message], $this->failures))
        ->toBe([['stats', 'network', null, 'The async task did not complete within 60 seconds.']]);
});

it('ships the render call to the lane, not the request() around it', function () {
    [, $batch] = regionsInFlight();
    holdLanes();

    (new HttpFragmentSource($this->config))->request($batch);

    expect(taskPayload(lastTaskId()))->toContain('RenderCall::send($config, $body)')->not->toContain('->finished(');
});

it('is the bound source when an Asgard server is configured', function () {
    config()->set('midgard.asgard.base', 'https://asgard.test/_asgard');
    config()->set('midgard.asgard.key', 'test-key');
    app()->offsetUnset(FragmentSource::class);

    (new AsgardServiceProvider(app()))->register();

    expect(app(FragmentSource::class))->toBeInstanceOf(HttpFragmentSource::class);
});
