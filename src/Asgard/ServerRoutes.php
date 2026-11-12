<?php

namespace Native\Api\Client\Asgard;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;

/**
 * The server's screen routes on the device (protocol §12, "Routes").
 *
 * boot() registers the last list this app fetched, straight from the cache,
 * so server routes work offline and from the first frame. It never touches
 * the network. An app that has never reached its server has no server
 * routes.
 *
 * refresh() fetches the list again in the background, on an AsyncTask
 * lane, and registers it when it arrives. Nothing waits for it: not the
 * boot, not the first frame, not a navigation. The service provider calls
 * it once the first screen has mounted, so every launch refreshes once.
 * A failed fetch keeps the cached list and may be tried again a minute
 * later.
 *
 * The router (`$register`) checks every route again and keeps app routes
 * ahead of the server's, so nothing here decides which screen wins.
 */
class ServerRoutes
{
    /** Seconds before a failed fetch may be tried again. */
    public const RETRY_AFTER = 60;

    protected bool $refreshed = false;

    protected bool $inFlight = false;

    protected int $retryAt = 0;

    /**
     * @param  Closure(list<array{uri: string, screen: string}>): int  $register  Puts a list into the router.
     * @param  Closure(Closure(array): void): void  $fetch  Fetches off the UI lane, then calls back with RenderCall::routes()'s answer.
     */
    public function __construct(
        protected Repository $cache,
        protected string $key,
        protected Closure $register,
        protected Closure $fetch,
    ) {}

    /** A cache key per server and Asgard key, so two servers never share a list. */
    public static function keyFor(string $url, string $asgardKey): string
    {
        return 'midgard:asgard:routes:'.hash('sha256', rtrim($url, '/')."\n".$asgardKey);
    }

    /** Register the cached list, if there is one. */
    public function boot(): void
    {
        $cached = $this->cached();

        if ($cached !== null) {
            ($this->register)($cached['routes']);
        }
    }

    /** Fetch the list in the background, unless that already happened in this process. */
    public function refresh(?int $now = null): void
    {
        $now ??= time();

        if ($this->refreshed || $this->inFlight || $now < $this->retryAt) {
            return;
        }

        $this->inFlight = true;

        try {
            ($this->fetch)(fn (array $result) => $this->receive($result, $now));
        } catch (\Throwable $e) {
            $this->receive(['ok' => false, 'error' => $e->getMessage()], $now);
        }
    }

    /** Take a fetched list: cache it and register it. A failure keeps what is cached. */
    public function receive(array $result, ?int $now = null): void
    {
        $this->inFlight = false;

        if (! ($result['ok'] ?? false) || ! is_array($result['routes'] ?? null) || ! is_string($result['version'] ?? null)) {
            $this->retryAt = ($now ?? time()) + self::RETRY_AFTER;

            Log::warning('Asgard: could not fetch the server routes, keeping the cached ones', [
                'status' => $result['status'] ?? null,
                'error' => $result['error'] ?? null,
            ]);

            return;
        }

        $this->refreshed = true;

        if (($this->cached()['version'] ?? null) === $result['version']) {
            return;
        }

        $this->cache->forever($this->key, ['version' => $result['version'], 'routes' => array_values($result['routes'])]);

        ($this->register)(array_values($result['routes']));
    }

    /** @return array{version: string, routes: list<array{uri: string, screen: string}>}|null */
    public function cached(): ?array
    {
        $cached = $this->cache->get($this->key);

        return is_array($cached) && is_string($cached['version'] ?? null) && is_array($cached['routes'] ?? null)
            ? $cached
            : null;
    }
}
