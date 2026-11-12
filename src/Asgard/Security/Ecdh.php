<?php

namespace Native\Api\Client\Asgard\Security;

use OpenSSLAsymmetricKey;

/**
 * Ephemeral P-256 key agreement for the handshake.
 *
 * Public keys travel as uncompressed points: 0x04 || X || Y, 65 bytes, the
 * same form RFC 8291 uses. Keys are built from a random scalar and imported
 * as DER, so nothing here needs an openssl.cnf, which an app's bundled PHP
 * may not have.
 */
final class Ecdh
{
    /** The order of the P-256 group, big-endian. */
    private const ORDER = 'ffffffff00000000ffffffffffffffffbce6faada7179e84f3b9cac2fc632551';

    /** SubjectPublicKeyInfo for an id-ecPublicKey on prime256v1, up to the point. */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** @return array{0: OpenSSLAsymmetricKey, 1: string} The private key and its public point. */
    public static function generate(): array
    {
        $order = hex2bin(self::ORDER);

        do {
            $scalar = random_bytes(32);
        } while (strcmp($scalar, $order) >= 0 || $scalar === str_repeat("\0", 32));

        $key = self::privateKey($scalar);

        return [$key, self::publicPoint($key)];
    }

    /** A private key from its 32-byte scalar. */
    public static function privateKey(string $scalar): OpenSSLAsymmetricKey
    {
        if (strlen($scalar) !== 32) {
            throw new SecurityException('A P-256 private key is 32 bytes.');
        }

        // ECPrivateKey (RFC 5915) with the curve named and no public key; OpenSSL computes it.
        $der = hex2bin('30310201010420').$scalar.hex2bin('a00a06082a8648ce3d030107');
        $key = openssl_pkey_get_private(self::pem('EC PRIVATE KEY', $der));

        if ($key === false) {
            throw new SecurityException('Not a P-256 private key.');
        }

        return $key;
    }

    /** The uncompressed public point of a key. */
    public static function publicPoint(OpenSSLAsymmetricKey $key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'] ?? null;

        if (! is_array($ec) || ($ec['curve_name'] ?? null) !== 'prime256v1') {
            throw new SecurityException('Not a P-256 key.');
        }

        return "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    /**
     * The shared secret (the X coordinate, 32 bytes) with the peer's public
     * point. Throws when the point is malformed or not on the curve.
     */
    public static function agree(OpenSSLAsymmetricKey $private, string $peerPublic): string
    {
        if (strlen($peerPublic) !== 65 || $peerPublic[0] !== "\x04") {
            throw new SecurityException('A public key must be an uncompressed P-256 point.');
        }

        $peer = @openssl_pkey_get_public(self::pem('PUBLIC KEY', hex2bin(self::SPKI_PREFIX).$peerPublic));

        if ($peer === false) {
            throw new SecurityException('The public key is not a point on P-256.');
        }

        $shared = openssl_pkey_derive($peer, $private, 32);

        if (! is_string($shared) || strlen($shared) !== 32) {
            throw new SecurityException('Key agreement failed.');
        }

        return $shared;
    }

    private static function pem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END {$label}-----\n";
    }
}
