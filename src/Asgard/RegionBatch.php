<?php

namespace Native\Api\Client\Asgard;

/**
 * One render request on the wire (Asgard protocol §3.1): the regions of one
 * component instance, asked for together. Handed to FragmentSource::request(),
 * or built by a source from the ActionRequest it is handed.
 *
 * Every render request names `regions`, a list even for one region, so this
 * is the only body a render or action goes out with. Each region in it is
 * still its own RegionRequest, with its own id, timer and supersede rule.
 * The batch only groups them on the wire, sharing the component, state,
 * declared cache and reason of the first.
 *
 * An action names exactly one region (§10.2), so a batch with an
 * ActionRequest in it holds that request alone, and its body carries the
 * `action` member.
 *
 * The source answers with ServerRegion::deliverBatch(), or
 * ServerRegion::failedBatch() when the whole request failed. split() halves
 * it for a server that answered `422 too_many_regions`.
 */
final class RegionBatch
{
    /** @param list<RegionRequest> $requests  All for one component instance and state. */
    public function __construct(public readonly array $requests)
    {
        if (count($requests) < 1) {
            throw new \InvalidArgumentException('A batch needs at least one region request.');
        }

        if (count($requests) > 1 && array_filter($requests, fn (RegionRequest $request) => $request instanceof ActionRequest) !== []) {
            throw new \InvalidArgumentException('An action names exactly one region, so it goes out alone.');
        }
    }

    /** @return list<string> */
    public function regions(): array
    {
        return array_map(fn (RegionRequest $request) => $request->region, $this->requests);
    }

    public function component(): string
    {
        return $this->requests[0]->component;
    }

    public function state(): array
    {
        return $this->requests[0]->state;
    }

    /** The action this request carries, or null for a plain render. */
    public function action(): ?ActionRequest
    {
        return $this->requests[0] instanceof ActionRequest ? $this->requests[0] : null;
    }

    /**
     * The same regions as two smaller requests, first half then second, or
     * null when there is only one region left to split.
     *
     * @return array{0: self, 1: self}|null
     */
    public function split(): ?array
    {
        if (count($this->requests) < 2) {
            return null;
        }

        $half = intdiv(count($this->requests) + 1, 2);

        return [
            new self(array_slice($this->requests, 0, $half)),
            new self(array_slice($this->requests, $half)),
        ];
    }

    /** The body a transport signs, encrypts and sends. */
    public function toArray(): array
    {
        $first = $this->requests[0];
        $action = $this->action();

        return [
            'component' => $first->component,
            'regions' => $this->regions(),
            'state' => $first->state,
            ...($first->cache === [] ? [] : ['cache' => $first->cache]),
            ...($first->reason === null ? [] : ['reason' => $first->reason]),
            ...($action === null ? [] : ['action' => ['call' => $action->call, 'args' => $action->args]]),
        ];
    }
}
