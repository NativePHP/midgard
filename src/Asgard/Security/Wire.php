<?php

namespace Native\Api\Client\Asgard\Security;

use Closure;

/**
 * Asgard's use of RFC 9421, RFC 9530, RFC 8188 and gzip (protocol §7 and §8.1).
 *
 * Seals and opens requests, responses and pushed stream events. Pure: the
 * caller supplies the keys, the clock and, in tests, the salt. Header names
 * are lowercase on the way in and out.
 *
 * The client and server packages each carry a copy of this class. The two
 * must stay byte-identical apart from the namespace, which the shared
 * interop vectors (tests/Fixtures/asgard-interop.json) check.
 */
final class Wire
{
    public const LABEL = 'asgard';

    public const TAG = 'asgard/1';

    public const ALG = 'hmac-sha256';

    public const PROTOCOL = '1';

    /** Longest a request signature may live: `expires` minus `created`. */
    public const MAX_LIFETIME = 300;

    /** How far in the future `created` may be. */
    public const MAX_SKEW = 60;

    public const NONCE_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/D';

    public const REQUEST_COMPONENTS = [
        '@method', '@authority', '@path', 'content-type', 'content-encoding',
        'content-digest', 'asgard-protocol', 'asgard-session-id',
    ];

    /** Covered after the rest, in this order, only when the request carries them. */
    public const OPTIONAL_REQUEST_COMPONENTS = ['x-asgard-method', 'authorization'];

    public const RESPONSE_COMPONENTS = ['@status', 'content-type', 'content-encoding', 'asgard-content-coding', 'content-digest', 'asgard-protocol'];

    /**
     * Covered right after the rest of RESPONSE_COMPONENTS, before the
     * request's signature, only when the response carries them. A response
     * that carries one without covering it does not verify.
     */
    public const OPTIONAL_RESPONSE_COMPONENTS = ['asgard-cache'];

    // Requests

    /**
     * Encrypt a request body with enc_c and sign the request with sig_c.
     *
     * @param  array<string, string>  $headers  x-asgard-method and authorization, when the request has them.
     * @return array{headers: array<string, string>, body: string, signature: string}
     */
    public static function sealRequest(
        SessionKeys $keys,
        string $session,
        string $method,
        string $authority,
        string $path,
        string $plaintext,
        array $headers = [],
        ?int $created = null,
        ?string $nonce = null,
        ?string $salt = null,
    ): array {
        $created ??= time();

        if ($nonce !== null && ! preg_match(self::NONCE_PATTERN, $nonce)) {
            throw new SecurityException('A nonce is 16 to 64 base64url characters.');
        }

        $body = Aes128Gcm::encrypt($plaintext, $keys->encClient, $session, Aes128Gcm::RECORD_SIZE, $salt);

        $headers = [
            'content-type' => 'application/json',
            'content-encoding' => 'aes128gcm',
            'content-digest' => ContentDigest::header($body),
            'asgard-protocol' => self::PROTOCOL,
            'asgard-session-id' => $session,
        ] + array_intersect_key(array_change_key_case($headers), array_flip(self::OPTIONAL_REQUEST_COMPONENTS));

        $params = [
            'created' => StructuredFields::integer($created),
            'expires' => StructuredFields::integer($created + self::MAX_LIFETIME),
            'keyid' => StructuredFields::string($session),
            'alg' => StructuredFields::string(self::ALG),
            'tag' => StructuredFields::string(self::TAG),
        ];

        if ($nonce !== null) {
            $params['nonce'] = StructuredFields::string($nonce);
        }

        $derived = self::requestDerived($method, $authority, $path);
        [$input, $base] = self::signatureBase(
            self::components(self::requestComponents($headers)),
            $params,
            fn (string $name) => $derived[$name] ?? $headers[$name],
        );

        $signature = hash_hmac('sha256', $base, $keys->sigClient, true);

        return [
            'headers' => $headers + [
                'signature-input' => self::LABEL.'='.$input,
                'signature' => self::LABEL.'='.StructuredFields::serializeBareItem(StructuredFields::bytes($signature)),
            ],
            'body' => $body,
            'signature' => $signature,
        ];
    }

    /**
     * Check a request in the order §7.6 gives (digest, signature, decrypt)
     * and return its plaintext. The caller has already found the session.
     *
     * @param  array<string, string>  $headers
     * @return array{body: string, signature: string, created: int, expires: int, nonce: ?string}
     */
    public static function openRequest(
        SessionKeys $keys,
        string $session,
        string $method,
        string $authority,
        string $path,
        array $headers,
        string $body,
        int $now,
    ): array {
        $headers = array_change_key_case($headers);

        if (! ContentDigest::matches($headers['content-digest'] ?? null, $body)) {
            throw new SecurityException('Content-Digest does not match the body.');
        }

        [$items, $params, $signature] = self::signatureFrom($headers);

        if (StructuredFields::serializeInnerList($items, []) !== StructuredFields::serializeInnerList(self::components(self::requestComponents($headers)), [])) {
            throw new SecurityException('The signature does not cover the components a request must cover.');
        }

        self::checkParams($params, ['created', 'expires', 'keyid', 'alg', 'tag'], ['nonce'], $session);

        $created = $params['created']['value'];
        $expires = $params['expires']['value'];
        $nonce = isset($params['nonce']) ? $params['nonce']['value'] : null;

        if ($created > $now + self::MAX_SKEW) {
            throw new SecurityException('The signature was created in the future.');
        }

        if ($expires <= $created || $expires - $created > self::MAX_LIFETIME) {
            throw new SecurityException('The signature lives longer than '.self::MAX_LIFETIME.' seconds.');
        }

        if ($now > $expires) {
            throw new SecurityException('The signature has expired.');
        }

        if ($nonce !== null && ! preg_match(self::NONCE_PATTERN, $nonce)) {
            throw new SecurityException('The nonce is malformed.');
        }

        $derived = self::requestDerived($method, $authority, $path);
        [, $base] = self::signatureBase($items, $params, fn (string $name) => $derived[$name] ?? $headers[$name] ?? null);

        if (! hash_equals(hash_hmac('sha256', $base, $keys->sigClient, true), $signature)) {
            throw new SecurityException('The request signature does not verify.');
        }

        return [
            // Requests are never compressed (§7.4).
            'body' => self::decode($headers['content-encoding'] ?? null, 'identity', $body, $keys->encClient, $session),
            'signature' => $signature,
            'created' => $created,
            'expires' => $expires,
            'nonce' => $nonce,
        ];
    }

    // Responses

    /**
     * Encrypt a response body with enc_s and sign the response with sig_s,
     * bound to the request it answers. With $compress, a body of at least
     * Gzip::MIN_SIZE bytes is gzipped first. Asgard-Content-Coding says
     * whether it was (gzip or identity), and the signature covers it.
     *
     * Not the stacked `Content-Encoding: gzip, aes128gcm`: Cloudflare
     * rewrites that header to `aes128gcm` and passes the body through as it
     * is, which breaks the signature.
     *
     * @return array{headers: array<string, string>, body: string}
     */
    public static function sealResponse(
        SessionKeys $keys,
        string $session,
        int $status,
        string $plaintext,
        string $requestSignature,
        ?int $created = null,
        ?string $salt = null,
        bool $compress = false,
        ?string $cache = null,
    ): array {
        if ($cache !== null && ! preg_match('/^[a-z]{1,32}$/D', $cache)) {
            throw new SecurityException('Asgard-Cache must be a short lowercase word.');
        }

        $gzip = $compress && strlen($plaintext) >= Gzip::MIN_SIZE;
        $body = Aes128Gcm::encrypt($gzip ? Gzip::encode($plaintext) : $plaintext, $keys->encServer, $session, Aes128Gcm::RECORD_SIZE, $salt);

        $headers = [
            'content-type' => 'application/json',
            'content-encoding' => 'aes128gcm',
            'asgard-content-coding' => $gzip ? 'gzip' : 'identity',
            'content-digest' => ContentDigest::header($body),
            'asgard-protocol' => self::PROTOCOL,
            ...($cache === null ? [] : ['asgard-cache' => $cache]),
        ];

        [$input, $base] = self::signatureBase(self::responseComponents($headers), self::responseParams($created ?? time(), $session), self::responseValue($status, $headers, $requestSignature));

        $signature = hash_hmac('sha256', $base, $keys->sigServer, true);

        return [
            'headers' => $headers + [
                'signature-input' => self::LABEL.'='.$input,
                'signature' => self::LABEL.'='.StructuredFields::serializeBareItem(StructuredFields::bytes($signature)),
            ],
            'body' => $body,
        ];
    }

    /**
     * Check a response against the request it answers and return its
     * plaintext. Throws on any failure; never hand back an unverified body.
     *
     * @param  array<string, string>  $headers
     */
    public static function openResponse(
        SessionKeys $keys,
        string $session,
        int $status,
        array $headers,
        string $body,
        string $requestSignature,
        int $now,
    ): string {
        $headers = array_change_key_case($headers);

        if (! ContentDigest::matches($headers['content-digest'] ?? null, $body)) {
            throw new SecurityException('Content-Digest does not match the body.');
        }

        [$items, $params, $signature] = self::signatureFrom($headers);

        if (StructuredFields::serializeInnerList($items, []) !== StructuredFields::serializeInnerList(self::responseComponents($headers), [])) {
            throw new SecurityException('The signature does not cover the components a response must cover.');
        }

        self::checkParams($params, ['created', 'keyid', 'alg', 'tag'], [], $session);

        $created = $params['created']['value'];

        if ($created > $now + self::MAX_SKEW || $created < $now - self::MAX_LIFETIME) {
            throw new SecurityException('The response signature is stale or from the future.');
        }

        [, $base] = self::signatureBase($items, $params, self::responseValue($status, $headers, $requestSignature));

        if (! hash_equals(hash_hmac('sha256', $base, $keys->sigServer, true), $signature)) {
            throw new SecurityException('The response signature does not verify.');
        }

        return self::decode($headers['content-encoding'] ?? null, $headers['asgard-content-coding'] ?? '', $body, $keys->encServer, $session);
    }

    // Stream events

    /**
     * The string an event's `sig` is the HMAC of (§8.1). Lines are joined
     * with LF and there is no trailing LF.
     */
    public static function eventBase(string $requestSignature, string $id, string $event, string $enc, string $body): string
    {
        return "asgard/1 event\n".base64_encode($requestSignature)."\n{$id}\n{$event}\n{$enc}\n{$body}";
    }

    /**
     * The `data` of a pushed event: {"body": ..., "enc": ..., "sig": ...}.
     * `enc` is gzip when the plaintext was compressed before encryption,
     * and identity when it was not.
     */
    public static function sealEvent(
        SessionKeys $keys,
        string $session,
        string $requestSignature,
        string $id,
        string $event,
        string $plaintext,
        ?string $salt = null,
        bool $compress = false,
    ): string {
        if (preg_match('/[\r\n]/', $id.$event)) {
            throw new SecurityException('An event id or name cannot hold a line break.');
        }

        $gzip = $compress && strlen($plaintext) >= Gzip::MIN_SIZE;
        $enc = $gzip ? 'gzip' : 'identity';

        $body = Base64Url::encode(Aes128Gcm::encrypt($gzip ? Gzip::encode($plaintext) : $plaintext, $keys->encServer, $session, Aes128Gcm::RECORD_SIZE, $salt));
        $sig = Base64Url::encode(hash_hmac('sha256', self::eventBase($requestSignature, $id, $event, $enc, $body), $keys->sigServer, true));

        return json_encode(['body' => $body, 'enc' => $enc, 'sig' => $sig], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Verify a pushed event against the stream request that opened it, then decrypt it. */
    public static function openEvent(
        SessionKeys $keys,
        string $session,
        string $requestSignature,
        string $id,
        string $event,
        string $data,
    ): string {
        $json = json_decode($data, true);

        if (! is_array($json) || array_keys($json) !== ['body', 'enc', 'sig']
            || ! is_string($json['body']) || ! is_string($json['sig']) || ! in_array($json['enc'], ['gzip', 'identity'], true)) {
            throw new SecurityException('An event must be {"body", "enc", "sig"} with enc gzip or identity.');
        }

        $sig = Base64Url::decode($json['sig']);
        $expected = hash_hmac('sha256', self::eventBase($requestSignature, $id, $event, $json['enc'], $json['body']), $keys->sigServer, true);

        if ($sig === null || ! hash_equals($expected, $sig)) {
            throw new SecurityException('The event signature does not verify.');
        }

        $body = Base64Url::decode($json['body']);

        if ($body === null) {
            throw new SecurityException('The event body is not base64url.');
        }

        return self::decode('aes128gcm', $json['enc'], $body, $keys->encServer, $session);
    }

    // Internals

    /**
     * The request components to cover, given the headers being sent.
     *
     * @param  array<string, string>  $headers
     * @return list<string>
     */
    public static function requestComponents(array $headers): array
    {
        $components = self::REQUEST_COMPONENTS;

        foreach (self::OPTIONAL_REQUEST_COMPONENTS as $name) {
            if (array_key_exists($name, $headers)) {
                $components[] = $name;
            }
        }

        return $components;
    }

    /** @return array<string, string> */
    private static function requestDerived(string $method, string $authority, string $path): array
    {
        return ['@method' => strtoupper($method), '@authority' => strtolower($authority), '@path' => $path];
    }

    /** @return list<array{type: string, value: string, params: array}> */
    private static function components(array $names): array
    {
        return array_map(fn (string $name) => StructuredFields::string($name) + ['params' => []], $names);
    }

    /**
     * The response components to cover, given the headers being sent.
     *
     * @param  array<string, string>  $headers  lowercase names
     * @return list<array{type: string, value: string, params: array}>
     */
    private static function responseComponents(array $headers): array
    {
        $names = self::RESPONSE_COMPONENTS;

        foreach (self::OPTIONAL_RESPONSE_COMPONENTS as $name) {
            if (array_key_exists($name, $headers)) {
                $names[] = $name;
            }
        }

        $items = self::components($names);
        $items[] = StructuredFields::string('signature') + ['params' => [
            'req' => StructuredFields::boolean(true),
            'key' => StructuredFields::string(self::LABEL),
        ]];

        return $items;
    }

    private static function responseParams(int $created, string $session): array
    {
        return [
            'created' => StructuredFields::integer($created),
            'keyid' => StructuredFields::string($session),
            'alg' => StructuredFields::string(self::ALG),
            'tag' => StructuredFields::string(self::TAG),
        ];
    }

    /** @param array<string, string> $headers */
    private static function responseValue(int $status, array $headers, string $requestSignature): Closure
    {
        return fn (string $name, array $params) => match (true) {
            $name === '@status' => (string) $status,
            $name === 'signature' => StructuredFields::serializeBareItem(StructuredFields::bytes($requestSignature)),
            default => $headers[$name] ?? null,
        };
    }

    /**
     * The serialised Signature-Input value and the signature base (RFC 9421 §2.5).
     *
     * @param  list<array{type: string, value: string, params: array}>  $items
     * @param  Closure(string, array): ?string  $value
     * @return array{0: string, 1: string}
     */
    public static function signatureBase(array $items, array $params, Closure $value): array
    {
        $input = StructuredFields::serializeInnerList($items, $params);
        $base = '';

        foreach ($items as $item) {
            $resolved = $value($item['value'], $item['params']);

            if ($resolved === null) {
                throw new SecurityException("The message has no {$item['value']} to cover.");
            }

            $base .= StructuredFields::serializeItem($item).': '.trim($resolved, " \t")."\n";
        }

        return [$input, $base.'"@signature-params": '.$input];
    }

    /**
     * The `asgard` members of Signature-Input and Signature.
     *
     * @param  array<string, string>  $headers
     * @return array{0: list<array>, 1: array<string, array>, 2: string}
     */
    private static function signatureFrom(array $headers): array
    {
        if (! isset($headers['signature-input'], $headers['signature'])) {
            throw new SecurityException('The message is not signed.');
        }

        $input = StructuredFields::parseDictionary($headers['signature-input'])[self::LABEL] ?? null;
        $signature = StructuredFields::parseDictionary($headers['signature'])[self::LABEL] ?? null;

        if (! is_array($input) || $input['type'] !== 'inner' || ! is_array($signature) || $signature['type'] !== 'bytes') {
            throw new SecurityException('The message has no asgard signature.');
        }

        foreach ($input['value'] as $item) {
            if ($item['type'] !== 'string') {
                throw new SecurityException('A covered component must be a string.');
            }
        }

        return [$input['value'], $input['params'], $signature['value']];
    }

    /**
     * @param  list<string>  $required
     * @param  list<string>  $optional
     */
    private static function checkParams(array $params, array $required, array $optional, string $session): void
    {
        $names = array_keys($params);

        if (array_diff($required, $names) !== [] || array_diff($names, $required, $optional) !== []) {
            throw new SecurityException('The signature parameters are not the ones Asgard uses.');
        }

        foreach ($params as $name => $param) {
            $type = in_array($name, ['created', 'expires'], true) ? 'integer' : 'string';
            if ($param['type'] !== $type) {
                throw new SecurityException("The {$name} parameter must be a {$type}.");
            }
        }

        if ($params['alg']['value'] !== self::ALG || $params['tag']['value'] !== self::TAG) {
            throw new SecurityException('The signature is not an asgard/1 hmac-sha256 signature.');
        }

        if (! hash_equals($session, $params['keyid']['value'])) {
            throw new SecurityException('The signature keyid is not this session.');
        }
    }

    /**
     * Undo what was done to a body, in reverse: decrypt the aes128gcm
     * content coding, then gunzip when $coding (the signed
     * Asgard-Content-Coding or event `enc`) is gzip. Anything else is refused.
     */
    private static function decode(?string $contentEncoding, string $coding, string $body, string $key, string $session): string
    {
        if ($contentEncoding === null || strtolower(trim($contentEncoding)) !== 'aes128gcm') {
            throw new SecurityException("The content coding [{$contentEncoding}] is not aes128gcm.");
        }

        if ($coding !== 'gzip' && $coding !== 'identity') {
            throw new SecurityException("The Asgard content coding [{$coding}] is not gzip or identity.");
        }

        if (! hash_equals($session, Aes128Gcm::keyId($body))) {
            throw new SecurityException('The body was encrypted for another session.');
        }

        $plaintext = Aes128Gcm::decrypt($body, $key);

        return $coding === 'gzip' ? Gzip::decode($plaintext) : $plaintext;
    }
}
