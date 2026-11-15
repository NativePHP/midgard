<?php

namespace Native\Api\Client\Asgard;

/**
 * One ask for one region's fragment, handed to the bound FragmentSource.
 *
 * `component` is the dot-notation address of the component that rendered
 * the region (ComponentAddress, protocol §4), which is a child component
 * when the `@server` block sits in a child's view. `state` is what that
 * component sends (ComponentState, with `midgard.asgard.state` added), kept
 * to what JSON can carry: scalars, null,
 * and arrays of those. A prop holding anything else (an object, an enum,
 * a non-finite float) is left out whole rather than half-sent.
 *
 * `id` is client-local. It identifies this request so a late answer to a
 * superseded one can be told apart and dropped, and it is not part of
 * the wire body. Nor is `componentClass`, which is here for local use
 * only: the server MUST NOT see a class name (§4).
 *
 * `cache` is the region cache's `{region: {made_at, ttl}}` for the copies
 * of this component's regions the device holds and still counts as good
 * (RegionCache). It is sent only when it has an entry.
 *
 * `reason` is `push` for a fetch a push stream signal caused, and null
 * (left off the wire) for every other.
 */
class RegionRequest
{
    public function __construct(
        public readonly string $id,
        public readonly string $component,
        public readonly string $region,
        public readonly array $state,
        public readonly ?string $componentClass = null,
        public readonly array $cache = [],
        public readonly ?string $reason = null,
    ) {}

    /** The body a transport signs, encrypts and sends. */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'region' => $this->region,
            'state' => $this->state,
            ...($this->cache === [] ? [] : ['cache' => $this->cache]),
            ...($this->reason === null ? [] : ['reason' => $this->reason]),
        ];
    }
}
