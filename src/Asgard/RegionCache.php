<?php

namespace Native\Api\Client\Asgard;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Native\Mobile\Edge\NativeRouter;

/**
 * The device's copy of each region and server screen fragment it got, kept
 * across launches (the region cache).
 *
 * When a region has nothing to paint yet, ServerRegion paints the copy held
 * here at once, even an old one, and still asks the server. The request
 * declares each copy that is still within its TTL in a `cache` member, and
 * the server answers `{"cached": true}` when it still holds that copy good.
 *
 * An entry is keyed by the server's base URL, the component address, the
 * region and a fingerprint of the state the request carried. It holds the
 * fragment, a server screen's chrome, the version token, the server's
 * `made_at` and `max_age`, the device's own `stored_at` (milliseconds) and
 * the TTL that applied when it was stored.
 *
 * It lives in a cache store that persists on the device:
 * `midgard.asgard.cache.store`, or the app's default store when that is
 * null. Not `array`, which forgets everything when the app quits. Caching
 * is off until `midgard.asgard.cache.ttl` (or a region's `cacheTtl:`) is
 * above 0.
 *
 * At most MAX_ENTRIES are kept. The oldest is dropped when another lands.
 * Call flush() when the user logs out, so the next user never sees the
 * last one's fragments.
 */
final class RegionCache
{
    public const PREFIX = 'midgard:asgard:region:';

    /** Where the list of stored keys lives, oldest first. */
    public const INDEX = 'midgard:asgard:region-index';

    /** Bumped by flush(), so an entry the index lost track of is never read again. */
    public const GENERATION = 'midgard:asgard:region-generation';

    public const MAX_ENTRIES = 200;

    /** The most regions one request may declare. */
    public const MAX_DECLARED = 64;

    /** The longest TTL a request may declare, in seconds (one year). */
    public const MAX_TTL = 31536000;

    /** The app's default TTL in seconds, from `midgard.asgard.cache.ttl`. 0 is off. */
    public static function defaultTtl(): int
    {
        return self::clamp(config('midgard.asgard.cache.ttl', 0));
    }

    /** A TTL as a whole number of seconds between 0 and MAX_TTL. */
    public static function clamp(mixed $ttl): int
    {
        return is_numeric($ttl) ? max(0, min(self::MAX_TTL, (int) $ttl)) : 0;
    }

    /**
     * The TTL that applies to an entry: the client's, cut to the server's
     * max age when the server sent one.
     */
    public static function effectiveTtl(int $clientTtl, ?int $maxAge): int
    {
        return $maxAge === null ? $clientTtl : max(0, min($clientTtl, $maxAge));
    }

    /** The key of a region's entry. Null when no server is configured. */
    public static function key(string $component, string $region, array $state): ?string
    {
        $base = config('midgard.asgard.base');

        if (! is_string($base) || $base === '') {
            return null;
        }

        try {
            $canonical = json_encode(
                [rtrim($base, '/'), $component, $region, CanonicalState::encode((object) $state)],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            return null;
        }

        return self::PREFIX.self::generation().':'.hash('sha256', $canonical);
    }

    /**
     * The entry stored under a key, or null when there is none or it is
     * malformed.
     *
     * @return array{fragment: array, chrome: ?array, version: ?string, made_at: int, max_age: ?int, stored_at: int, ttl: int}|null
     */
    public static function get(?string $key): ?array
    {
        if ($key === null) {
            return null;
        }

        $entry = self::attempt(fn (Repository $store) => $store->get($key));

        if (! is_array($entry) || ! is_array($entry['fragment'] ?? null) || ! is_int($entry['made_at'] ?? null)
            || ! is_int($entry['stored_at'] ?? null) || ! is_int($entry['ttl'] ?? null)) {
            return null;
        }

        return [
            'fragment' => $entry['fragment'],
            'chrome' => is_array($entry['chrome'] ?? null) ? $entry['chrome'] : null,
            'version' => is_string($entry['version'] ?? null) ? $entry['version'] : null,
            'made_at' => $entry['made_at'],
            'max_age' => is_int($entry['max_age'] ?? null) ? $entry['max_age'] : null,
            'stored_at' => $entry['stored_at'],
            'ttl' => $entry['ttl'],
        ];
    }

    /**
     * Store an entry. `stored_at` is the device's clock in milliseconds.
     *
     * @param  array{fragment: array, chrome: ?array, version: ?string, made_at: int, max_age: ?int, stored_at: int, ttl: int}  $entry
     */
    public static function put(string $key, array $entry): void
    {
        self::attempt(function (Repository $store) use ($key, $entry) {
            $store->forever($key, $entry);

            $index = static::index($store);
            $index = array_values(array_diff($index, [$key]));
            $index[] = $key;

            while (count($index) > self::MAX_ENTRIES) {
                $store->forget(array_shift($index));
            }

            $store->forever(self::INDEX, $index);
        });
    }

    public static function forget(?string $key): void
    {
        if ($key === null) {
            return;
        }

        self::attempt(function (Repository $store) use ($key) {
            $store->forget($key);
            $store->forever(self::INDEX, array_values(array_diff(static::index($store), [$key])));
        });
    }

    /**
     * Forget every stored fragment. Call it when the user logs out.
     *
     * Fragments already on screen stay there until they are replaced.
     */
    public static function flush(): void
    {
        self::attempt(function (Repository $store) {
            foreach (static::index($store) as $key) {
                $store->forget($key);
            }

            $store->forget(self::INDEX);

            $generation = $store->get(self::GENERATION);
            $store->forever(self::GENERATION, (is_int($generation) ? $generation : 0) + 1);
        });
    }

    /**
     * How many entries are stored.
     */
    public static function count(): int
    {
        return (int) self::attempt(fn (Repository $store) => count(self::index($store)));
    }

    /** The store entries live in: `midgard.asgard.cache.store`, or the app's default. */
    public static function store(): Repository
    {
        $name = config('midgard.asgard.cache.store');

        return Cache::store(is_string($name) && $name !== '' ? $name : null);
    }

    /** @return list<string> */
    protected static function index(Repository $store): array
    {
        $index = $store->get(self::INDEX);

        return is_array($index) ? array_values(array_filter($index, 'is_string')) : [];
    }

    protected static function generation(): int
    {
        $generation = self::attempt(fn (Repository $store) => $store->get(self::GENERATION));

        return is_int($generation) ? $generation : 0;
    }

    /**
     * Run against the store. A store that fails is logged, never thrown:
     * the cache is an optimisation, and the region still asks the server.
     */
    protected static function attempt(\Closure $work): mixed
    {
        try {
            return $work(self::store());
        } catch (\Throwable $e) {
            NativeRouter::debugLog('Asgard: the region cache failed: '.$e->getMessage());

            return null;
        }
    }
}
