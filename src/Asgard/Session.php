<?php

namespace Native\Api\Client\Asgard;

use Native\Api\Client\Asgard\Security\SessionKeys;

/**
 * One Asgard session as the device holds it (protocol §6): the id the server
 * gave, the keys derived from the agreed secret, and when it lapses.
 */
final class Session
{
    public function __construct(
        public readonly string $id,
        public readonly SessionKeys $keys,
        public readonly int $expiresAt,
        public readonly int $lifetime,
    ) {}
}
