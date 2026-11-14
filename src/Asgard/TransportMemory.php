<?php

namespace Native\Api\Client\Asgard;

use Illuminate\Contracts\Cache\Repository;

/**
 * Which request form works between this installation and its server
 * (protocol §8).
 *
 * The client sends its first request after a handshake as real QUERY. If
 * that is not answered by Asgard (a 405, a 501, a firewall's error page) it
 * sends it again as POST plus `X-Asgard-Method: QUERY`. The form that got a
 * real Asgard answer is remembered here, and every later request uses it,
 * the push stream included. Nothing is remembered after a network failure,
 * or when neither form got an Asgard answer, so the next request probes
 * again.
 *
 * Kept in the same cache store as the session, so every lane reads it, and
 * kept for good: it is a property of the network path, not of a session.
 * One entry per base URL, since another server may sit behind another
 * proxy.
 */
class TransportMemory
{
    /** Real QUERY. */
    public const QUERY = 'QUERY';

    /** POST plus `X-Asgard-Method: QUERY`. */
    public const POST = 'POST';

    public function __construct(
        protected Repository $cache,
        protected string $key,
    ) {}

    public static function keyFor(string $base): string
    {
        return 'midgard:asgard:transport:'.hash('sha256', rtrim($base, '/'));
    }

    /** The form that worked, or null when it has not been found yet. */
    public function get(): ?string
    {
        try {
            $form = $this->cache->get($this->key);
        } catch (\Throwable) {
            return null;
        }

        return in_array($form, [self::QUERY, self::POST], true) ? $form : null;
    }

    public function remember(string $form): void
    {
        if (! in_array($form, [self::QUERY, self::POST], true)) {
            throw new \InvalidArgumentException("Unknown request form [{$form}].");
        }

        $this->cache->forever($this->key, $form);
    }

    public function forget(): void
    {
        $this->cache->forget($this->key);
    }
}
