<?php

namespace Native\Api\Client\Asgard\Events;

/**
 * What to do with a fragment that replaces a cached copy, carried by
 * RegionCacheInvalidation. It starts as apply. Call ignore() to keep the
 * copy on screen and in the cache instead.
 *
 * It is an object, so an `#[On]` listener can take it as a parameter and
 * change the one decision every listener shares.
 */
final class CacheDecision
{
    private bool $ignored = false;

    /** Keep the cached copy. The new fragment is dropped. */
    public function ignore(): void
    {
        $this->ignored = true;
    }

    /** Apply the new fragment after all, undoing an earlier ignore(). */
    public function apply(): void
    {
        $this->ignored = false;
    }

    public function ignored(): bool
    {
        return $this->ignored;
    }
}
