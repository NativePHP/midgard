<?php

namespace Native\Api\Client\Asgard;

use Illuminate\Support\Facades\Log;
use Native\Api\Client\Asgard\Contracts\ServerTransport;
use Native\Mobile\AsyncTask;

/**
 * Sends `#[Server]` method calls to an Asgard server over HTTP
 * (ASGARD-SERVER-METHODS.md §3, protocol §10.5).
 *
 * The call runs on a background PHP lane (AsyncTask), as a region fetch
 * does, so the UI never waits on the network. send() makes the request id,
 * starts the lane and returns the id. The answer comes back on the UI
 * thread, where ServerMethods::deliver() finds the call by that id, applies
 * the delta, paints any regions the method asked for, hands on any hints and
 * runs the closure.
 *
 * The answer is taken on whichever screen is on top when it lands. The
 * pending table is keyed by id, so it does not need the calling screen to
 * be showing, and it drops an answer for a component that has gone.
 *
 * Every call is signed, encrypted and carries a nonce (protocol §7), done by
 * AsgardClient, which also handshakes when there is no session.
 *
 * A failure goes to deliver() with `ok` false. Its `code` is the HTTP status
 * when the server answered, which the device reports as reason `server`,
 * and null when nothing came back, which it reports as `network`. A call
 * that outlives the device's own timeout is failed there as `timeout`, and
 * this answer is dropped when it lands.
 */
class HttpServerTransport implements ServerTransport
{
    /**
     * @param  array{base: string, key: string, timeout: float, store: ?string}  $config
     */
    public function __construct(protected array $config) {}

    public function send(ServerRequest $request): string
    {
        $id = 'call-'.bin2hex(random_bytes(8));
        $body = self::wireBody($request);
        $endpoint = rtrim($this->config['base'], '/').'/call';
        $what = "#[Server] {$request->component}::{$request->method}()";

        $config = ['headers' => AsgardClient::configuredHeaders()] + $this->config;

        $task = AsyncTask::dispatch(self::callWork($config, $body))
            ->anyScreen()
            // Named, not static::, as a callback may be bound to a screen.
            ->finished(static function ($result) use ($id, $endpoint, $what) {
                HttpServerTransport::answered($id, $result, $endpoint, $what);
            })
            ->failed(static function (\Throwable $e) use ($id, $what) {
                HttpServerTransport::lost($id, $e, $what);
            });

        // Started here rather than when the chain is destroyed. A lane that
        // cannot start reports through failed() at once, and ServerMethods
        // holds that answer until send() has returned the id.
        $task->start();

        return $id;
    }

    /**
     * The call came back: deliver the delta, or say why there is none.
     *
     * @param  mixed  $result  What RenderCall::serverMethod() returned.
     */
    public static function answered(string $id, mixed $result, string $endpoint, string $what): void
    {
        if (is_array($result) && ($result['ok'] ?? false) && is_array($result['delta'] ?? null)) {
            ServerMethods::deliver([
                'id' => $id,
                'ok' => true,
                'delta' => $result['delta'],
                'fragments' => is_array($result['fragments'] ?? null) ? $result['fragments'] : [],
                'refresh' => is_array($result['refresh'] ?? null) ? $result['refresh'] : [],
            ]);

            return;
        }

        $status = is_array($result) && is_int($result['status'] ?? null) ? $result['status'] : 0;
        $error = is_array($result) && is_string($result['error'] ?? null) ? $result['error'] : 'no answer';

        Log::warning("Asgard: {$what} failed", [
            'endpoint' => $endpoint,
            'status' => $status,
            'error' => $error,
        ]);

        ServerMethods::deliver([
            'id' => $id,
            'ok' => false,
            'error' => $error,
            // An HTTP status means the server answered: reason `server`.
            // Nothing came back: reason `network`.
            'code' => $status >= 100 ? $status : null,
        ]);
    }

    /** The call never came back: the lane died, timed out or never started. */
    public static function lost(string $id, \Throwable $e, string $what): void
    {
        Log::warning("Asgard: {$what} failed: ".$e->getMessage());

        ServerMethods::deliver([
            'id' => $id,
            'ok' => false,
            'error' => $e->getMessage(),
            'code' => null,
        ]);
    }

    /**
     * The body as plain JSON data. Anything in state that JSON cannot carry
     * throws here, on the UI thread, which ServerMethods reports as a send
     * that failed. Objects become the arrays JSON makes of them, so the lane
     * is handed nothing but plain values.
     *
     * @return array<string, mixed>
     */
    public static function wireBody(ServerRequest $request): array
    {
        $json = json_encode(
            ['protocol' => 1, ...$request->toArray()],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The call an AsyncTask lane runs. It is built here, on a line of its
     * own, because a serialized closure is found by the line it starts on.
     * Written inline next to the closure that dispatches it, the lane would
     * get that outer closure instead.
     *
     * @param  array{base: string, key: string, timeout: float, store: ?string}  $config
     * @param  array<string, mixed>  $body
     */
    public static function callWork(array $config, array $body): \Closure
    {
        return static fn () => RenderCall::serverMethod($config, $body);
    }
}
