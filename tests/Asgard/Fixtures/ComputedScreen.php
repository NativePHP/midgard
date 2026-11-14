<?php

namespace Native\Api\Client\Tests\Asgard\Fixtures;

use Illuminate\View\View;
use Native\Api\Client\Asgard\Attributes\LocalOnly;
use Native\Api\Client\Asgard\Attributes\Server;
use Native\Api\Client\Asgard\Concerns\UsesServer;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture for #[Computed] values in state: two that are sent (one
 * protected, which views can still read), one kept local, two regions so a
 * first frame goes out as a batch, and a #[Server] method.
 */
class ComputedScreen extends NativeComponent
{
    use UsesServer;

    public static int $labelCalls = 0;

    public string $range = '7d';

    public int $taps = 0;

    #[Computed]
    public function label(): string
    {
        static::$labelCalls++;

        return $this->range.':'.$this->taps;
    }

    #[Computed]
    protected function doubled(): int
    {
        return $this->taps * 2;
    }

    #[LocalOnly]
    #[Computed]
    public function secret(): string
    {
        return 'shh';
    }

    public function tapped(): void
    {
        $this->taps++;
    }

    #[Server]
    public function save(): void {}

    public function render(): View
    {
        return view('computed-screen');
    }
}
