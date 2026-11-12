<?php

namespace Native\Api\Client\Asgard\Security;

/**
 * The aes128gcm content coding (RFC 8188).
 *
 * Header: salt (16) || rs (uint32) || idlen (1) || keyid. Then records of
 * rs bytes, the last one shorter or equal. Each record is the data, a
 * delimiter (0x01, or 0x02 on the last record) and a 16-byte tag. This
 * encoder never pads; the decoder accepts padding.
 */
final class Aes128Gcm
{
    public const RECORD_SIZE = 4096;

    public static function encrypt(string $plaintext, string $ikm, string $keyId = '', int $recordSize = self::RECORD_SIZE, ?string $salt = null): string
    {
        if ($recordSize < 18 || $recordSize > 0xFFFFFFFF) {
            throw new SecurityException('The record size must be at least 18.');
        }

        if (strlen($keyId) > 255) {
            throw new SecurityException('The key id is longer than 255 bytes.');
        }

        $salt ??= random_bytes(16);

        if (strlen($salt) !== 16) {
            throw new SecurityException('The salt must be 16 bytes.');
        }

        [$cek, $nonce] = self::derive($ikm, $salt);

        $records = $plaintext === '' ? [''] : str_split($plaintext, $recordSize - 17);
        $last = count($records) - 1;

        $out = $salt.pack('N', $recordSize).chr(strlen($keyId)).$keyId;

        foreach ($records as $seq => $data) {
            $tag = '';
            $sealed = openssl_encrypt(
                $data.($seq === $last ? "\x02" : "\x01"),
                'aes-128-gcm', $cek, OPENSSL_RAW_DATA, self::nonce($nonce, $seq), $tag, '', 16,
            );

            if ($sealed === false) {
                throw new SecurityException('Encryption failed.');
            }

            $out .= $sealed.$tag;
        }

        return $out;
    }

    public static function decrypt(string $body, string $ikm): string
    {
        [$salt, $recordSize, $start] = self::header($body);

        $records = substr($body, $start);

        if ($records === '') {
            throw new SecurityException('The body has no records.');
        }

        [$cek, $nonce] = self::derive($ikm, $salt);

        $chunks = str_split($records, $recordSize);
        $last = count($chunks) - 1;
        $out = '';

        foreach ($chunks as $seq => $chunk) {
            if (strlen($chunk) < 17) {
                throw new SecurityException('A record is too short.');
            }

            $plain = openssl_decrypt(
                substr($chunk, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA,
                self::nonce($nonce, $seq), substr($chunk, -16),
            );

            if ($plain === false) {
                throw new SecurityException('A record did not decrypt.');
            }

            $plain = rtrim($plain, "\0");

            if ($plain === '' || $plain[-1] !== ($seq === $last ? "\x02" : "\x01")) {
                throw new SecurityException('A record has the wrong delimiter, so the body is truncated or corrupt.');
            }

            $out .= substr($plain, 0, -1);
        }

        return $out;
    }

    /** The keyid from the header, without decrypting anything. */
    public static function keyId(string $body): string
    {
        [, , $start] = self::header($body);

        return substr($body, 21, $start - 21);
    }

    /** @return array{0: string, 1: int, 2: int} salt, record size, offset of the first record */
    private static function header(string $body): array
    {
        if (strlen($body) < 21) {
            throw new SecurityException('The body is shorter than an aes128gcm header.');
        }

        $recordSize = unpack('N', substr($body, 16, 4))[1];
        $start = 21 + ord($body[20]);

        if ($recordSize < 18) {
            throw new SecurityException('The record size is below 18.');
        }

        if (strlen($body) < $start) {
            throw new SecurityException('The body is shorter than its header.');
        }

        return [substr($body, 0, 16), $recordSize, $start];
    }

    /** @return array{0: string, 1: string} the content-encryption key and the base nonce */
    private static function derive(string $ikm, string $salt): array
    {
        return [
            Hkdf::derive($ikm, 16, "Content-Encoding: aes128gcm\0", $salt),
            Hkdf::derive($ikm, 12, "Content-Encoding: nonce\0", $salt),
        ];
    }

    /** The base nonce XOR the record sequence number, as a 96-bit big-endian integer. */
    private static function nonce(string $base, int $seq): string
    {
        return substr($base, 0, 4).(substr($base, 4) ^ pack('J', $seq));
    }
}
