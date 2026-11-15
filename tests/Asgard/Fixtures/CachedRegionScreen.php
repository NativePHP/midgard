<?php

namespace Native\Api\Client\Tests\Asgard\Fixtures;

use Illuminate\View\View;
use Native\Api\Client\Asgard\Attributes\LocalOnly;
use Native\Api\Client\Asgard\Attributes\Server;
use Native\Api\Client\Asgard\Concerns\UsesServer;
use Native\Api\Client\Asgard\Events\CacheDecision;
use Native\Api\Client\Asgard\Events\RegionCacheInvalidation;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture for the region cache: `stats` on the config TTL, `news`
 * with its own `cacheTtl:`, an #[On] listener that can keep the cached
 * copy of `stats`, and a #[Server] method.
 */
class CachedRegionScreen extends NativeComponent
{
    use UsesServer;

    public string $range = '7d';

    #[LocalOnly]
    public ?int $newsTtl = 0;

    #[LocalOnly]
    public bool $keepStats = false;

    /** @var list<array{oldMadeAt: ?int, newMadeAt: ?int}> */
    #[LocalOnly]
    public array $invalidations = [];

    #[On(RegionCacheInvalidation::class, when: ['region' => 'stats'])]
    public function statsInvalidated(CacheDecision $decision, ?int $oldMadeAt, ?int $newMadeAt): void
    {
        $this->invalidations[] = compact('oldMadeAt', 'newMadeAt');

        if ($this->keepStats) {
            $decision->ignore();
        }
    }

    public function pick(string $range): void
    {
        $this->range = $range;
    }

    public function refreshStats(): void
    {
        $this->refreshServerRegions('stats');
    }

    #[Server]
    public function save(): void {}

    public function render(): View
    {
        return view('cached-region-screen');
    }
}
