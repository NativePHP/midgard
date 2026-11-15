<?php

namespace Native\Api\Client\Asgard\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * The server redrew a region the device holds a cached copy of, and said
 * so with a signed `Asgard-Cache: invalidate` header.
 *
 * It fires before the new fragment is applied. By default the fragment is
 * applied and stored, on the screen's next turn. A listener can keep the
 * cached copy instead, on screen and in the cache, by calling ignore().
 *
 * A Laravel listener gets the event itself:
 *
 *     Event::listen(RegionCacheInvalidation::class, function ($event) {
 *         if ($event->region === 'drafts') {
 *             $event->ignore();
 *         }
 *     });
 *
 * An `#[On]` listener on the screen gets the event's properties by name, so
 * it takes the decision:
 *
 *     #[On(RegionCacheInvalidation::class, when: ['region' => 'drafts'])]
 *     public function draftsChanged(CacheDecision $decision): void
 *     {
 *         $decision->ignore();
 *     }
 *
 * `#[On]` listeners run on the screen's next turn, not inside the call that
 * fired the event. That is why the fragment waits for that turn before it
 * is applied: every listener has had its say by then. The event fires on
 * the screen the region is on, as ServerRegionFailed does, and only the
 * screen's own `#[On]` listeners hear it, not a child component's.
 *
 * A region that had no cached copy, painted or stored, does not fire this.
 * Its fragment just applies.
 */
class RegionCacheInvalidation implements BroadcastsGlobally
{
    use Dispatchable;

    public CacheDecision $decision;

    public function __construct(
        /** The region's name. */
        public string $region,
        /** The address of the component that owns the region (§4). */
        public string $component,
        /** The server's `made_at` of the copy the device holds, in milliseconds. */
        public ?int $oldMadeAt,
        /** The server's `made_at` of the new fragment, in milliseconds. */
        public ?int $newMadeAt,
        /** The new fragment, as the server sent it. */
        public array $fragment,
        /** The client-local id of the request the answer is for. */
        public string $requestId = '',
        ?CacheDecision $decision = null,
    ) {
        $this->decision = $decision ?? new CacheDecision;
    }

    /** Keep the cached copy, on screen and in the cache. */
    public function ignore(): void
    {
        $this->decision->ignore();
    }

    public function ignored(): bool
    {
        return $this->decision->ignored();
    }
}
