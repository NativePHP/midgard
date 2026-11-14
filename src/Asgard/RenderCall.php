<?php

namespace Native\Api\Client\Asgard;

/**
 * One call to an Asgard server: a render, a `#[Server]` method, or the
 * route list.
 *
 * Runs on the background PHP lane of an AsyncTask, so it is static and takes
 * and returns plain arrays. The session it uses is shared with every other
 * lane through the cache (SessionCache).
 */
class RenderCall
{
    /**
     * @param  array{base: string, key: string, timeout: float, store: ?string}  $config
     * @param  array<string, mixed>  $body  The RegionRequest wire body.
     * @return array{ok: bool, status: int, fragment?: mixed, chrome?: array, version?: string, error?: string}
     */
    public static function send(array $config, array $body): array
    {
        return AsgardClient::make($config)->render($body);
    }

    /**
     * Run a `#[Server]` method on the server.
     *
     * @param  array{base: string, key: string, timeout: float, store: ?string}  $config
     * @param  array<string, mixed>  $body  The ServerRequest wire body.
     * @return array{ok: bool, status: int, delta?: array<string, mixed>, fragments?: array<string, mixed>, error?: string}
     */
    public static function serverMethod(array $config, array $body): array
    {
        return AsgardClient::make($config)->callMethod($body);
    }

    /**
     * Send a file to an `#[Upload]` method. The file is read here, on the
     * lane, so its bytes never pass through the UI thread.
     *
     * @param  array{base: string, key: string, timeout: float, upload_timeout?: float, store: ?string}  $config
     * @param  array<string, mixed>  $header  The frame header, protocol and size included.
     * @return array{ok: bool, status: int, delta?: array<string, mixed>, fragments?: array<string, mixed>, refresh?: list<array>, error?: string}
     */
    public static function upload(array $config, array $header, string $path): array
    {
        return AsgardClient::make($config)->upload($header, $path);
    }

    /**
     * Fetch the server's screen routes, handshaking first when needed.
     *
     * @param  array{base: string, key: string, timeout: float, store: ?string}  $config
     * @return array{ok: bool, status: int, routes?: list<array{uri: string, screen: string}>, version?: string, error?: string}
     */
    public static function routes(array $config): array
    {
        return AsgardClient::make($config)->routes();
    }

    /**
     * Make sure a session exists, for a stream that needs one. True on success.
     *
     * @param  array{base: string, key: string, timeout: float, store: ?string}  $config
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
