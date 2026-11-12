<?php

namespace Native\Api\Client\Asgard;

use RuntimeException;

/**
 * A Server-Sent Events stream held open by the native side.
 *
 * PHP on the device runs in a single runloop and must not block on a
 * long-lived connection, so Swift/Kotlin owns the socket. open() and close()
 * only tell it what to do and return straight away. Everything that happens
 * on the stream comes back as a Laravel event: StreamOpened, StreamMessage
 * and StreamClosed (see Events/).
 *
 * The native side reconnects by itself after a drop or a 5xx, sending
 * Last-Event-ID. It gives up on a 204 or a 4xx.
 */
class Stream
{
    public const OPEN = 'Asgard.Stream.Open';

    public const CLOSE = 'Asgard.Stream.Close';

    protected static ?FakeStream $fake = null;

    /**
     * Open (or replace) the stream with this id.
     *
     * Without a body the request is a GET. With one it is a POST with the
     * body sent as JSON.
     *
     * @param  array<string, string>  $headers
     * @param  array<mixed>|null  $jsonBody
     */
    public static function open(string $id, string $url, array $headers = [], ?array $jsonBody = null): void
    {
        $method = $jsonBody === null ? 'GET' : 'POST';
        $body = $jsonBody === null ? null : json_encode($jsonBody, JSON_THROW_ON_ERROR);

        if ($body !== null && ! static::hasHeader($headers, 'Content-Type')) {
            $headers['Content-Type'] = 'application/json';
        }

        if (static::$fake !== null) {
            static::$fake->recordOpen($id, $url, $method, $headers, $body);

            return;
        }

        static::call(self::OPEN, [
            'id' => $id,
            'url' => $url,
            'method' => $method,
            // An object even when empty, so the native side sees {} not [].
            'headers' => (object) $headers,
            'body' => $body,
        ]);
    }

    /**
     * Close the stream with this id. Closing an id that is not open is fine.
     */
    public static function close(string $id): void
    {
        if (static::$fake !== null) {
            static::$fake->recordClose($id);

            return;
        }

        static::call(self::CLOSE, ['id' => $id]);
    }

    /**
     * Swap in a fake that records opens and closes and lets a test emit events.
     */
    public static function fake(): FakeStream
    {
        return static::$fake = new FakeStream;
    }

    /**
     * Go back to the real native bridge.
     */
    public static function unfake(): void
    {
        static::$fake = null;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected static function call(string $function, array $params): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        $result = nativephp_call($function, json_encode($params, JSON_THROW_ON_ERROR));

        if (! is_string($result) || $result === '') {
            return;
        }

        $decoded = json_decode($result, true);

        if (is_array($decoded) && ($decoded['status'] ?? null) === 'error') {
            throw new RuntimeException(
                "{$function} failed: ".($decoded['message'] ?? $decoded['code'] ?? 'unknown error')
            );
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected static function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
