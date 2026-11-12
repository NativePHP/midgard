<?php

namespace Native\Api\Client\Asgard\Events;

use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * The connection ended.
 *
 * `$error` says why when something went wrong (null for a clean end or a
 * close requested from PHP). `$status` is the HTTP status when the server
 * answered with one that ended the stream.
 *
 * `$reconnect` is true when the stream should be opened again: the drop was
 * worth retrying and the native side has already waited out its backoff.
 * Native code does not reconnect by itself, because the request it would
 * replay carries a signature that expires. The owner opens the same id again
 * with fresh headers (RegionStreams does), and the native side carries the
 * Last-Event-ID and backoff over. When it is false the stream is gone for
 * good.
 *
 * Stream::close() produces one of these with no error and no reconnect.
 * Replacing a stream by opening the same id again produces none for the
 * old connection.
 */
class StreamClosed implements BroadcastsGlobally
{
    public function __construct(
        public string $id,
        public ?string $error = null,
        public bool $reconnect = false,
        public ?int $status = null,
    ) {}
}
