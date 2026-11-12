<?php

namespace Native\Api\Client\Asgard\Events;

use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * The server answered with a 2xx and the stream is live. Fires again after
 * every automatic reconnect.
 */
class StreamOpened implements DispatchableFromNative
{
    public function __construct(public string $id) {}
}
