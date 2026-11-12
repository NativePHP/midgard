<?php

namespace Native\Api\Client\Asgard\Events;

use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * The connection ended.
 *
 * `$error` says why when something went wrong (null for a clean end or a
 * close requested from PHP). `$willReconnect` is true when the native side
 * will try again on its own, in which case StreamOpened follows on success.
 * When it is false the stream is gone for good.
 *
 * Stream::close() produces one of these with no error and no reconnect.
 * Replacing a stream by opening the same id again produces none for the
 * old connection.
 */
class StreamClosed implements DispatchableFromNative
{
    public function __construct(
        public string $id,
        public ?string $error = null,
        public bool $willReconnect = false,
    ) {}
}
