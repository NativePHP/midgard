<?php

namespace Native\Api\Client\Asgard\Security;

/**
 * Content-Digest (RFC 9530) with sha-256, over the content as sent.
 */
final class ContentDigest
{
    public static function header(string $content): string
    {
        return 'sha-256='.StructuredFields::serializeBareItem(StructuredFields::bytes(hash('sha256', $content, true)));
    }

    public static function matches(?string $header, string $content): bool
    {
        if ($header === null) {
            return false;
        }

        try {
            $member = StructuredFields::parseDictionary($header)['sha-256'] ?? null;
        } catch (SecurityException) {
            return false;
        }

        return is_array($member) && $member['type'] === 'bytes'
            && hash_equals(hash('sha256', $content, true), $member['value']);
    }
}
