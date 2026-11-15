<?php

namespace Native\Api\Client\Asgard;

/**
 * One `#[Server]` call on its way out
 * (docs/ASGARD-SERVER-METHODS.md §2).
 *
 * The method name, its arguments, and a snapshot of the component's
 * public state taken AFTER the client body returned. That ordering is the
 * point: local mutations made in the body are part of the request. The
 * keys from `midgard.asgard.state` ride along in it, as they do on a
 * region request, so the server method can read the platform like any
 * other prop.
 *
 * What is NOT here is as load bearing as what is. The closure the body
 * returned never reaches this object: it is a PHP value held on the
 * device for the lifetime of the request, never serialised, inspected
 * or transmitted. No client-side logic is sent.
 *
 * `component` is the dot-notation address from §4 of the protocol doc,
 * never a class name.
 *
 * `readOnly` is the state keys the server may read but not change
 * (#[ServerReadOnly] props and #[Computed] values), sent as `read_only`.
 * `regions` is what `#[Server(render: ...)]` asked the server to redraw.
 * Both are left off the wire when empty.
 */
final class ServerRequest
{
    public function __construct(
        public readonly string $component,
        public readonly string $method,
        public readonly array $args,
        public readonly array $state,
        public readonly array $cache = [],
        public readonly array $readOnly = [],
        public readonly array $regions = [],
    ) {}

    /** The body a transport signs, encrypts and sends. */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'method' => $this->method,
            'args' => $this->args,
            'state' => $this->state,
            ...($this->readOnly === [] ? [] : ['read_only' => $this->readOnly]),
            ...($this->regions === [] ? [] : ['regions' => $this->regions]),
            ...($this->cache === [] ? [] : ['cache' => $this->cache]),
        ];
    }
}
