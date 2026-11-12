<?php

namespace Native\Api\Client\Asgard\Events;

use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * One Server-Sent Event, dispatched when the blank line that ends it arrives.
 *
 * `$id` is the stream id given to Stream::open(), not the SSE `id:` field.
 * The SSE `id:` field is `$lastEventId`: the most recent one the server sent
 * on this stream, or null if it has not sent one.
 */
class StreamMessage implements BroadcastsGlobally
{
    public function __construct(
        public string $id,
        public string $event,
        public string $data,
        public ?string $lastEventId = null,
    ) {}
}
