<?php

namespace Native\Api\Client\Asgard;

use Illuminate\Contracts\Cache\Repository;
use Native\Api\Client\Asgard\Security\Base64Url;
use Native\Api\Client\Asgard\Security\SessionKeys;

/**
 * Where the device keeps its Asgard session between calls.
 *
 * Render calls run on AsyncTask lanes, which are separate PHP interpreters,
 * and streams are signed on the UI lane. They share nothing in memory, so the
 * session lives in a cache store every lane can read: the app's default
 * store unless `midgard.asgard.session_store` names another. On a device
 * that is SQLite by default. It must not be `array`.
 *
 * Only the 32-byte secret is stored. Keys are derived again on read.
 */
class SessionCache
{
    /** Treat a session as gone this long before the server would, so a call never races the expiry. */
    public const MARGIN = 60;

    public function __construct(
        protected Repository $cache,
        protected string $key,
    ) {}

    /** A cache key per server and Asgard key, so two servers never share a session. */
    public static function keyFor(string $url, string $asgardKey): string
    {
        return 'midgard:asgard:session:'.hash('sha256', rtrim($url, '/')."\n".$asgardKey);
    }

    public function get(?int $now = null): ?Session
    {
        $stored = $this->cache->get($this->key);
        $now ??= time();

        if (! is_array($stored) || ! is_string($stored['id'] ?? null) || ! is_string($stored['secret'] ?? null)
            || ! is_int($stored['expires_at'] ?? null) || ! is_int($stored['lifetime'] ?? null)) {
            return null;
        }

        $secret = Base64Url::decode($stored['secret']);

        if ($secret === null || strlen($secret) !== 32 || $now >= $stored['expires_at'] - self::MARGIN) {
            return null;
        }

        return new Session($stored['id'], SessionKeys::fromSecret($secret), $stored['expires_at'], $stored['lifetime']);
    }

    public function put(string $id, string $secret, int $lifetime, ?int $now = null): void
    {
        $this->cache->put($this->key, [
            'id' => $id,
            'secret' => Base64Url::encode($secret),
            'expires_at' => ($now ?? time()) + $lifetime,
            'lifetime' => $lifetime,
        ], $lifetime);
    }

    /** The server accepted a request, so it slid the expiry forward. Do the same. */
    public function touch(string $id, ?int $now = null): void
    {
        $stored = $this->cache->get($this->key);

        if (is_array($stored) && ($stored['id'] ?? null) === $id && is_int($stored['lifetime'] ?? null)) {
            $stored['expires_at'] = ($now ?? time()) + $stored['lifetime'];
            $this->cache->put($this->key, $stored, $stored['lifetime']);
        }
    }

    /** Forget the session, but only if it is still this one: another lane may have replaced it already. */
    public function forget(string $id): void
    {
        $stored = $this->cache->get($this->key);

        if (is_array($stored) && ($stored['id'] ?? null) === $id) {
            $this->cache->forget($this->key);
        }
    }
}
