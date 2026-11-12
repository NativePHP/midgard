<?php

namespace Native\Api\Client\Asgard\Security;

/**
 * Base64 with the URL and filename safe alphabet and no padding (RFC 4648 §5).
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** The decoded bytes, or null when the text is not base64url. */
    public static function decode(string $text): ?string
    {
        if (! preg_match('/^[A-Za-z0-9_-]*$/D', $text) || strlen($text) % 4 === 1) {
            return null;
        }

        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
