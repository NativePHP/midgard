<?php

use Laravel\SerializableClosure\SerializableClosure;
use Native\Api\Client\Asgard\HttpFragmentSource;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\Asgard\ActionRequest;
use Native\Mobile\Edge\Asgard\RegionBatch;
use Native\Mobile\Edge\Asgard\RegionRequest;
use Native\Mobile\Edge\Asgard\ServerRegion;

require_once __DIR__.'/Fixtures/AsgardRegionStandIns.php';
require_once __DIR__.'/Fixtures/AsgardServerMethodStandIns.php';

// What the transport tells the device once a render call is over: the
// fragment, or why there is none (protocol §10.2, §12.1).
beforeEach(function () {
    // Only the stand-in records. With nativephp/mobile installed, skip.
    if (! property_exists(ServerRegion::class, 'calls')) {
        $this->markTestSkipped('nativephp/mobile is installed, so the stand-in is not in use.');
    }

    ServerRegion::$calls = [];
    AsyncTask::$dispatched = [];
});

it('delivers a fragment with its chrome and version', function () {
    $request = new RegionRequest('region-1', 'promos.show', 'screen');

    HttpFragmentSource::answered($request, ['ok' => true, 'status' => 200, 'fragment' => ['type' => 'text'], 'chrome' => ['nav_bar' => []], 'version' => 'v1'], 'https://a.test/_asgard/render', 'region [screen]');

    expect(ServerRegion::$calls)->toBe([['deliver', 'region-1', ['type' => 'text'], ['nav_bar' => []], 'v1', [], []]]);
});

it('hands an action answer\'s other regions and hints to deliver', function () {
    $request = new ActionRequest('action-1', 'app.home', 'stats', 'bump');
    $fragments = ['greeting' => ['fragment' => ['type' => 'text'], 'version' => 'v2']];
    $refresh = [['component' => 'app.inbox']];

    HttpFragmentSource::answered($request, ['ok' => true, 'status' => 200, 'fragment' => ['type' => 'row'], 'fragments' => $fragments, 'refresh' => $refresh], 'https://a.test/_asgard/render', 'action [bump]');

    expect(ServerRegion::$calls)->toBe([['deliver', 'action-1', ['type' => 'row'], null, null, $fragments, $refresh]]);
});

// ── Batches ─────────────────────────────────────────

function batchOf(string ...$regions): RegionBatch
{
    return new RegionBatch(array_map(
        fn (int $i, string $region) => new RegionRequest('region-'.($i + 1), 'app.home', $region, ['id' => 1]),
        array_keys($regions),
        $regions,
    ));
}

it('sends a batch as one render request on a lane', function () {
    $config = ['url' => 'https://a.test', 'path' => '_asgard', 'key' => 'k', 'timeout' => 5, 'store' => null];

    (new HttpFragmentSource($config))->batch($batch = batchOf('stats', 'chart'));

    expect(AsyncTask::$dispatched)->toHaveCount(1);

    (AsyncTask::$dispatched[0]->finished)(['ok' => true, 'status' => 200, 'fragments' => ['stats' => ['fragment' => []]]]);
    (AsyncTask::$dispatched[0]->failed)(new RuntimeException('lane died'));

    expect(ServerRegion::$calls)->toBe([
        ['deliverBatch', ['stats', 'chart'], ['stats' => ['fragment' => []]]],
        ['failedBatch', ['stats', 'chart'], 'network', null, 'lane died'],
    ]);

    expect($batch->toArray())->toBe(['component' => 'app.home', 'regions' => ['stats', 'chart'], 'state' => ['id' => 1], 'platform' => 'ios']);
});

it('fails every region of a batch whose answer failed', function (array $result, string $reason, ?int $status) {
    HttpFragmentSource::answeredBatch(batchOf('stats', 'chart'), $result, 'https://a.test/_asgard/render', 'regions [stats, chart]');

    expect(ServerRegion::$calls)->toBe([['failedBatch', ['stats', 'chart'], $reason, $status, $result['error'] ?? 'no answer']]);
})->with([
    'no response' => [['ok' => false, 'status' => 0, 'error' => 'cURL error 28'], 'network', null],
    'a server error' => [['ok' => false, 'status' => 500, 'error' => 'invalid_fragment'], 'server', 500],
    'not a batch answer' => [['ok' => false, 'status' => 200, 'error' => 'bad response'], 'invalid', 200],
    'ok without fragments' => [['ok' => true, 'status' => 200], 'invalid', 200],
]);

it('reports a failed answer with its reason and status', function (?array $result, string $reason, ?int $status) {
    $request = new ActionRequest('action-2', 'app.home', 'stats', 'like');

    HttpFragmentSource::answered($request, $result, 'https://a.test/_asgard/render', 'action [like]');

    expect(ServerRegion::$calls)->toHaveCount(1)
        ->and(ServerRegion::$calls[0][0])->toBe('failed')
        ->and(ServerRegion::$calls[0][1])->toBe('action-2')
        ->and(ServerRegion::$calls[0][2])->toBe($reason)
        ->and(ServerRegion::$calls[0][3])->toBe($status);
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
    $request = new RegionRequest('region-3', 'promos.show', 'screen');

    HttpFragmentSource::lost($request, new RuntimeException('The async task did not complete within 60 seconds.'), 'region [screen]');

    expect(ServerRegion::$calls)->toBe([['failed', 'region-3', 'network', null, 'The async task did not complete within 60 seconds.']]);
});

it('ships the batch render to the lane, not the batch() around it', function () {
    $config = ['url' => 'https://a.test', 'path' => '_asgard', 'key' => 'k', 'timeout' => 5, 'store' => null];

    (new HttpFragmentSource($config))->batch(batchOf('stats', 'chart'));

    $code = serialize(new SerializableClosure(AsyncTask::$dispatched[0]->work));

    expect($code)->toContain('RenderCall::send($config, $body)')->not->toContain('->finished(');
});
