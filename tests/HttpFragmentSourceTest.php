<?php

use Native\Api\Client\Asgard\HttpFragmentSource;
use Native\Mobile\Edge\Asgard\ActionRequest;
use Native\Mobile\Edge\Asgard\RegionRequest;
use Native\Mobile\Edge\Asgard\ServerRegion;

require_once __DIR__.'/Fixtures/AsgardRegionStandIns.php';

// What the transport tells the device once a render call is over: the
// fragment, or why there is none (protocol §10.2, §12.1).
beforeEach(function () {
    // Only the stand-in records. With nativephp/mobile installed, skip.
    if (! property_exists(ServerRegion::class, 'calls')) {
        $this->markTestSkipped('nativephp/mobile is installed, so the stand-in is not in use.');
    }

    ServerRegion::$calls = [];
});

it('delivers a fragment with its chrome and version', function () {
    $request = new RegionRequest('region-1', 'promos.show', 'screen');

    HttpFragmentSource::answered($request, ['ok' => true, 'status' => 200, 'fragment' => ['type' => 'text'], 'chrome' => ['nav_bar' => []], 'version' => 'v1'], 'https://a.test/_asgard/render', 'region [screen]');

    expect(ServerRegion::$calls)->toBe([['deliver', 'region-1', ['type' => 'text'], ['nav_bar' => []], 'v1']]);
});

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
