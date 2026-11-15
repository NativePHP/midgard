<?php

use Native\Api\Client\Asgard\ServerRegion;
use Native\Api\Client\Tests\Asgard\Fixtures\MemoPatchFeedScreen;

/**
 * What one frame costs for a 1,000-row patched region: the patch, the
 * render to elements, and the serialize with subtree memo on, as a device
 * runs it. Off unless ASGARD_BENCH=1:
 *
 *     ASGARD_BENCH=1 vendor/bin/pest tests/Asgard/Bench
 */
beforeEach(function () {
    if (getenv('ASGARD_BENCH') !== '1') {
        $this->markTestSkipped('Set ASGARD_BENCH=1 to run the patch frame benchmark.');
    }

    app('view')->addLocation(__DIR__.'/../Fixtures/views');
    config(['app.debug' => false, 'midgard.asgard.cache.ttl' => 0, 'midgard.asgard.state' => []]);
    ServerRegion::flush();
});

function benchRow(int $id): array
{
    return [
        'type' => 'pressable',
        'key' => "row-{$id}",
        'class' => 'flex-row items-center gap-3 px-4 py-3 border-b border-gray-200',
        'on_press' => ['call' => 'pick', 'args' => ["row-{$id}"], 'target' => 'client'],
        'children' => [
            ['type' => 'text', 'class' => 'text-base font-semibold text-gray-900', 'props' => ['text' => "Event {$id}"]],
            ['type' => 'text', 'class' => 'text-sm text-gray-500', 'props' => ['text' => 'just now']],
        ],
    ];
}

function benchMedianUs(Closure $fn, int $runs = 41): float
{
    $samples = [];
    for ($i = 0; $i < $runs; $i++) {
        $t = hrtime(true);
        $fn($i);
        $samples[] = (hrtime(true) - $t) / 1000;
    }
    sort($samples);

    return $samples[intdiv($runs, 2)];
}

it('measures a frame of a 1,000-row region after a one-row prepend', function () {
    $screen = new MemoPatchFeedScreen;
    $tree = ['type' => 'column', 'children' => [
        ['type' => 'text', 'props' => ['text' => 'Latest']],
        ['type' => 'column', 'key' => 'items', 'children' => array_map(benchRow(...), range(1000, 1))],
    ]];
    expect(ServerRegion::applyFragment('feed', $tree, $screen))->toBeTrue();

    $frame = fn () => MemoPatchFeedScreen::frame($screen);

    $apply = new ReflectionMethod(ServerRegion::class, 'applyPatch');
    $next = 1001;
    $prepend = function () use ($apply, $screen, &$next) {
        $why = $apply->invoke(null, $screen, 'feed', 'v1', 'v1', [
            'op' => 'prepend', 'target' => 'items', 'nodes' => [benchRow($next++)], 'keep' => 1000,
        ]);
        expect($why)->toBeNull();
    };

    // Warm up: first paint, then a few patched frames.
    $frame();
    for ($i = 0; $i < 5; $i++) {
        $prepend();
        $frame();
    }

    $quiet = benchMedianUs(fn () => $frame());
    $patchOnly = benchMedianUs(fn () => $prepend());
    $patched = benchMedianUs(function () use ($prepend, $frame) {
        $prepend();
        $frame();
    });

    $last = $frame();
    $reuse = 0;
    $walk = function (array $node) use (&$walk, &$reuse) {
        $reuse += ($node['flags'] ?? 0) & 1;
        foreach ($node['children'] ?? [] as $child) {
            $walk($child);
        }
    };
    $prepend();
    $walk($frame());

    fwrite(STDERR, sprintf(
        "\n1,000 rows: frame with no change %.0f us | patch alone %.1f us | patch + frame %.0f us | REUSE markers after a prepend %d\n",
        $quiet, $patchOnly, $patched, $reuse,
    ));

    expect($last)->toBeArray();
});
