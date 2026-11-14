<?php

use Illuminate\Support\Carbon;
use Native\Api\Client\Asgard\ComponentState;
use Native\Api\Client\Asgard\ServerMethods;
use Native\Api\Client\Asgard\ServerRegion;
use Native\Api\Client\Asgard\Testing\Asgard;
use Native\Api\Client\Tests\Asgard\Fixtures\LocalOnlyChildScreen;
use Native\Api\Client\Tests\Asgard\Fixtures\LocalOnlyScreen;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;

/**
 * #[LocalOnly] props stay on the device: no path that sends state carries
 * them, a server delta cannot set them, and changing one is not a change
 * of state.
 *
 * LocalOnlyScreen draws two regions, `stats` and `feed`, so its first
 * frame asks for both in one batch.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/Fixtures/views');
    ServerRegion::flush();
    ServerMethods::flush();
    config(['app.debug' => false]);
    Carbon::setTestNow(Carbon::now());

    $this->photo = tempnam(sys_get_temp_dir(), 'local-only-');
    file_put_contents($this->photo, 'x');
});

afterEach(function () {
    ServerRegion::flush();
    ServerMethods::flush();
    Carbon::setTestNow();
    @unlink($this->photo);
});

/** Answer the first batch: `stats` with a button whose tap goes to the server. */
function answerLocalOnly($source): void
{
    $source->respondToBatch(fragments: [
        'stats' => ['fragment' => [
            'type' => 'pressable',
            'key' => 'go',
            'on_press' => ['call' => 'shuffle', 'args' => [], 'target' => 'server'],
            'children' => [['type' => 'text', 'props' => ['text' => 'Shuffle']]],
        ]],
        'feed' => ['fragment' => ['type' => 'text', 'props' => ['text' => 'Feed 1']]],
    ]);
}

it('leaves local-only props out of a batch and a single region request', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(LocalOnlyScreen::class);

    $source->assertBatched(['stats', 'feed'], 1);
    expect($source->lastBatch()->toArray()['state'])->toBe(['range' => '7d', 'taps' => 0]);

    answerLocalOnly($source);
    $screen->instance()->refreshServerRegions('feed');
    $screen->tap('tap');

    $source->assertRequested('feed', 2)->assertBatched(['stats', 'feed'], 1);
    expect($source->lastRequest('feed')->toArray()['state'])->toBe(['range' => '7d', 'taps' => 1]);
});

it('leaves local-only props out of a server action', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(LocalOnlyScreen::class);
    answerLocalOnly($source);

    $screen->tap('type')->tap('Shuffle');

    $source->assertActionSent('stats', 'shuffle', times: 1);
    expect($source->lastAction('stats')->state)->toBe(['range' => '7d', 'taps' => 0]);
});

it('leaves local-only props out of a #[Server] call and an upload', function () {
    $server = Asgard::fakeServerTransport();
    $screen = Native::test(LocalOnlyScreen::class);

    $screen->instance()->callServerMethod('save');
    $screen->instance()->uploadToServer('savePhoto', $this->photo);

    expect($server->lastRequest()->toArray()['state'])->toBe(['range' => '7d', 'taps' => 0])
        ->and($server->lastUpload()->state)->toBe(['range' => '7d', 'taps' => 0]);
});

it('leaves local-only props out of the push subscription', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(LocalOnlyScreen::class);
    answerLocalOnly($source);
    $screen->tap('tap');

    expect($source->watching())->toHaveCount(1)
        ->and($source->watching()[0]['state'])->toBe(['range' => '7d', 'taps' => 0]);
});

it('does not fetch regions again or resubscribe when a local-only prop changes', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(LocalOnlyScreen::class);
    answerLocalOnly($source);
    $screen->tap('tap');
    $watching = $source->watching();

    $screen->tap('type')->set('token', 'rotated');

    expect($screen->get('draft'))->toBe('hello');
    $source->assertBatched(['stats', 'feed'], 1)->assertRequested('stats', 1)->assertRequested('feed', 1)->assertWatched(1);
    expect($source->watching())->toBe($watching);
});

it('ignores a delta that names a local-only prop, logs it, and applies the rest', function () {
    $server = Asgard::fakeServerTransport();
    $screen = Native::test(LocalOnlyScreen::class);
    $screen->instance()->draft = 'mine';

    $debugLogging = new ReflectionProperty(NativeRouter::class, 'debugLogging');
    $debugLogging->setValue(null, true);
    $log = storage_path('logs/edge-nav.log');
    @mkdir(dirname($log), 0777, true);
    @unlink($log);

    try {
        $screen->instance()->callServerMethod('save');
        expect($server->resolve(delta: ['draft' => 'theirs', 'token' => null, 'range' => '30d']))->toBeTrue();
    } finally {
        $debugLogging->setValue(null, null);
    }

    expect($screen->get('draft'))->toBe('mine')
        ->and($screen->get('token'))->toBe('secret-token')
        ->and($screen->get('range'))->toBe('30d')
        ->and(file_get_contents($log))
        ->toContain('delta prop [draft] is #[LocalOnly]')
        ->toContain('delta prop [token] is #[LocalOnly]');

    @unlink($log);
});

it('keeps a parent\'s local-only props local in a subclass, even one it declares again', function () {
    $server = Asgard::fakeServerTransport();
    $screen = Native::test(LocalOnlyChildScreen::class);

    $screen->instance()->callServerMethod('save');

    expect($server->lastRequest()->state)->toBe(['mode' => 'child', 'range' => '7d', 'taps' => 0])
        ->and(ComponentState::isLocalOnly(LocalOnlyChildScreen::class, 'draft'))->toBeTrue()
        ->and(ComponentState::isLocalOnly(LocalOnlyChildScreen::class, 'token'))->toBeTrue()
        ->and(ComponentState::isLocalOnly(LocalOnlyChildScreen::class, 'pin'))->toBeTrue();

    $server->resolve(delta: ['token' => 'theirs', 'pin' => '0000', 'mode' => 'parent']);

    expect($screen->get('token'))->toBe('child-token')
        ->and($screen->get('pin'))->toBe('1234')
        ->and($screen->get('mode'))->toBe('parent');
});
