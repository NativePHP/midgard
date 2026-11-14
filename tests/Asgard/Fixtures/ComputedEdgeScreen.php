<?php

namespace Native\Api\Client\Tests\Asgard\Fixtures;

use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Native\Api\Client\Asgard\Attributes\Server;
use Native\Api\Client\Asgard\Concerns\UsesServer;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Edge\NativeComponent;

/**
 * Computed values that cannot all be sent: one throws, one is a closure,
 * one an object, one hidden by a prop of the same name, next to one that
 * is fine. None is read by the view.
 */
class ComputedEdgeScreen extends NativeComponent
{
    use UsesServer;

    public int $n = 1;

    #[Computed]
    public function broken(): int
    {
        throw new \RuntimeException('no data yet');
    }

    #[Computed]
    public function handler(): \Closure
    {
        return fn () => $this->n;
    }

    #[Computed]
    public function when(): Carbon
    {
        return Carbon::now();
    }

    #[Computed]
    public function n(): int
    {
        return 99;
    }

    #[Computed]
    public function next(): int
    {
        return $this->n + 1;
    }

    #[Server]
    public function save(): void {}

    public function render(): View
    {
        return view('computed-edge-screen');
    }
}
