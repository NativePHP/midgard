<?php

namespace Native\Api\Client\Asgard\Security;

/**
 * HKDF-SHA256 (RFC 5869), extract then expand. An empty salt means a salt of
 * 32 zero bytes, as the RFC says.
 */
final class Hkdf
{
    public static function derive(string $ikm, int $length, string $info = '', string $salt = ''): string
    {
        return hash_hkdf('sha256', $ikm, $length, $info, $salt);
    }
}
