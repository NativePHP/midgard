<?php

namespace Native\Api\Client\Asgard\Security;

/**
 * The four keys of one Asgard session (protocol §7.2), and the session secret
 * they come from (§6.1).
 */
final class SessionKeys
{
    public const SECRET_INFO = 'asgard/1 session';

    public function __construct(
        public readonly string $sigClient,
        public readonly string $sigServer,
        public readonly string $encClient,
        public readonly string $encServer,
    ) {}

    /**
     * The session secret: HKDF over the ECDH shared secret, bound to both
     * public keys so it belongs to this one handshake.
     */
    public static function secret(string $sharedSecret, string $clientPublic, string $serverPublic): string
    {
        return Hkdf::derive($sharedSecret, 32, self::SECRET_INFO."\0".$clientPublic.$serverPublic);
    }

    public static function fromSecret(string $secret): self
    {
        return new self(
            Hkdf::derive($secret, 32, 'asgard/1 sig client'),
            Hkdf::derive($secret, 32, 'asgard/1 sig server'),
            Hkdf::derive($secret, 16, 'asgard/1 enc client'),
            Hkdf::derive($secret, 16, 'asgard/1 enc server'),
        );
    }
}
