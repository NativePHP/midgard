<?php

namespace Native\Api\Client\Tests\Fixtures;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
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

    /** @var list<array{path: string, session: ?string, nonce: ?string, body: ?array}> */
    public array $requests = [];

    public int $handshakes = 0;

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

    /** Answer with exactly this plaintext instead of a fragment message. */
    public ?string $plaintext = null;

    public function __construct(public string $key = 'test-key') {}

    public function client(): Client
    {
        return new Client(['handler' => HandlerStack::create(fn (RequestInterface $r) => new FulfilledPromise($this->handle($r))), 'http_errors' => false]);
    }

    public function forgetSessions(): void
    {
        $this->sessions = [];
    }

    /** The stream body a sealed stream request carried, as the server would read it. */
    public function openStream(string $url, array $headers, string $body): array
    {
        $session = $headers['asgard-session-id'];
        $parts = parse_url($url);
        $opened = Wire::openRequest($this->sessions[$session], $session, 'POST', $parts['host'], $parts['path'], $headers, $body, time());

        return ['body' => json_decode($opened['body'], true), 'raw' => $opened['body'], 'signature' => $opened['signature'], 'session' => $session];
    }

    protected function handle(RequestInterface $request): Response
    {
        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/session')) {
            return $this->handshake(json_decode((string) $request->getBody(), true));
        }

        $session = $request->getHeaderLine('Asgard-Session-Id');
        $this->requests[] = ['path' => $path, 'session' => $session, 'nonce' => null, 'body' => null];

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
        $this->requests[array_key_last($this->requests)]['body'] = json_decode($opened['body'], true);

        if ($this->refuseWith !== null) {
            return new Response($this->refuseWith, ['Content-Type' => 'application/json'], '{"error":"refused"}');
        }

        $message = str_ends_with($path, '/routes')
            ? ['protocol' => 1, 'version' => $this->routesVersion, 'routes' => $this->routes]
            : ['protocol' => 1, 'region' => 'r', 'fragment' => $this->fragment] + ($this->chrome !== null ? ['chrome' => $this->chrome] : []);

        $plaintext = $this->plaintext ?? json_encode($message);
        $sealed = Wire::sealResponse($this->sessions[$session], $session, 200, $plaintext, $opened['signature'], null, null, $this->compress);

        if ($this->tamper !== null) {
            $sealed = ($this->tamper)($sealed);
        }

        return new Response(200, $sealed['headers'], $sealed['body']);
    }

    protected function handshake(array $body): Response
    {
        $this->handshakes++;

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
