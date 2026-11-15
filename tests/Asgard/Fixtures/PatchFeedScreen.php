<?php

namespace Native\Api\Client\Tests\Asgard\Fixtures;

use Illuminate\View\View;
use Native\Api\Client\Asgard\Attributes\LocalOnly;
use Native\Api\Client\Asgard\Concerns\UsesServer;
use Native\Mobile\Attributes\ClientAction;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture for region patches: one `feed` region holding a keyed
 * list, and a #[ClientAction] its items can call.
 */
class PatchFeedScreen extends NativeComponent
{
    use UsesServer;

    public string $tab = 'all';

    /** @var list<string> */
    #[LocalOnly]
    public array $picked = [];

    #[ClientAction]
    public function pick(string $id): void
    {
        $this->picked[] = $id;
    }

    public function render(): View
    {
        return view('patch-feed-screen');
    }
}
