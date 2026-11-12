<?php

namespace Native\Api\Client\Asgard;

/**
 * One render request to an Asgard server.
 *
 * Runs on the background PHP lane of an AsyncTask, so it is static and takes
 * and returns plain arrays. The session it uses is shared with every other
 * lane through the cache (SessionCache).
 */
class RenderCall
{
    /**
     * @param  array{url: string, path: string, key: string, timeout: float, store: ?string}  $config
     * @param  array<string, mixed>  $body  The RegionRequest wire body.
     * @return array{ok: bool, status: int, fragment?: mixed, error?: string}
     */
    public static function send(array $config, array $body): array
    {
        return AsgardClient::make($config)->render($body);
    }

    /**
     * Make sure a session exists, for a stream that needs one. True on success.
     *
     * @param  array{url: string, path: string, key: string, timeout: float, store: ?string}  $config
     */
    public static function handshake(array $config): bool
    {
        try {
            AsgardClient::make($config)->handshake();
        } catch (AsgardException) {
            return false;
        }

        return true;
    }
}
