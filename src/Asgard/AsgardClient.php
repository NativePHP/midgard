<?php

namespace Native\Api\Client\Asgard;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Native\Api\Client\Asgard\Security\Base64Url;
use Native\Api\Client\Asgard\Security\Ecdh;
use Native\Api\Client\Asgard\Security\SecurityException;
use Native\Api\Client\Asgard\Security\SessionKeys;
use Native\Api\Client\Asgard\Security\Wire;
use Psr\Http\Message\ResponseInterface;

/**
 * The device's half of Asgard sessions, signatures and encryption (§6, §7).
 *
 * render(), callMethod(), upload() and routes() do the network calls: each handshakes
 * when there is no session, seals the request, and verifies and decrypts the
 * answer. A 419 gets one re-handshake and one retry. A 403 is never retried.
 * Anything that fails comes back as ['ok' => false], never as a fragment, a
 * delta or a route list.
 *
 * The handshake is always a plain POST to {base}/session. Every other
 * request goes as real QUERY or as POST plus `X-Asgard-Method: QUERY`
 * (§8). Which one is found by probing: until TransportMemory knows, a
 * request goes as QUERY, and if what comes back is not an Asgard answer it
 * is signed again and sent as POST. The form that got an Asgard answer is
 * remembered for the installation.
 *
 * sealStream() only signs and encrypts, so it is safe on the UI lane.
 */
class AsgardClient
{
    public const ENCODING = 'tree/json';

    /** At most this many hints in one answer. */
    public const MAX_PARTS = 16;

    /**
     * At most this many parts in one answer: the 16 regions a request names
     * at most, or an action's own region and 16 it redrew inline.
     */
    public const MAX_FRAGMENTS = self::MAX_PARTS + 1;

    /** The response header that says the server redrew every region despite the device's cache. */
    public const CACHE_HEADER = 'asgard-cache';

    /** Unsigned answers a server may give before it checks a request (§7.3), by status. */
    public const EARLY_REJECTIONS = [
        403 => ['forbidden', 'nonce_required'],
        405 => ['method_not_allowed'],
        413 => ['too_large'],
        419 => ['session_expired'],
    ];

    /**
     * Header names the app may not set through `midgard.asgard.headers`,
     * lowercase. The transport sets these itself, the signature covers
     * them, or a server reads them as the method to run.
     */
    public const RESERVED_HEADERS = [
        'accept', 'authorization', 'connection', 'cookie', 'host', 'last-event-id',
        'signature', 'signature-input', 'transfer-encoding',
        'x-http-method', 'x-http-method-override', 'x-method-override',
    ];

    /** Every header name starting with one of these is reserved too. */
    public const RESERVED_HEADER_PREFIXES = ['content-', 'asgard-', 'x-asgard-'];

    protected TransportMemory $transport;

    /** @var list<string> */
    protected array $elements;

    /**
     * @param  list<string>|null  $elements  The extra element types to advertise. Null asks mobile's registry.
     * @param  array<string, string>|null  $headers  Extra headers for every request, already resolved. Null reads `midgard.asgard.headers` at each request.
     */
    public function __construct(
        protected string $base,
        protected string $key,
        protected SessionCache $sessions,
        protected float $timeout = 20,
        protected ?ClientInterface $http = null,
        protected float $uploadTimeout = 150,
        ?TransportMemory $transport = null,
        ?array $elements = null,
        protected ?array $headers = null,
    ) {
        $this->transport = $transport ?? new TransportMemory(Cache::store('array'), TransportMemory::keyFor($base));
        $this->elements = $elements ?? self::advertisedElements();
    }

    /**
     * Build one from plain config, as an AsyncTask lane must.
     *
     * `headers` are the app's extra headers as they were when the request
     * was built (configuredHeaders()). Without them the client reads the
     * config itself at each request.
     *
     * @param  array{base: string, key: string, timeout: float, store: ?string, upload_timeout?: float, elements?: list<string>, headers?: array<string, string>}  $config
     */
    public static function make(array $config, ?ClientInterface $http = null): self
    {
        $store = Cache::store($config['store'] ?? null);
        $elements = $config['elements'] ?? self::advertisedElements();

        return new self(
            $config['base'],
            $config['key'],
            new SessionCache($store, SessionCache::keyFor($config['base'], $config['key'], $elements)),
            (float) ($config['timeout'] ?? 20),
            $http,
            (float) ($config['upload_timeout'] ?? 150),
            new TransportMemory($store, TransportMemory::keyFor($config['base'])),
            $elements,
            $config['headers'] ?? null,
        );
    }

    /**
     * The app's extra headers from `midgard.asgard.headers`, worked out now.
     *
     * A value may be a closure or a `[Class::class, 'method']` callable,
     * called with no arguments, like a `midgard.asgard.state` value. A
     * callable that gives null leaves the header out of this request.
     *
     * Throws on a name that is not a header name, on one the transport or
     * the protocol already uses (RESERVED_HEADERS and
     * RESERVED_HEADER_PREFIXES), and on a value that is not a string or
     * number or has a line break in it.
     *
     * @return array<string, string>
     */
    public static function configuredHeaders(): array
    {
        $config = config('midgard.asgard.headers', []);

        if ($config === null) {
            return [];
        }

        if (! is_array($config)) {
            throw new \InvalidArgumentException('midgard.asgard.headers must be an array of header name => value.');
        }

        $headers = [];

        foreach ($config as $name => $value) {
            $name = (string) $name;
            $lower = strtolower($name);

            if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1) {
                throw new \InvalidArgumentException("midgard.asgard.headers [{$name}] is not a header name.");
            }

            if (self::reservedHeader($lower)) {
                throw new \InvalidArgumentException(
                    "midgard.asgard.headers cannot set [{$name}]: Asgard's transport sets it, signs it or reads it itself."
                );
            }

            if ($value instanceof \Closure || (is_array($value) && is_callable($value))) {
                $value = $value();
            }

            if ($value === null) {
                continue;
            }

            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                throw new \InvalidArgumentException("midgard.asgard.headers [{$name}] must be a string, got a ".get_debug_type($value).'.');
            }

            $value = (string) $value;

            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new \InvalidArgumentException("midgard.asgard.headers [{$name}] has a line break in it.");
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    protected static function reservedHeader(string $lower): bool
    {
        foreach (self::RESERVED_HEADER_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        return in_array($lower, self::RESERVED_HEADERS, true);
    }

    /** The extra headers for the request about to go out. */
    public function extraHeaders(): array
    {
        return $this->headers ?? self::configuredHeaders();
    }

    public function endpoint(string $name): string
    {
        return rtrim($this->base, '/').'/'.$name;
    }

    /**
     * The element types this app can render beyond the core set, for the
     * handshake's `elements` (§6.1). They come from mobile's element
     * registry, which includes types UI packages register at boot, through
     * the same list mobile checks fragments against. Without mobile there
     * are none, which means core types only.
     *
     * @return list<string>
     */
    public static function advertisedElements(): array
    {
        if (! class_exists(FragmentTypes::class)) {
            return [];
        }

        try {
            return array_values(FragmentTypes::advertised());
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    public function elements(): array
    {
        return $this->elements;
    }

    /** Which request form this installation uses, or null while it is still to be found. */
    public function transport(): ?string
    {
        return $this->transport->get();
    }

    /** The stored session, or null when there is none or it is about to lapse. */
    public function currentSession(): ?Session
    {
        return $this->sessions->get();
    }

    /**
     * A fresh handshake. Throws on any failure.
     */
    public function handshake(): Session
    {
        [$private, $public] = Ecdh::generate();

        try {
            $response = $this->http()->request('POST', $this->endpoint('session'), [
                'body' => json_encode([
                    'key' => $this->key,
                    'protocol' => 1,
                    'accepts' => [self::ENCODING],
                    'elements' => $this->elements,
                    'client_public' => Base64Url::encode($public),
                ], JSON_THROW_ON_ERROR),
                'headers' => $this->extraHeaders() + ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            ]);
        } catch (GuzzleException $e) {
            throw new AsgardException('Handshake failed: '.$e->getMessage(), 0);
        }

        $status = $response->getStatusCode();
        $json = json_decode((string) $response->getBody(), true);

        if ($status !== 200 || ! is_array($json)) {
            throw new AsgardException('Handshake refused: '.(is_array($json) ? ($json['error'] ?? 'error') : 'not JSON'), $status);
        }

        $serverPublic = is_string($json['server_public'] ?? null) ? Base64Url::decode($json['server_public']) : null;
        $id = $json['session'] ?? null;
        $lifetime = $json['expires_in'] ?? null;

        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $id) || ! is_int($lifetime) || $lifetime < 1
            || ($json['encoding'] ?? null) !== self::ENCODING || $serverPublic === null) {
            throw new AsgardException('Handshake answer is malformed.', $status);
        }

        try {
            $secret = SessionKeys::secret(Ecdh::agree($private, $serverPublic), $public, $serverPublic);
        } catch (SecurityException $e) {
            throw new AsgardException('Handshake failed: '.$e->getMessage(), $status);
        }

        $this->sessions->put($id, $secret, $lifetime);

        return new Session($id, SessionKeys::fromSecret($secret), time() + $lifetime, $lifetime);
    }

    /**
     * One render or server action. Actions (a body with `action`) carry a nonce.
     *
     * Every answer is a `fragments` map, one part per region (§3.1). A part
     * is `{version, made_at, max_age?, fragment, chrome?}`, or `{cached: true}`
     * when the request declared a copy the server still holds good. A server
     * screen's part also carries its chrome, which comes back as is for the
     * device to check. The version token, `made_at` and `max_age` come back
     * only when well-formed.
     *
     * An action's answer holds its own region's part next to any other
     * regions of the same component the action redrew, and may carry
     * `refresh`, hints naming other components. `invalidate` is true when
     * the response carried a signed `Asgard-Cache: invalidate`.
     *
     * Every answer, a plain render's and an action's, carries `delta`: the
     * state the server changed, as a call's answer does. An object, possibly
     * empty. Without one the answer is a bad response.
     *
     * @param  array<string, mixed>  $body  The wire body, protocol member included.
     * @return array{ok: bool, status: int, delta?: array<string, mixed>, fragments?: array<string, array{fragment?: mixed, version?: string, chrome?: array, made_at?: int, max_age?: int, cached?: true}>, refresh?: list<array{component: string, region?: string}>, invalidate?: true, error?: string}
     */
    public function render(array $body): array
    {
        $result = $this->call('render', $body, array_key_exists('action', $body));

        if (! $result['ok']) {
            return $result;
        }

        $delta = $result['json']['delta'] ?? null;
        $more = array_key_exists('fragments', $result['json']) ? self::more($result['json']) : null;

        if ($more === null || ! is_array($delta) || ($delta !== [] && array_is_list($delta))) {
            return ['ok' => false, 'status' => $result['status'], 'error' => 'bad response'];
        }

        return ['ok' => true, 'status' => $result['status'], 'delta' => $delta, ...$more, ...($result['invalidate'] ? ['invalidate' => true] : [])];
    }

    /**
     * One `#[Server]` method call (protocol §10.5). Unsafe, so it carries a
     * nonce. The answer is the props the server changed, plus any regions
     * and hints the method asked for with refresh().
     *
     * @param  array<string, mixed>  $body  The wire body, protocol member included.
     * @return array{ok: bool, status: int, delta?: array<string, mixed>, fragments?: array<string, array{fragment: mixed, version?: string, chrome?: array}>, refresh?: list<array{component: string, region?: string}>, error?: string}
     */
    public function callMethod(array $body): array
    {
        $result = $this->call('call', $body, true);

        if (! $result['ok']) {
            return $result;
        }

        $delta = $result['json']['delta'] ?? null;
        $more = self::more($result['json']);

        if (! is_array($delta) || ($delta !== [] && array_is_list($delta)) || $more === null) {
            return ['ok' => false, 'status' => $result['status'], 'error' => 'bad response'];
        }

        return ['ok' => true, 'status' => $result['status'], 'delta' => $delta, ...$more, ...($result['invalidate'] ? ['invalidate' => true] : [])];
    }

    /**
     * One upload to an `#[Upload]` method (protocol §10.6). Unsafe, so it
     * carries a nonce. The body is a frame: a 4 byte big-endian header
     * length, the JSON header, then the file's bytes. The answer is a
     * call's.
     *
     * The whole file is read into memory and sealed in one piece, as §7.4
     * encrypts a body whole. Streaming it is future work. A file whose size
     * no longer matches the header (it changed after the device checked
     * it) is refused here, before anything is sent.
     *
     * @param  array<string, mixed>  $header  The frame header, protocol and size included.
     * @return array{ok: bool, status: int, delta?: array<string, mixed>, fragments?: array<string, array{fragment: mixed, version?: string, chrome?: array}>, refresh?: list<array{component: string, region?: string}>, error?: string}
     */
    public function upload(array $header, string $path): array
    {
        $bytes = is_file($path) && ! is_link($path) ? @file_get_contents($path) : false;

        if ($bytes === false) {
            return ['ok' => false, 'status' => 0, 'error' => 'The file could not be read.'];
        }

        if (strlen($bytes) !== ($header['size'] ?? null)) {
            return ['ok' => false, 'status' => 0, 'error' => 'The file changed after it was checked.'];
        }

        $frame = self::frame($header, $bytes);
        unset($bytes);

        $result = $this->send('upload', $frame, true, $this->uploadTimeout);
        unset($frame);

        if (! $result['ok']) {
            return $result;
        }

        $delta = $result['json']['delta'] ?? null;
        $more = self::more($result['json']);

        if (! is_array($delta) || ($delta !== [] && array_is_list($delta)) || $more === null) {
            return ['ok' => false, 'status' => $result['status'], 'error' => 'bad response'];
        }

        return ['ok' => true, 'status' => $result['status'], 'delta' => $delta, ...$more, ...($result['invalidate'] ? ['invalidate' => true] : [])];
    }

    /** The upload frame: header length, JSON header, bytes. */
    public static function frame(array $header, string $bytes): string
    {
        $json = json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return pack('N', strlen($json)).$json.$bytes;
    }

    /**
     * The optional `fragments` and `refresh` members of an action or call
     * answer, or null when either is malformed. Absent members stay absent.
     *
     * @param  array<string, mixed>  $json
     * @return array{fragments?: array<string, array{fragment: mixed, version?: string, chrome?: array}>, refresh?: list<array{component: string, region?: string}>}|null
     */
    protected static function more(array $json): ?array
    {
        $more = [];

        if (array_key_exists('fragments', $json)) {
            $parts = self::parts($json['fragments']);

            if ($parts === null) {
                return null;
            }

            $more['fragments'] = $parts;
        }

        if (array_key_exists('refresh', $json)) {
            $hints = self::hints($json['refresh']);

            if ($hints === null) {
                return null;
            }

            $more['refresh'] = $hints;
        }

        return $more;
    }

    /**
     * `{region: {version?, fragment, chrome?, made_at?, max_age?}}`, at least
     * one region, or null. A part may be `{cached: true}` instead.
     *
     * @return array<string, array{fragment?: mixed, version?: string, chrome?: array, made_at?: int, max_age?: int, cached?: true}>|null
     */
    protected static function parts(mixed $fragments): ?array
    {
        if (! is_array($fragments) || $fragments === [] || array_is_list($fragments) || count($fragments) > self::MAX_FRAGMENTS) {
            return null;
        }

        $parts = [];

        foreach ($fragments as $region => $part) {
            if (is_array($part) && ! array_key_exists('fragment', $part) && ($part['cached'] ?? null) === true) {
                $parts[(string) $region] = ['cached' => true];

                continue;
            }

            if (! is_array($part) || ! array_key_exists('fragment', $part) || ($part = self::part($part)) === null) {
                return null;
            }

            $parts[(string) $region] = $part;
        }

        return $parts;
    }

    /**
     * One region's fragment, with its version token when well-formed and its
     * chrome when it is an object. Null when the chrome is not one.
     *
     * @param  array<string, mixed>  $json
     * @return array{fragment: mixed, version?: string, chrome?: array}|null
     */
    protected static function part(array $json): ?array
    {
        $part = ['fragment' => $json['fragment']];

        // The region's version token (§8.1), handed back when it subscribes.
        $version = $json['version'] ?? null;
        if (is_string($version) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $version)) {
            $part['version'] = $version;
        }

        if (array_key_exists('chrome', $json)) {
            if (! is_array($json['chrome'])) {
                return null;
            }

            $part['chrome'] = $json['chrome'];
        }

        // When the server rendered it, in milliseconds, and how long it
        // says a copy stays good, in seconds. Kept only when well-formed.
        if (is_int($json['made_at'] ?? null) && $json['made_at'] > 0) {
            $part['made_at'] = $json['made_at'];
        }

        if (is_int($json['max_age'] ?? null) && $json['max_age'] >= 0) {
            $part['max_age'] = $json['max_age'];
        }

        return $part;
    }

    /**
     * `[{component, region?}]`, each a string, or null. The device checks
     * the names against what it has on screen.
     *
     * @return list<array{component: string, region?: string}>|null
     */
    protected static function hints(mixed $refresh): ?array
    {
        if (! is_array($refresh) || ! array_is_list($refresh) || count($refresh) > self::MAX_PARTS) {
            return null;
        }

        $hints = [];

        foreach ($refresh as $hint) {
            // Members other than these are ignored (§5.3).
            if (! is_array($hint) || ! is_string($hint['component'] ?? null)) {
                return null;
            }

            if (array_key_exists('region', $hint) && ! is_string($hint['region'])) {
                return null;
            }

            $hints[] = array_intersect_key($hint, ['component' => true, 'region' => true]);
        }

        return $hints;
    }

    /**
     * The server's screen routes (protocol §12, "Routes"). Only the shape is
     * checked here; the router checks each route again when it registers it.
     *
     * @return array{ok: bool, status: int, routes?: list<array{uri: string, screen: string}>, version?: string, error?: string}
     */
    public function routes(): array
    {
        $result = $this->call('routes', ['protocol' => 1], false);

        if (! $result['ok']) {
            return $result;
        }

        $routes = $result['json']['routes'] ?? null;
        $version = $result['json']['version'] ?? null;

        if (! is_array($routes) || ! array_is_list($routes) || ! is_string($version) || strlen($version) > 64) {
            return ['ok' => false, 'status' => $result['status'], 'error' => 'route list is malformed'];
        }

        foreach ($routes as $route) {
            if (! is_array($route) || ! is_string($route['uri'] ?? null) || ! is_string($route['screen'] ?? null)) {
                return ['ok' => false, 'status' => $result['status'], 'error' => 'route list is malformed'];
            }
        }

        return ['ok' => true, 'status' => $result['status'], 'routes' => $routes, 'version' => $version];
    }

    /**
     * One sealed call to an endpoint, with the 419 retry.
     *
     * @return array{ok: true, status: int, json: array<string, mixed>, invalidate: bool}|array{ok: false, status: int, error: string}
     */
    protected function call(string $endpoint, array $body, bool $action): array
    {
        return $this->send($endpoint, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $action);
    }

    /**
     * One sealed call with a plaintext body, with the 419 retry.
     *
     * @return array{ok: true, status: int, json: array<string, mixed>}|array{ok: false, status: int, error: string}
     */
    protected function send(string $endpoint, string $plaintext, bool $action, ?float $timeout = null): array
    {
        try {
            $result = $this->attempt($this->currentSession() ?? $this->handshake(), $endpoint, $plaintext, $action, $timeout);

            if ($result['status'] === 419) {
                // The server lost the session. Exactly one fresh one, exactly one retry.
                $result = $this->attempt($this->handshake(), $endpoint, $plaintext, $action, $timeout);
            }
        } catch (AsgardException $e) {
            return ['ok' => false, 'status' => $e->getCode(), 'error' => $e->getMessage()];
        }

        return $result;
    }

    /**
     * Sign and encrypt a request to the stream endpoint. No network.
     *
     * It goes in the form this installation remembered (§8). The stream is
     * opened by native code, which cannot fall back, and only for regions
     * that already got an answer, so the form is normally known by then.
     * When it is not, the stream goes as POST plus the header, which every
     * Asgard server accepts, and nothing is remembered.
     *
     * @return array{method: string, headers: array<string, string>, body: string, signature: string}
     */
    public function sealStream(Session $session, string $plaintext): array
    {
        $form = $this->transport->get() ?? TransportMemory::POST;

        return ['method' => $form] + $this->seal($session, $this->endpoint('stream'), $plaintext, null, $form);
    }

    /**
     * One request, in the remembered form, or probed when there is none
     * yet (§8): QUERY first, then POST plus the header when QUERY did not
     * get an Asgard answer. The form that did is remembered. A network
     * failure remembers nothing and does not fall back, so the next request
     * tries QUERY again. So does a request neither form got an Asgard answer
     * for, which fails as the POST attempt did.
     *
     * @return array{ok: true, status: int, json: array<string, mixed>}|array{ok: false, status: int, error: string}
     */
    protected function attempt(Session $session, string $name, string $plaintext, bool $action, ?float $timeout = null): array
    {
        $known = $this->transport->get();

        // Only a safe request probes (§8): a render with no action, or the
        // route list. Anything that changes the server goes as POST plus the
        // header until the form is known, so a fallback can never run it twice.
        if ($known === null && ($action || ! in_array($name, ['render', 'routes'], true))) {
            return $this->exchange($session, $name, $plaintext, $action, $timeout, TransportMemory::POST)[1];
        }

        [$asgard, $result] = $this->exchange($session, $name, $plaintext, $action, $timeout, $known ?? TransportMemory::QUERY);

        if ($known !== null || $asgard === null) {
            return $result;
        }

        if ($asgard) {
            $this->transport->remember(TransportMemory::QUERY);

            return $result;
        }

        // Signed afresh, with a fresh nonce: the method is a covered component.
        [$asgard, $result] = $this->exchange($session, $name, $plaintext, $action, $timeout, TransportMemory::POST);

        if ($asgard === true) {
            $this->transport->remember(TransportMemory::POST);
        }

        return $result;
    }

    /**
     * Send one sealed request in one form. Returns whether the answer was an
     * Asgard answer (null when nothing came back), and the result.
     *
     * An Asgard answer is a response that verifies as §7.3 says, or one of
     * the unsigned early rejections listed there other than 405, with the
     * body that table gives for its status. A firewall's 403 page is not
     * one, so it does not make a blocked QUERY look like it worked.
     *
     * @return array{0: ?bool, 1: array{ok: true, status: int, json: array<string, mixed>}|array{ok: false, status: int, error: string}}
     */
    protected function exchange(Session $session, string $name, string $plaintext, bool $action, ?float $timeout, string $form): array
    {
        $endpoint = $this->endpoint($name);
        $sealed = $this->seal($session, $endpoint, $plaintext, $action ? Base64Url::encode(random_bytes(16)) : null, $form);

        try {
            $response = $this->http()->request($form, $endpoint, [
                'body' => $sealed['body'],
                'headers' => $sealed['headers'] + ['accept' => 'application/json'] + $this->extraHeaders(),
            ] + ($timeout === null ? [] : ['timeout' => $timeout]));
        } catch (GuzzleException $e) {
            return [null, ['ok' => false, 'status' => 0, 'error' => $e->getMessage()]];
        }

        $status = $response->getStatusCode();
        $headers = self::headers($response);

        if (! isset($headers['signature'])) {
            // Nothing here can be trusted beyond the status (§7.3).
            $early = self::isEarlyRejection($status, (string) $response->getBody());

            if ($status === 419) {
                $this->sessions->forget($session->id);

                return [$early, ['ok' => false, 'status' => 419, 'error' => 'session_expired']];
            }

            if (isset(self::EARLY_REJECTIONS[$status])) {
                return [$early && $status !== 405, ['ok' => false, 'status' => $status, 'error' => 'unsigned response']];
            }

            // Any other unsigned status is a failure like a bad signature.
            return [false, ['ok' => false, 'status' => $status, 'error' => 'response failed verification: the answer is not signed']];
        }

        try {
            $json = json_decode(
                Wire::openResponse($session->keys, $session->id, $status, $headers, (string) $response->getBody(), $sealed['signature'], time()),
                true,
            );
        } catch (SecurityException $e) {
            return [false, ['ok' => false, 'status' => $status, 'error' => 'response failed verification: '.$e->getMessage()]];
        }

        $this->sessions->touch($session->id);

        if ($status !== 200 || ! is_array($json)) {
            return [true, ['ok' => false, 'status' => $status, 'error' => is_array($json) ? (string) ($json['error'] ?? 'bad response') : 'response was not JSON']];
        }

        // Verified above: a response that carries the header has to cover it.
        $invalidate = strtolower(trim($headers[self::CACHE_HEADER] ?? '')) === 'invalidate';

        return [true, ['ok' => true, 'status' => $status, 'json' => $json, 'invalidate' => $invalidate]];
    }

    /** Whether an unsigned answer is one of §7.3's early rejections: a listed status with its listed body. */
    public static function isEarlyRejection(int $status, string $body): bool
    {
        $json = json_decode($body, true);

        return isset(self::EARLY_REJECTIONS[$status]) && is_array($json)
            && in_array($json['error'] ?? null, self::EARLY_REJECTIONS[$status], true);
    }

    /** @return array{headers: array<string, string>, body: string, signature: string} */
    protected function seal(Session $session, string $endpoint, string $plaintext, ?string $nonce, string $form): array
    {
        $parts = parse_url($endpoint);
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $authority = strtolower($parts['host'] ?? '');

        if (isset($parts['port']) && ! ($scheme === 'http' && $parts['port'] === 80) && ! ($scheme === 'https' && $parts['port'] === 443)) {
            $authority .= ':'.$parts['port'];
        }

        // Real QUERY, or POST with the verb it means in a header that the
        // signature covers too (§7.3, §8).
        return Wire::sealRequest(
            $session->keys,
            $session->id,
            $form,
            $authority,
            $parts['path'] ?? '/',
            $plaintext,
            $form === TransportMemory::POST ? ['x-asgard-method' => 'QUERY'] : [],
            time(),
            $nonce,
        );
    }

    /** @return array<string, string> */
    protected static function headers(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        return $headers;
    }

    protected function http(): ClientInterface
    {
        // decode_content off: curl refuses a Content-Encoding it does not
        // know, and aes128gcm is ours to decode.
        return $this->http ??= new Client([
            'timeout' => $this->timeout,
            'http_errors' => false,
            'decode_content' => false,
        ]);
    }
}
