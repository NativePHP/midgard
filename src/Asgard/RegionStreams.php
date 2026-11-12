<?php

namespace Native\Api\Client\Asgard;

use Closure;
use Illuminate\Support\Facades\Log;
use Native\Api\Client\Asgard\Events\StreamMessage;

/**
 * Keeps one Server-Sent Events stream open per component, so the server can
 * push fragments to its regions.
 *
 * Each region that asks for a fragment is added to its component's stream.
 * The stream is (re)opened when a new region joins or the state the server
 * renders from changes, because the subscription carries both. The stream
 * itself lives in native code (Stream), so PHP never blocks on it.
 *
 * A pushed `fragment` event is handed to the pusher, which in an app is
 * ServerRegion::push(). That only paints regions a live screen declared, so a
 * server cannot use a push to paint anywhere else.
 */
class RegionStreams
{
    public const PREFIX = 'asgard:';

    /** @var array<string, array{regions: list<string>, state: array, platform: string}> */
    protected array $subscriptions = [];

    /**
     * @param  Closure(string $component, string $region, array $fragment): int  $pusher
     */
    public function __construct(
        protected string $endpoint,
        protected Closure $pusher,
    ) {}

    /**
     * @param  object{component: string, region: string, state: array, platform: string}  $request
     */
    public function subscribe(object $request): void
    {
        $current = $this->subscriptions[$request->component] ?? null;
        $regions = $current['regions'] ?? [];

        if (! in_array($request->region, $regions, true)) {
            $regions[] = $request->region;
        }

        $next = ['regions' => $regions, 'state' => $request->state, 'platform' => $request->platform];

        if ($current === $next) {
            return;
        }

        $this->subscriptions[$request->component] = $next;

        Stream::open(self::PREFIX.$request->component, $this->endpoint, [
            'Accept' => 'text/event-stream',
            'Asgard-Protocol' => '1',
            'X-Asgard-Method' => 'QUERY',
        ], [
            'protocol' => 1,
            'component' => $request->component,
            'regions' => $regions,
            'state' => $request->state,
            'platform' => $request->platform,
        ]);
    }

    public function receive(StreamMessage $message): void
    {
        if (! str_starts_with($message->id, self::PREFIX) || $message->event !== 'fragment') {
            return;
        }

        $component = substr($message->id, strlen(self::PREFIX));

        if (! isset($this->subscriptions[$component])) {
            return;
        }

        $payload = json_decode($message->data, true);
        $region = is_array($payload) ? ($payload['region'] ?? null) : null;

        if (! is_string($region) || ! is_array($payload['fragment'] ?? null)
            || ! in_array($region, $this->subscriptions[$component]['regions'], true)) {
            Log::warning("Asgard: ignored a pushed event for [{$component}] that is not a fragment for a subscribed region");

            return;
        }

        ($this->pusher)($component, $region, $payload['fragment']);
    }

    public function close(): void
    {
        foreach (array_keys($this->subscriptions) as $component) {
            Stream::close(self::PREFIX.$component);
        }

        $this->subscriptions = [];
    }

    /** @return array<string, array{regions: list<string>, state: array, platform: string}> */
    public function subscriptions(): array
    {
        return $this->subscriptions;
    }
}
