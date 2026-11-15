<?php

namespace Native\Api\Client\Tests\Fixtures;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Native\Api\Client\Asgard\Security\Base64Url;
use Native\Api\Client\Asgard\Security\Ecdh;
use Native\Api\Client\Asgard\Security\SecurityException;
use Native\Api\Client\Asgard\Security\SessionKeys;
use Native\Api\Client\Asgard\Security\Wire;
use Psr\Http\Message\RequestInterface;

/**
 * An Asgard server in memory, behind a Guzzle handler. It does the real
 * handshake and the real checks, so client tests exercise the same crypto a
 * server would. Hooks let a test make it misbehave.
 */
class FakeAsgardServer
{
    /** @var array<string, SessionKeys> */
    public array $sessions = [];

    /** @var list<array{path: string, method: string, override: ?string, session: ?string, nonce: ?string, body: ?array}> */
    public array $requests = [];

    public int $handshakes = 0;

    /** The per-request timeout option of each request, handshakes included. */
    public array $timeouts = [];

    /** Answer every render with this status, unsigned, while set. */
    public ?int $refuseWith = null;

    /** Change the sealed response before it goes back: fn (array $sealed): array */
    public ?Closure $tamper = null;

    /** The fragment to answer with. */
    public mixed $fragment = ['type' => 'text', 'props' => ['text' => 'hi']];

    /** Gzip the answer before encrypting it, as a region that allows it would. */
    public bool $compress = false;

    /** Chrome to send with each fragment, as a server screen does. */
    public ?array $chrome = null;

    /** The route list the routes endpoint answers with. */
    public array $routes = [['uri' => '/promos/{id}', 'screen' => 'promos.show']];

    public string $routesVersion = 'v1';

    /** The delta a #[Server] call answers with. */
    public array $delta = ['roll' => 4];

    /** The delta a render or action answers with. */
    public array $renderDelta = [];

    /** The Asgard-Cache value to seal into each answer, or null for none. */
    public ?string $cacheHeader = null;

    /** Refuse every render with a signed 422 too_many_regions while set. */
    public bool $tooManyRegions = false;

    /** Answer with exactly this plaintext instead of a fragment message. */
    public ?string $plaintext = null;

    /**
     * What something in front of the server does to a real QUERY: null lets
     * it through, an int answers with that status and a firewall's page,
     * 'network' drops the connection.
     */
    public int|string|null $blockQuery = null;

    /** The same for the POST form. */
    public int|string|null $blockPost = null;

    /** Every handshake body, in order. */
    public array $handshakeBodies = [];

    /** The method of every request, handshakes included. */
    public array $methods = [];

    /** The headers of every request, handshakes included, with lowercase names. */
    public array $headers = [];

    public function __construct(public string $key = 'test-key') {}

    public function client(): Client
    {
        return new Client(['handler' => HandlerStack::create(function (RequestInterface $r, array $options) {
            $this->timeouts[] = $options['timeout'] ?? null;

            return $this->dispatch($r);
        }), 'http_errors' => false]);
    }

    protected function dispatch(RequestInterface $r): PromiseInterface
    {
        $this->methods[] = $r->getMethod();
        $this->headers[] = array_change_key_case(array_map(fn (array $values) => implode(', ', $values), $r->getHeaders()));

        if (! str_ends_with($r->getUri()->getPath(), '/session')) {
            $block = $r->getMethod() === 'QUERY' ? $this->blockQuery : ($r->getMethod() === 'POST' ? $this->blockPost : null);

            if ($block === 'network') {
                return new RejectedPromise(new ConnectException('Connection reset', $r));
            }

            if (is_int($block)) {
                return new FulfilledPromise(new Response($block, ['Content-Type' => 'text/html'], '<html><body>Request blocked</body></html>'));
            }
        }

        return new FulfilledPromise($this->handle($r));
    }

    public function forgetSessions(): void
    {
        $this->sessions = [];
    }

    /** The stream body a sealed stream request carried, as the server would read it. */
    public function openStream(string $url, array $headers, string $body, string $method = 'POST'): array
    {
        $session = $headers['asgard-session-id'];
        $parts = parse_url($url);
        $opened = Wire::openRequest($this->sessions[$session], $session, $method, $parts['host'], $parts['path'], $headers, $body, time());

        return ['body' => json_decode($opened['body'], true), 'raw' => $opened['body'], 'signature' => $opened['signature'], 'session' => $session];
    }

    protected function handle(RequestInterface $request): Response
    {
        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/session')) {
            return $this->handshake(json_decode((string) $request->getBody(), true));
        }

        $session = $request->getHeaderLine('Asgard-Session-Id');
        $override = $request->hasHeader('X-Asgard-Method') ? $request->getHeaderLine('X-Asgard-Method') : null;
        $this->requests[] = ['path' => $path, 'method' => $request->getMethod(), 'override' => $override, 'session' => $session, 'nonce' => null, 'body' => null];

        // Every endpoint but the handshake takes QUERY, or POST with the header (§8).
        if (! ($request->getMethod() === 'QUERY' && $override === null) && ! ($request->getMethod() === 'POST' && $override === 'QUERY')) {
            return new Response(405, ['Content-Type' => 'application/json'], '{"error":"method_not_allowed"}');
        }

        if (! isset($this->sessions[$session])) {
            return new Response(419, ['Content-Type' => 'application/json'], '{"error":"session_expired"}');
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        try {
            $opened = Wire::openRequest($this->sessions[$session], $session, $request->getMethod(), $request->getUri()->getAuthority(), $path, $headers, (string) $request->getBody(), time());
        } catch (SecurityException $e) {
            return new Response(403, ['Content-Type' => 'application/json'], json_encode(['error' => 'forbidden', 'reason' => $e->getMessage()]));
        }

        $this->requests[array_key_last($this->requests)]['nonce'] = $opened['nonce'];
        if (str_ends_with($path, '/upload')) {
            // A frame: header length, JSON header, the file.
            $length = unpack('N', $opened['body'])[1];
            $this->requests[array_key_last($this->requests)]['body'] = json_decode(substr($opened['body'], 4, $length), true);
            $this->requests[array_key_last($this->requests)]['bytes'] = substr($opened['body'], 4 + $length);
        } else {
            $this->requests[array_key_last($this->requests)]['body'] = json_decode($opened['body'], true);
        }

        if ($this->refuseWith !== null) {
            // A listed early rejection carries the body §7.3 gives it.
            $error = match ($this->refuseWith) {
                403 => 'forbidden', 405 => 'method_not_allowed', 413 => 'too_large', 419 => 'session_expired', default => 'refused',
            };

            return new Response($this->refuseWith, ['Content-Type' => 'application/json'], json_encode(['error' => $error]));
        }

        $message = match (true) {
            str_ends_with($path, '/routes') => ['protocol' => 1, 'version' => $this->routesVersion, 'routes' => $this->routes],
            str_ends_with($path, '/call'), str_ends_with($path, '/upload') => ['protocol' => 1, 'delta' => (object) $this->delta],
            default => ['protocol' => 1, 'delta' => (object) $this->renderDelta, 'fragments' => $this->parts($this->requests[array_key_last($this->requests)]['body']['regions'] ?? ['r'])],
        };

        if ($this->tooManyRegions && str_ends_with($path, '/render')) {
            $sealed = Wire::sealResponse($this->sessions[$session], $session, 422, '{"error":"too_many_regions"}', $opened['signature'], null, null);

            return new Response(422, $sealed['headers'], $sealed['body']);
        }

        $plaintext = $this->plaintext ?? json_encode($message);
        $sealed = Wire::sealResponse($this->sessions[$session], $session, 200, $plaintext, $opened['signature'], null, null, $this->compress, $this->cacheHeader);

        if ($this->tamper !== null) {
            $sealed = ($this->tamper)($sealed);
        }

        return new Response(200, $sealed['headers'], $sealed['body']);
    }

    /** One part per region the request named, each the fragment and any chrome. */
    protected function parts(array $regions): array
    {
        $part = ['fragment' => $this->fragment] + ($this->chrome !== null ? ['chrome' => $this->chrome] : []);

        return array_fill_keys(array_map('strval', $regions), $part);
    }

    protected function handshake(array $body): Response
    {
        $this->handshakes++;
        $this->handshakeBodies[] = $body;

        if (($body['key'] ?? null) !== $this->key) {
            return new Response(403, ['Content-Type' => 'application/json'], '{"error":"unknown_key"}');
        }

        $clientPublic = Base64Url::decode($body['client_public']);
        [$private, $public] = Ecdh::generate();
        $id = Base64Url::encode(random_bytes(18));
        $this->sessions[$id] = SessionKeys::fromSecret(SessionKeys::secret(Ecdh::agree($private, $clientPublic), $clientPublic, $public));

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'session' => $id, 'server_public' => Base64Url::encode($public), 'expires_in' => 1800, 'encoding' => 'tree/json',
        ]));
    }
}
