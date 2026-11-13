<?php

namespace Native\Api\Client\Asgard;

use Illuminate\Support\Facades\Log;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\Asgard\ActionRequest;
use Native\Mobile\Edge\Asgard\Contracts\FragmentSource;
use Native\Mobile\Edge\Asgard\RegionRequest;
use Native\Mobile\Edge\Asgard\ServerRegion;

/**
 * Fetches region fragments from an Asgard server over HTTP.
 *
 * The request runs on a background PHP lane (AsyncTask), so the UI never waits
 * on the network. The answer comes back on the UI thread, where
 * ServerRegion::deliver() hands it to the region that asked (by request id,
 * so a late answer to a superseded request is dropped) and the screen
 * repaints.
 *
 * A server action (a tap inside a fragment, protocol §10.2) goes to the same
 * endpoint with an `action` member and is answered the same way.
 *
 * A server screen (§12) is a region too, named `screen`, and its answer
 * carries chrome next to the fragment. The chrome is handed over with it,
 * and the screen checks it before it wears it.
 *
 * Every call is signed and encrypted (protocol §7) by AsgardClient, which
 * also handshakes when there is no session. A fragment whose response does
 * not verify never reaches the region.
 *
 * Each answer carries the region's version token, which is handed over with
 * the fragment. The device uses it to say what it already holds when it
 * subscribes to the push stream.
 *
 * With streaming on, watch() hands the device's set of subscriptions to the
 * push stream (RegionStreams). The device only watches regions that already
 * got a good answer, so the session the stream is signed with exists. With
 * streaming off it does nothing.
 *
 * A failure is reported to ServerRegion::failed() with a reason, and the
 * HTTP status when the server answered: `network` when nothing came back,
 * `server` for an error status (a 419 that survived its one retry
 * included), and `invalid` for an answer that failed verification or is
 * not a fragment response. The device decides what that means: a region
 * keeps what it had, a server screen still waiting for its body gives up,
 * and an action fires ServerActionFailed. Failures are also logged, never
 * thrown.
 */
class HttpFragmentSource implements FragmentSource
{
    /**
     * @param  array{url: string, path: string, key: string, timeout: float, store: ?string}  $config
     */
    public function __construct(
        protected array $config,
        protected ?RegionStreams $streams = null,
    ) {}

    public function request(RegionRequest $request): void
    {
        $this->send($request);
    }

    public function action(ActionRequest $request): void
    {
        $this->send($request);
    }

    public function watch(array $subscriptions, string $platform): void
    {
        $this->streams?->watch($subscriptions, $platform);
    }

    protected function send(RegionRequest $request): void
    {
        $config = $this->config;
        $endpoint = $this->endpoint('render');
        // The wire body (protocol §9) leaves the protocol version to the transport.
        $body = ['protocol' => 1, ...$request->toArray()];
        $what = $request instanceof ActionRequest
            ? "action [{$request->call}] in region [{$request->region}]"
            : "region [{$request->region}]";

        AsyncTask::dispatch(static fn () => RenderCall::send($config, $body))
            // Named, not static::, as a callback may be bound to the screen.
            ->finished(function ($result) use ($request, $endpoint, $what) {
                HttpFragmentSource::answered($request, $result, $endpoint, $what);
            })
            ->failed(function (\Throwable $e) use ($request, $what) {
                HttpFragmentSource::lost($request, $e, $what);
            });
    }

    /**
     * The render call came back: deliver the fragment, or report why there
     * is none.
     *
     * @param  mixed  $result  What RenderCall::send() returned.
     */
    public static function answered(RegionRequest $request, mixed $result, string $endpoint, string $what): void
    {
        if (is_array($result) && ($result['ok'] ?? false)) {
            ServerRegion::deliver($request, $result['fragment'], $result['chrome'] ?? null, $result['version'] ?? null);

            return;
        }

        $status = is_array($result) && is_int($result['status'] ?? null) ? $result['status'] : 0;
        $error = is_array($result) && is_string($result['error'] ?? null) ? $result['error'] : 'no answer';

        Log::warning("Asgard: no fragment for {$what}", [
            'endpoint' => $endpoint,
            'status' => $status,
            'error' => $error,
        ]);

        ServerRegion::failed($request, self::reasonFor($status, $error), $status === 0 ? null : $status, $error);
    }

    /** The render call never came back: the lane died or timed out. */
    public static function lost(RegionRequest $request, \Throwable $e, string $what): void
    {
        Log::warning("Asgard: fetching {$what} failed: ".$e->getMessage());

        ServerRegion::failed($request, ServerRegion::NETWORK, null, $e->getMessage());
    }

    /**
     * Why a render call failed, from what AsgardClient answered. Status 0 is
     * a request that got no response. An answer that failed verification,
     * or a 2xx that is not a fragment response, is `invalid`. Any other
     * status is the server's.
     */
    public static function reasonFor(int $status, string $error): string
    {
        if ($status === 0) {
            return ServerRegion::NETWORK;
        }

        if (str_starts_with($error, 'response failed verification') || ($status >= 200 && $status < 300)) {
            return ServerRegion::INVALID;
        }

        return ServerRegion::SERVER;
    }

    protected function endpoint(string $name): string
    {
        return rtrim($this->config['url'], '/').'/'.trim($this->config['path'], '/').'/'.$name;
    }
}
