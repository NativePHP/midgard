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

// What the transport tells the device once a render call is over: the
// fragment, or why there is none (protocol §10.2, §12.1). The requests are
// real ones, made by a screen's first frame, so what HttpFragmentSource
// hands to ServerRegion shows on that screen.
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/Fixtures/views');

    $this->config = ['url' => 'https://a.test', 'path' => '_asgard', 'key' => 'k', 'timeout' => 5, 'store' => null];

    $this->failures = [];
    Event::listen(ServerRegionFailed::class, fn ($event) => $this->failures[] = $event);
    Event::listen(ServerActionFailed::class, fn ($event) => $this->failures[] = $event);
});

/**
 * A screen whose two regions were asked for in one batch, held by the fake
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

it('delivers a fragment with its version', function () {
    [$screen, $batch] = regionsInFlight();
    $request = $batch->requests[0];

    HttpFragmentSource::answered($request, ['ok' => true, 'status' => 200, 'fragment' => text('Ten sales'), 'version' => 'v1'], 'https://a.test/_asgard/render', 'region [stats]');

    $screen->wait()->assertSee('Ten sales')->assertSee('Loading chart');

    expect(ServerRegion::subscriptions()[0]['versions'] ?? null)->toBe(['stats' => 'v1']);
});

it('hands an action answer\'s other regions and hints to deliver', function () {
    [$screen, $batch] = regionsInFlight();
    $source = Asgard::fakeFragmentSource();
    $asked = count($source->requests('chart'));

    $action = ServerRegion::action($screen->instance(), 'stats', 'bump', []);
    $fragments = ['chart' => ['fragment' => text('A chart'), 'version' => 'v2']];
    $refresh = [['component' => $action->component, 'region' => 'chart']];

    HttpFragmentSource::answered($action, ['ok' => true, 'status' => 200, 'fragment' => text('Bumped'), 'fragments' => $fragments, 'refresh' => $refresh], 'https://a.test/_asgard/render', 'action [bump]');

    $screen->wait()->assertSee('Bumped')->assertSee('A chart');

    // The hint asks for the region again after that frame.
    expect($source->requests('chart'))->toHaveCount($asked + 1);
});

// ── Batches ─────────────────────────────────────────

it('sends a batch as one render request on a lane', function () {
    [, $batch] = regionsInFlight();
    holdLanes();

    (new HttpFragmentSource($this->config))->batch($batch);

    expect(heldTasks())->toHaveCount(1)
        ->and($batch->regions())->toBe(['stats', 'chart'])
        ->and($batch->toArray())->toMatchArray(['regions' => ['stats', 'chart'], 'state' => ['id' => 1]]);
});

it('paints every region a batch answer carries', function () {
    [$screen, $batch] = regionsInFlight();
    holdLanes();

    (new HttpFragmentSource($this->config))->batch($batch);
    finishTask(lastTaskId(), ['ok' => true, 'status' => 200, 'fragments' => [
        'stats' => ['fragment' => text('Ten sales')],
        'chart' => ['fragment' => text('A chart')],
    ]]);

    $screen->wait()->assertSee('Ten sales')->assertSee('A chart');
});

it('fails every region of a batch whose lane died', function () {
    [$screen, $batch] = regionsInFlight();
    holdLanes();

    (new HttpFragmentSource($this->config))->batch($batch);
    failTask(lastTaskId(), new RuntimeException('lane died'));

    $screen->wait();

    expect(array_map(fn ($f) => [$f->region, $f->reason, $f->message], $this->failures))->toBe([
        ['stats', 'network', 'lane died'],
        ['chart', 'network', 'lane died'],
    ]);
});

it('fails every region of a batch whose answer failed', function (array $result, string $reason, ?int $status) {
    [$screen, $batch] = regionsInFlight();

    HttpFragmentSource::answeredBatch($batch, $result, 'https://a.test/_asgard/render', 'regions [stats, chart]');

    $screen->wait();

    expect(array_map(fn ($f) => [$f->region, $f->reason, $f->status, $f->message], $this->failures))->toBe([
        ['stats', $reason, $status, $result['error'] ?? 'no answer'],
        ['chart', $reason, $status, $result['error'] ?? 'no answer'],
    ]);
})->with([
    'no response' => [['ok' => false, 'status' => 0, 'error' => 'cURL error 28'], 'network', null],
    'a server error' => [['ok' => false, 'status' => 500, 'error' => 'invalid_fragment'], 'server', 500],
    'not a batch answer' => [['ok' => false, 'status' => 200, 'error' => 'bad response'], 'invalid', 200],
    'ok without fragments' => [['ok' => true, 'status' => 200], 'invalid', 200],
]);

it('reports a failed answer with its reason and status', function (?array $result, string $reason, ?int $status) {
    [$screen] = regionsInFlight();
    $action = ServerRegion::action($screen->instance(), 'stats', 'like', []);

    HttpFragmentSource::answered($action, $result, 'https://a.test/_asgard/render', 'action [like]');

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
    'a signed 404' => [['ok' => false, 'status' => 404, 'error' => 'unknown_action'], 'server', 404],
    'failed verification' => [['ok' => false, 'status' => 200, 'error' => 'response failed verification: bad signature'], 'invalid', 200],
    'not a fragment response' => [['ok' => false, 'status' => 200, 'error' => 'bad response'], 'invalid', 200],
    'a handshake that got nothing' => [['ok' => false, 'status' => 0, 'error' => 'Handshake failed: timeout'], 'network', null],
    'not an array at all' => [null, 'network', null],
]);

it('reports a render call that never came back as a network failure', function () {
    [$screen, $batch] = regionsInFlight();
    $request = $batch->requests[0];

    HttpFragmentSource::lost($request, new RuntimeException('The async task did not complete within 60 seconds.'), 'region [stats]');

    $screen->wait();

    expect(array_map(fn ($f) => [$f->region, $f->reason, $f->status, $f->message], $this->failures))
        ->toBe([['stats', 'network', null, 'The async task did not complete within 60 seconds.']]);
});

it('ships the batch render to the lane, not the batch() around it', function () {
    [, $batch] = regionsInFlight();
    holdLanes();

    (new HttpFragmentSource($this->config))->batch($batch);

    expect(taskPayload(lastTaskId()))->toContain('RenderCall::send($config, $body)')->not->toContain('->finished(');
});

it('is the bound source when an Asgard server is configured', function () {
    config()->set('midgard.asgard.url', 'https://asgard.test');
    config()->set('midgard.asgard.key', 'test-key');
    app()->offsetUnset(FragmentSource::class);

    (new AsgardServiceProvider(app()))->register();

    expect(app(FragmentSource::class))->toBeInstanceOf(HttpFragmentSource::class);
});
