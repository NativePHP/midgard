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
 * Keeps the device's one Server-Sent Events stream to the Asgard server
 * (protocol §8.1).
 *
 * The device hands over the whole set of subscriptions it wants through
 * watch(), each time the set changes: one per component instance on
 * screen, with its regions, the state they were rendered for and the
 * version it holds for each. An unchanged set does nothing. An empty set
 * closes the stream. Anything else (re)opens it, signed afresh. The stream
 * itself lives in native code (Stream), so PHP never blocks on it.
 *
 * Every opening request is signed and encrypted here, with the current
 * session. Native code never reconnects on its own with the old headers,
 * whose signature would soon expire: it waits out its backoff and fires
 * StreamClosed with `reconnect`, and this class opens the stream again with
 * a fresh signature. A 419 gets one new handshake (off the UI lane) and one
 * more try. Anything else that stops the stream drops the set until the
 * device hands over a new one.
 *
 * When the app goes to the background the stream is closed but the set is
 * kept, so the server frees the worker the stream holds. Nothing that
 * happens to the stream while away drops the set or reopens it, and a set
 * handed over while away is only recorded. When the app comes back the
 * stream opens again at once, signed afresh, with the versions the device
 * holds. The server then signals every region that changed while it was
 * away, or that the device holds no version for.
 *
 * Every event must verify against the request that opened the stream and
 * decrypt, and must name a subscription and region this stream carries,
 * before it reaches the device. One that fails is logged and dropped. A
 * `signal` goes to the signaller and a shared region's `fragment` to the
 * pusher (ServerRegion::signal() and ServerRegion::push() in an app), with
 * the subscription's address and state. Those only touch instances that
 * asked with that state, so a server cannot use the stream to paint
 * elsewhere.
 */
class RegionStreams
{
    /** The native stream's id. There is only ever one. */
    public const ID = 'asgard';

    /**
     * The set the stream carries, by subscription id.
     *
     * @var array<string, array{id: string, component: string, regions: list<string>, state: array, versions: array<string, string>}>
     */
    protected array $subscriptions = [];

    protected ?Session $session = null;

    protected ?string $signature = null;

    protected bool $retried = false;

    /** The app is in the background, so the stream stays closed. */
    protected bool $backgrounded = false;

    /**
     * @param  Closure(string $component, array $state, string $region, array $fragment, ?array $chrome, string $version, ?int $madeAt, ?int $maxAge): int  $pusher
     * @param  Closure(string $component, array $state, string $region, string $version): int  $signaller
     * @param  (Closure(Closure(bool): void): void)|null  $refresh  Gets a new session off the UI lane, then calls back.
     *                                                              Without one, the handshake runs inline (tests).
     */
    public function __construct(
        protected AsgardClient $client,
        protected Closure $pusher,
        protected Closure $signaller,
        protected ?Closure $refresh = null,
    ) {}

    /**
     * Carry exactly this set of subscriptions.
     *
     * @param  list<array{id: string, component: string, regions: list<string>, state: array, versions?: array<string, string>}>  $subscriptions
     */
    public function watch(array $subscriptions): void
    {
        $next = self::keyed($subscriptions);

        // Versions alone do not make a set different. The stream keeps its own.
        if (self::shape($next) === self::shape($this->subscriptions)) {
            return;
        }

        $this->subscriptions = $next;
        $this->retried = false;

        if ($next === []) {
            $this->forget();
            Stream::close(self::ID);

            return;
        }

        $this->open();
    }

    /**
     * The app went to the background. Close the stream so the server frees
     * its worker, but keep the set for when the app comes back.
     */
    public function backgrounded(): void
    {
        $this->backgrounded = true;

        if ($this->subscriptions === []) {
            return;
        }

        $this->forget();
        Stream::close(self::ID);
    }

    /**
     * The app came back. Open the stream again straight away, with a fresh
     * signature, a fresh native backoff and one more 419 retry.
     *
     * Pass the device's current set to send the versions it holds now. The
     * set handed to watch() keeps the versions it had then, because a
     * version moving alone is not handed over.
     *
     * @param  list<array{id: string, component: string, regions: list<string>, state: array, versions?: array<string, string>}>|null  $subscriptions
     */
    public function foregrounded(?array $subscriptions = null): void
    {
        $this->backgrounded = false;
        $this->retried = false;

        if ($subscriptions !== null) {
            $this->subscriptions = self::keyed($subscriptions);
        }

        // Close first, even if the background close never got through: a
        // fresh native session starts with no backoff, so it connects now.
        $this->forget();
        Stream::close(self::ID);

        $this->open();
    }

    public function receive(StreamMessage $message): void
    {
        if ($message->id !== self::ID || $this->session === null
            || ! in_array($message->event, ['signal', 'fragment'], true)) {
            return;
        }

        try {
            $plaintext = Wire::openEvent(
                $this->session->keys, $this->session->id, $this->signature,
                (string) $message->lastEventId, $message->event, $message->data,
            );
        } catch (SecurityException $e) {
            Log::warning('Asgard: dropped a pushed event that failed verification: '.$e->getMessage());

            return;
        }

        $payload = json_decode($plaintext, true);
        $id = is_array($payload) ? ($payload['subscription'] ?? null) : null;
        $region = is_array($payload) ? ($payload['region'] ?? null) : null;
        $version = is_array($payload) ? ($payload['version'] ?? null) : null;
        $subscription = is_string($id) ? ($this->subscriptions[$id] ?? null) : null;

        if ($subscription === null || ! is_string($region) || ! in_array($region, $subscription['regions'], true)
            || ! is_string($version) || $version === '' || strlen($version) > 64) {
            Log::warning('Asgard: ignored a pushed event that is not for a subscription and region of this stream');

            return;
        }

        if ($message->event === 'signal') {
            ($this->signaller)($subscription['component'], $subscription['state'], $region, $version);

            return;
        }

        if (! is_array($payload['fragment'] ?? null)) {
            Log::warning("Asgard: ignored a fragment event for [{$subscription['component']}] [{$region}] with no fragment");

            return;
        }

        // A reconnect sends this version, so the region is not sent again.
        $this->subscriptions[$id]['versions'][$region] = $version;

        // A server screen's event carries its chrome too, checked by the screen.
        $chrome = $payload['chrome'] ?? null;

        $madeAt = is_int($payload['made_at'] ?? null) && $payload['made_at'] > 0 ? $payload['made_at'] : null;
        $maxAge = is_int($payload['max_age'] ?? null) && $payload['max_age'] >= 0 ? $payload['max_age'] : null;

        ($this->pusher)($subscription['component'], $subscription['state'], $region, $payload['fragment'], is_array($chrome) ? $chrome : null, $version, $madeAt, $maxAge);
    }

    public function opened(StreamOpened $event): void
    {
        if ($event->id === self::ID) {
            $this->retried = false;
        }
    }

    public function closed(StreamClosed $event): void
    {
        if ($event->id !== self::ID || $this->subscriptions === [] || $this->backgrounded) {
            return;
        }

        // No error, no status and no reconnect is the native side owning up
        // to a Stream::close() from here. It can land after the stream was
        // opened again, so it must not drop the set.
        if (! $event->reconnect && $event->error === null && $event->status === null) {
            return;
        }

        if ($event->reconnect) {
            $this->open();

            return;
        }

        if ($event->status === 419 && ! $this->retried) {
            $this->retried = true;
            $this->refresh();

            return;
        }

        Log::warning('Asgard: the push stream stopped', ['error' => $event->error, 'status' => $event->status]);

        $this->forget();
        $this->subscriptions = [];
    }

    public function close(): void
    {
        $this->subscriptions = [];
        $this->forget();

        Stream::close(self::ID);
    }

    /** @return array<string, array{id: string, component: string, regions: list<string>, state: array, versions: array<string, string>}> */
    public function subscriptions(): array
    {
        return $this->subscriptions;
    }

    protected function open(): void
    {
        if ($this->subscriptions === [] || $this->backgrounded) {
            return;
        }

        $session = $this->client->currentSession();

        if ($session === null) {
            $this->refresh();

            return;
        }

        $subscriptions = [];
        foreach ($this->subscriptions as $subscription) {
            $wire = [
                'id' => $subscription['id'],
                'component' => $subscription['component'],
                'regions' => $subscription['regions'],
                'state' => (object) $subscription['state'],
            ];

            if ($subscription['versions'] !== []) {
                $wire['versions'] = $subscription['versions'];
            }

            $subscriptions[] = $wire;
        }

        $sealed = $this->client->sealStream($session, json_encode([
            'protocol' => 1,
            'subscriptions' => $subscriptions,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->session = $session;
        $this->signature = $sealed['signature'];

        // In the form this installation found works (§8), QUERY or POST.
        // The app's extra headers (midgard.asgard.headers) go too, worked out now.
        Stream::open(
            self::ID,
            $this->client->endpoint('stream'),
            $sealed['headers'] + ['accept' => 'text/event-stream'] + $this->client->extraHeaders(),
            $sealed['body'],
            $sealed['method'],
        );
    }

    /** Get a new session without blocking the UI lane, then open the stream with it. */
    protected function refresh(): void
    {
        $then = function (bool $ok) {
            // Back in the foreground, the stream opens again anyway.
            if ($this->subscriptions === [] || $this->backgrounded) {
                return;
            }

            if ($ok && $this->client->currentSession() !== null) {
                $this->open();

                return;
            }

            Log::warning('Asgard: no session for the push stream');
            $this->forget();
            $this->subscriptions = [];
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

    /** Events from here on belong to no stream this class opened. */
    protected function forget(): void
    {
        $this->session = null;
        $this->signature = null;
    }

    /**
     * @param  list<array{id: string, component: string, regions: list<string>, state: array, versions?: array<string, string>}>  $subscriptions
     * @return array<string, array{id: string, component: string, regions: list<string>, state: array, versions: array<string, string>}>
     */
    protected static function keyed(array $subscriptions): array
    {
        $keyed = [];
        foreach ($subscriptions as $subscription) {
            $keyed[$subscription['id']] = [
                'id' => $subscription['id'],
                'component' => $subscription['component'],
                'regions' => array_values($subscription['regions']),
                'state' => $subscription['state'],
                'versions' => $subscription['versions'] ?? [],
            ];
        }

        return $keyed;
    }

    /** The set without versions, for telling whether it changed. */
    protected static function shape(array $subscriptions): array
    {
        return array_map(fn (array $s) => array_diff_key($s, ['versions' => true]), $subscriptions);
    }
}
