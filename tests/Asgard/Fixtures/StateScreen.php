<?php

namespace Native\Api\Client\Tests\Asgard\Fixtures;

use Illuminate\View\View;
use Native\Api\Client\Asgard\Attributes\LocalOnly;
use Native\Api\Client\Asgard\Attributes\Server;
use Native\Api\Client\Asgard\Attributes\ServerReadOnly;
use Native\Api\Client\Asgard\Concerns\UsesServer;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture for state coming back on every answer: ordinary props, a
 * #[ServerReadOnly] one, a #[LocalOnly] one, a #[Computed] value, two
 * regions, and #[Server] methods with each kind of `render`.
 */
class StateScreen extends NativeComponent
{
    use UsesServer;

    public string $range = '7d';

    public int $count = 0;

    #[ServerReadOnly]
    public string $userId = 'u1';

    #[LocalOnly]
    public string $draft = '';

    #[Computed]
    public function label(): string
    {
        return 'count:'.$this->count;
    }

    public function bump(): void
    {
        $this->count++;
    }

    #[Server]
    public function save(): void {}

    #[Server(render: true)]
    public function saveAll(): void {}

    #[Server(render: 'stats')]
    public function saveStats(): void {}

    #[Server(render: ['feed', 'stats', 'feed'])]
    public function saveBoth(): void {}

    public function render(): View
    {
        return view('state-screen');
    }
}
