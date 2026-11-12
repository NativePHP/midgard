<?php

namespace Native\Api\Client\Asgard;

use Closure;
use Illuminate\Support\Facades\Log;
use Native\Api\Client\Asgard\Events\StreamClosed;
use Native\Api\Client\Asgard\Events\StreamMessage;
use Native\Api\Client\Asgard\Events\StreamOpened;
use Native\Api\Client\Asgard\Security\SecurityException;
use Native\Api\Client\Asgard\Security\Wire;

/**
 * Keeps one Server-Sent Events stream open per component, so the server can
 * push fragments to its regions.
 *
 * Each region that got a fragment is added to its component's stream. The
 * stream is (re)opened when a new region joins or the state the server
 * renders from changes, because the subscription carries both. The stream
 * itself lives in native code (Stream), so PHP never blocks on it.
 *
 * Every opening request is signed and encrypted here, with the current
 * session (protocol §8.1). Native code never reconnects on its own with the
 * old headers, whose signature would soon expire: it waits out its backoff
 * and fires StreamClosed with `reconnect`, and this class opens the stream
 * again with a fresh signature. A 419 gets one new handshake (off the UI
 * lane) and one more try. Anything else that stops the stream ends the
 * subscription until a region asks again.
 *
 * Every pushed event must verify against the request that opened its stream
 * and decrypt before it reaches the pusher. One that fails is logged and
 * dropped. The pusher, ServerRegion::push() in an app, only paints regions a
 * live screen declared, so a server cannot use a push to paint elsewhere.
 */
class RegionStreams
{
    public const PREFIX = 'asgard:';

    /**
     * @var array<string, array{regions: list<string>, state: array, platform: string, session: ?Session, signature: ?string, retried: bool}>
     */
    protected array $subscriptions = [];

    /**
     * @param  Closure(string $component, string $region, array $fragment, ?array $chrome): int  $pusher
     * @param  (Closure(Closure(bool): void): void)|null  $refresh  Gets a new session off the UI lane, then calls back.
     *                                                              Without one, the handshake runs inline (tests).
     */
    public function __construct(
        protected AsgardClient $client,
        protected Closure $pusher,
        protected ?Closure $refresh = null,
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

        if ($current !== null && array_intersect_key($current, $next) === $next) {
            return;
        }

        $this->subscriptions[$request->component] = $next + ['session' => null, 'signature' => null, 'retried' => false];

        $this->open($request->component);
    }

    public function receive(StreamMessage $message): void
    {
        $component = $this->component($message->id);
        $subscription = $component === null ? null : ($this->subscriptions[$component] ?? null);

        if ($subscription === null || $subscription['session'] === null || $message->event !== 'fragment') {
            return;
        }

        $session = $subscription['session'];

        try {
            $plaintext = Wire::openEvent(
                $session->keys, $session->id, $subscription['signature'],
                (string) $message->lastEventId, $message->event, $message->data,
            );
        } catch (SecurityException $e) {
            Log::warning("Asgard: dropped a pushed event for [{$component}] that failed verification: ".$e->getMessage());

            return;
        }

        $payload = json_decode($plaintext, true);
        $region = is_array($payload) ? ($payload['region'] ?? null) : null;

        if (! is_string($region) || ! is_array($payload['fragment'] ?? null)
            || ! in_array($region, $subscription['regions'], true)) {
            Log::warning("Asgard: ignored a pushed event for [{$component}] that is not a fragment for a subscribed region");

            return;
        }

        // A server screen's event carries its chrome too, checked by the screen.
        $chrome = $payload['chrome'] ?? null;

        ($this->pusher)($component, $region, $payload['fragment'], is_array($chrome) ? $chrome : null);
    }

    public function opened(StreamOpened $event): void
    {
        $component = $this->component($event->id);

        if ($component !== null && isset($this->subscriptions[$component])) {
            $this->subscriptions[$component]['retried'] = false;
        }
    }

    public function closed(StreamClosed $event): void
    {
        $component = $this->component($event->id);
        $subscription = $component === null ? null : ($this->subscriptions[$component] ?? null);

        if ($subscription === null) {
            return;
        }

        if ($event->reconnect) {
            $this->open($component);

            return;
        }

        if ($event->status === 419 && ! $subscription['retried']) {
            $this->subscriptions[$component]['retried'] = true;
            $this->refresh($component);

            return;
        }

        Log::warning("Asgard: the stream for [{$component}] stopped", ['error' => $event->error, 'status' => $event->status]);

        unset($this->subscriptions[$component]);
    }

    public function close(): void
    {
        $components = array_keys($this->subscriptions);
        $this->subscriptions = [];

        foreach ($components as $component) {
            Stream::close(self::PREFIX.$component);
        }
    }

    /** @return array<string, array{regions: list<string>, state: array, platform: string, session: ?Session, signature: ?string, retried: bool}> */
    public function subscriptions(): array
    {
        return $this->subscriptions;
    }

    protected function open(string $component): void
    {
        $subscription = $this->subscriptions[$component] ?? null;

        if ($subscription === null) {
            return;
        }

        $session = $this->client->currentSession();

        if ($session === null) {
            $this->refresh($component);

            return;
        }

        $sealed = $this->client->sealStream($session, json_encode([
            'protocol' => 1,
            'component' => $component,
            'regions' => $subscription['regions'],
            'state' => $subscription['state'],
            'platform' => $subscription['platform'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->subscriptions[$component]['session'] = $session;
        $this->subscriptions[$component]['signature'] = $sealed['signature'];

        Stream::open(self::PREFIX.$component, $this->client->endpoint('stream'), $sealed['headers'] + ['accept' => 'text/event-stream'], $sealed['body']);
    }

    /** Get a new session without blocking the UI lane, then open the stream with it. */
    protected function refresh(string $component): void
    {
        $then = function (bool $ok) use ($component) {
            if (! isset($this->subscriptions[$component])) {
                return;
            }

            if ($ok && $this->client->currentSession() !== null) {
                $this->open($component);

                return;
            }

            Log::warning("Asgard: no session for the stream of [{$component}]");
            unset($this->subscriptions[$component]);
        };

        if ($this->refresh !== null) {
            ($this->refresh)($then);

            return;
        }

        try {
            $this->client->handshake();
            $then(true);
        } catch (AsgardException) {
            $then(false);
        }
    }

    protected function component(string $streamId): ?string
    {
        return str_starts_with($streamId, self::PREFIX) ? substr($streamId, strlen(self::PREFIX)) : null;
    }
}
