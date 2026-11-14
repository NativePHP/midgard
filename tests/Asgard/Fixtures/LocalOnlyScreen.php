<?php

namespace Native\Api\Client\Tests\Asgard\Fixtures;

use Illuminate\View\View;
use Native\Api\Client\Asgard\Attributes\LocalOnly;
use Native\Api\Client\Asgard\Attributes\Server;
use Native\Api\Client\Asgard\Concerns\UsesServer;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture for #[LocalOnly]: a draft and a token that stay on the
 * device next to an ordinary prop that goes, two regions so a first frame
 * goes out as a batch, and a #[Server] method.
 */
class LocalOnlyScreen extends NativeComponent
{
    use UsesServer;

    public string $range = '7d';

    #[LocalOnly]
    public string $draft = '';

    #[LocalOnly]
    public ?string $token = 'secret-token';

    public int $taps = 0;

    public function tapped(): void
    {
        $this->taps++;
    }

    public function typed(string $text): void
    {
        $this->draft = $text;
    }

    #[Server]
    public function save(): void {}

    public function render(): View
    {
        return view('local-only-screen');
    }
}
