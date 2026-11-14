<?php

use Illuminate\Support\Carbon;
use Native\Api\Client\Asgard\ComponentState;
use Native\Api\Client\Asgard\ServerMethods;
use Native\Api\Client\Asgard\ServerRegion;
use Native\Api\Client\Asgard\Testing\Asgard;
use Native\Api\Client\Tests\Asgard\Fixtures\ComputedChildScreen;
use Native\Api\Client\Tests\Asgard\Fixtures\ComputedEdgeScreen;
use Native\Api\Client\Tests\Asgard\Fixtures\ComputedScreen;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;

/**
 * #[Computed] values go up with a component's state, under the name its
 * views read them by, worked out afresh for each send. A delta cannot set
 * one, #[LocalOnly] keeps one back, and one that throws or cannot be sent
 * is left out without breaking the send.
 *
 * ComputedScreen draws two regions, `stats` and `feed`, so its first
 * frame asks for both in one batch.
 */
beforeEach(function () {
    // Only the component's own state here. ConfiguredStateTest covers midgard.asgard.state.
    config(['midgard.asgard.state' => []]);
    app('view')->addLocation(__DIR__.'/Fixtures/views');
    ServerRegion::flush();
    ServerMethods::flush();
    config(['app.debug' => false]);
    Carbon::setTestNow(Carbon::now());
    ComputedScreen::$labelCalls = 0;

    $this->photo = tempnam(sys_get_temp_dir(), 'computed-');
    file_put_contents($this->photo, 'x');
});

afterEach(function () {
    ServerRegion::flush();
    ServerMethods::flush();
    Carbon::setTestNow();
    @unlink($this->photo);
});

/** Answer the first batch: `stats` with a button whose tap goes to the server. */
function answerComputed($source): void
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

/** Run $fn with NativeRouter's debug log on, and give back what it logged. */
function computedDebugLog(Closure $fn): string
{
    $debugLogging = new ReflectionProperty(NativeRouter::class, 'debugLogging');
    $debugLogging->setValue(null, true);
    $log = storage_path('logs/edge-nav.log');
    @mkdir(dirname($log), 0777, true);
    @unlink($log);

    try {
        $fn();
    } finally {
        $debugLogging->setValue(null, null);
    }

    $logged = (string) @file_get_contents($log);
    @unlink($log);

    return $logged;
}

it('sends computed values in a batch and a single region request', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(ComputedScreen::class);

    $source->assertBatched(['stats', 'feed'], 1);
    expect($source->lastBatch()->toArray()['state'])
        ->toBe(['range' => '7d', 'taps' => 0, 'label' => '7d:0', 'doubled' => 0]);

    answerComputed($source);
    $screen->instance()->refreshServerRegions('feed');

    $source->assertRequested('feed', 1);
    expect($source->lastRequest('feed')->toArray()['state'])
        ->toBe(['range' => '7d', 'taps' => 0, 'label' => '7d:0', 'doubled' => 0]);
});

it('sends computed values with a server action', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(ComputedScreen::class);
    answerComputed($source);

    $screen->tap('tap')->tap('Shuffle');

    $source->assertActionSent('stats', 'shuffle', times: 1);
    expect($source->lastAction('stats')->state)
        ->toBe(['range' => '7d', 'taps' => 1, 'label' => '7d:1', 'doubled' => 2]);
});

it('sends computed values with a #[Server] call and an upload', function () {
    $server = Asgard::fakeServerTransport();
    $screen = Native::test(ComputedScreen::class);

    $screen->instance()->callServerMethod('save');
    $screen->instance()->uploadToServer('savePhoto', $this->photo);

    $state = ['range' => '7d', 'taps' => 0, 'label' => '7d:0', 'doubled' => 0];

    expect($server->lastRequest()->toArray()['state'])->toBe($state)
        ->and($server->lastUpload()->state)->toBe($state);
});

it('sends computed values in the push subscription', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(ComputedScreen::class);
    answerComputed($source);
    $screen->tap('tap');

    expect($source->watching())->toHaveCount(1)
        ->and($source->watching()[0]['state'])
        ->toBe(['range' => '7d', 'taps' => 0, 'label' => '7d:0', 'doubled' => 0]);
});

it('works a computed value out afresh for each send, without touching the render memo', function () {
    $server = Asgard::fakeServerTransport();
    $screen = Native::test(ComputedScreen::class);
    $component = $screen->instance();

    // The render read it, so the memo holds 7d:0.
    expect($component->label)->toBe('7d:0');

    // Changed with no render in between: the memo still says 7d:0.
    $component->range = '30d';
    $before = ComputedScreen::$labelCalls;
    $component->callServerMethod('save');

    expect($server->lastRequest()->state['label'])->toBe('30d:0')
        ->and(ComputedScreen::$labelCalls)->toBeGreaterThan($before)
        ->and($component->label)->toBe('7d:0');

    $component->range = '90d';
    $before = ComputedScreen::$labelCalls;
    $component->callServerMethod('save');

    expect($server->lastRequest()->state['label'])->toBe('90d:0')
        ->and(ComputedScreen::$labelCalls)->toBeGreaterThan($before);
});

it('ignores a delta that names a computed value, logs it, and applies the rest', function () {
    $server = Asgard::fakeServerTransport();
    $screen = Native::test(ComputedScreen::class);

    $logged = computedDebugLog(function () use ($screen, $server) {
        $screen->instance()->callServerMethod('save');
        expect($server->resolve(delta: ['label' => 'theirs', 'doubled' => 40, 'secret' => 'x', 'range' => '30d']))->toBeTrue();
    });

    // Nothing was written into the memo, and the next send works it out
    // from the range the delta set.
    $screen->instance()->callServerMethod('save');

    expect($screen->get('range'))->toBe('30d')
        ->and($screen->instance()->label)->not->toBe('theirs')
        ->and($server->lastRequest()->state['label'])->toBe('30d:0')
        ->and($logged)
        ->toContain('delta prop [label] is #[Computed]')
        ->toContain('delta prop [doubled] is #[Computed]')
        ->toContain('delta prop [secret] is #[LocalOnly]');
});

it('keeps a local-only computed value off the wire, in a subclass too', function () {
    $server = Asgard::fakeServerTransport();
    $screen = Native::test(ComputedChildScreen::class);

    $screen->instance()->callServerMethod('save');

    expect($server->lastRequest()->state)->not->toHaveKey('secret')
        ->and(ComponentState::isLocalOnly(ComputedScreen::class, 'secret'))->toBeTrue()
        ->and(ComponentState::isLocalOnly(ComputedChildScreen::class, 'secret'))->toBeTrue()
        ->and(ComponentState::isComputed(ComputedChildScreen::class, 'secret'))->toBeFalse()
        ->and(ComponentState::isComputed(ComputedChildScreen::class, 'label'))->toBeTrue();
});

it('leaves out a computed value that throws or cannot be sent, and still sends', function () {
    $server = Asgard::fakeServerTransport();
    $source = Asgard::fakeFragmentSource();

    $logged = computedDebugLog(function () use (&$screen) {
        $screen = Native::test(ComputedEdgeScreen::class);
        $screen->instance()->callServerMethod('save');
    });

    // An object goes as an object prop would: encoded by a call, dropped
    // from a region request, which only carries plain values.
    expect($server->lastRequest()->state)->toEqual(['n' => 1, 'when' => Carbon::now(), 'next' => 2])
        ->and($source->lastRequest('stats')->toArray()['state'])->toBe(['n' => 1, 'next' => 2])
        ->and($logged)
        ->toContain('#[Computed] [broken] on '.ComputedEdgeScreen::class.' threw (no data yet)')
        ->toContain('#[Computed] [handler] on '.ComputedEdgeScreen::class.' returned a Closure');
});
