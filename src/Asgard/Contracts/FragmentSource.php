<?php

namespace Native\Api\Client\Asgard\Contracts;

use Native\Api\Client\Asgard\ActionRequest;
use Native\Api\Client\Asgard\RegionBatch;

/**
 * Where a region's fragment comes from (Asgard protocol §3, §8).
 *
 * The client asks once a region has painted its placeholder, and again
 * when a component calls refreshServerRegions() (from UsesServer).
 * request() is fire and forget: it must return quickly, because it runs
 * on the UI thread at the end of a frame. An implementation does its
 * work elsewhere (an AsyncTask, typically) and delivers later, from an
 * AsyncTask `->finished()` callback or anywhere else on the UI thread.
 * Do not call `$this->receiveFragment()` there: it comes from the
 * UsesServer trait, and the screen the callback is bound to may not use
 * it.
 *
 * request() gets a RegionBatch: the regions of one component instance
 * asked for at the same moment, one or more, sharing one state. It is one
 * request on the wire, with `regions` as a list even for one region. The
 * answer is a `fragments` map, which goes to
 * `ServerRegion::deliverBatch($batch, $fragments)`. Each region in it is
 * still its own RegionRequest, with its own timer and failure, and a
 * region the map left out fails with reason `invalid`.
 *
 * A request that fails as a whole is
 * `ServerRegion::failedBatch($batch, $reason, $status)`, where the reason
 * is ServerRegion::NETWORK, SERVER or INVALID. A region keeps its
 * placeholder, or the last fragment it painted, which is the intended
 * offline state rather than an error. A server screen still waiting for
 * its body gives up and leaves, and an action fires ServerActionFailed.
 * Never answering is allowed too: a server screen and an action then
 * fail when their timeout passes.
 *
 * action() is a tap inside a fragment whose callback targets the server
 * (§10). It is fire and forget in the same way. It goes out as a
 * RegionBatch of the one ActionRequest, whose body carries `action`, and
 * its answer goes to the same deliverBatch(), with the answer's `refresh`
 * hints: `deliverBatch($batch, $fragments, refresh: $refresh)`. Parts for
 * regions other than the action's own are painted as inline refreshes.
 *
 * watch() is the push stream (§8.1). It gets the whole set of
 * subscriptions the device wants, every time the set changes: an empty
 * list means close the stream. Each is
 * `['id', 'component', 'regions', 'state', 'versions']`. A source that
 * streams opens one stream for the set. It hands each signal to
 * `ServerRegion::signal()` and each shared region's fragment to
 * `ServerRegion::push()`, with the subscription's address and state. A
 * source that does not stream ignores it.
 */
interface FragmentSource
{
    public function request(RegionBatch $batch): void;

    public function action(ActionRequest $request): void;

    /**
     * @param  list<array{id: string, component: string, regions: list<string>, state: array, versions: array<string, string>}>  $subscriptions
     */
    public function watch(array $subscriptions): void;
}
