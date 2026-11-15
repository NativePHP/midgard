<?php

namespace Native\Api\Client\Asgard;

/**
 * A tap inside a region's fragment whose callback targets the server
 * (Asgard protocol §10), handed to FragmentSource::action().
 *
 * It is a RegionRequest for the same region, carrying the state the
 * component had when the tap landed, plus the callback's `call` and
 * `args`. On the wire it is a render request that names this one region,
 * the action's own region, in `regions`, plus an `action` member
 * (RegionBatch builds the body). The server runs the action and answers
 * with a `fragments` map: the action's own region, and any other regions
 * of the component it redrew. That goes back through
 * ServerRegion::deliverBatch() like any other answer. Sending one
 * supersedes a region request still in flight, so an older render cannot
 * land on top of the action's.
 *
 * `call` and `args` are what the fragment's callback slot said, with
 * the control's value (a text field's text, a toggle's state) appended
 * to `args` for `on_change` and `on_submit`.
 */
class ActionRequest extends RegionRequest
{
    public function __construct(
        string $id,
        string $component,
        string $region,
        array $state,
        public readonly string $call,
        public readonly array $args = [],
        ?string $componentClass = null,
        array $cache = [],
    ) {
        parent::__construct($id, $component, $region, $state, $componentClass, $cache);
    }
}
