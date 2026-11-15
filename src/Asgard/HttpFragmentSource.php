<?php

namespace Native\Api\Client\Asgard;

use Illuminate\Support\Facades\Log;
use Native\Api\Client\Asgard\Contracts\FragmentSource;
use Native\Mobile\AsyncTask;

/**
 * Fetches region fragments from an Asgard server over HTTP.
 *
 * The request runs on a background PHP lane (AsyncTask), so the UI never waits
 * on the network. The answer comes back on the UI thread, where
 * ServerRegion::deliver() hands it to the region that asked (by request id,
 * so a late answer to a superseded request is dropped) and the screen
 * repaints.
 *
 * Every request names `regions`, a list even for one region (protocol
 * §3.1), and every answer is a `fragments` map with one part per region.
 * Each region is still its own request on the device. The answer is handed
 * to ServerRegion::deliverBatch(), and a request that fails fails every
 * region in it through failedBatch().
 *
 * A server action (a tap inside a fragment, protocol §10.2) goes to the same
 * endpoint, naming its one region, with an `action` member, and is answered
 * the same way. Its answer can also carry other regions of the same
 * component and hints naming other components (from refresh() on the
 * server). Both are handed over with the action's own part, and the device
 * decides which owners they reach.
 *
 * A server screen (§12) is a region too, named `screen`, and its part
 * carries chrome next to the fragment. The chrome is handed over with it,
 * and the screen checks it before it wears it.
 *
 * Every call is signed and encrypted (protocol §7) by AsgardClient, which
 * also handshakes when there is no session. A fragment whose response does
 * not verify never reaches the region.
 *
 * Each part carries the region's version token, which is handed over with
 * the fragment. The device uses it to say what it already holds when it
 * subscribes to the push stream. It also carries the server's `made_at` and
 * `max_age`, which the region cache stores with it. A part of `cached: true`
 * (the copy the request declared is still good) keeps that copy, and a
 * signed `Asgard-Cache: invalidate` on the response is handed over too.
 *
 * A server may refuse a request that names more regions than it allows
 * with `422 too_many_regions`. The regions then go again as two requests of
 * half as many each, down to one region. One region refused that way fails
 * with reason `server`.
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
     * @param  array{base: string, key: string, timeout: float, store: ?string}  $config
     */
    public function __construct(
        protected array $config,
        protected ?RegionStreams $streams = null,
    ) {}

    public function request(RegionBatch $batch): void
    {
        static::dispatch($this->config(), $this->endpoint('render'), $batch);
    }

    public function action(ActionRequest $request): void
    {
        static::dispatch($this->config(), $this->endpoint('render'), new RegionBatch([$request]));
    }

    /** Send one render request on a background lane, and hand its answer over on return. */
    public static function dispatch(array $config, string $endpoint, RegionBatch $batch): void
    {
        // The wire body (protocol §9) leaves the protocol version to the transport.
        $body = ['protocol' => 1, ...$batch->toArray()];
        $what = static::describe($batch);

        AsyncTask::dispatch(static fn () => RenderCall::send($config, $body))
            // Named, not static::, as a callback may be bound to the screen.
            ->finished(function ($result) use ($batch, $config, $endpoint, $what) {
                HttpFragmentSource::answered($batch, $result, $endpoint, $what, $config);
            })
            ->failed(function (\Throwable $e) use ($batch, $what) {
                HttpFragmentSource::lost($batch, $e, $what);
            });
    }

    /** How a request reads in the log. */
    public static function describe(RegionBatch $batch): string
    {
        $action = $batch->action();

        return $action !== null
            ? "action [{$action->call}] in region [{$action->region}]"
            : 'regions ['.implode(', ', $batch->regions()).']';
    }

    /** The lane's config, with the app's extra headers as they are now. */
    protected function config(): array
    {
        return ['headers' => AsgardClient::configuredHeaders()] + $this->config;
    }

    public function watch(array $subscriptions): void
    {
        $this->streams?->watch($subscriptions);
    }

    /**
     * The render call came back: deliver each region's part, split the
     * regions when the server said there were too many, or fail them all.
     *
     * `$config` is the lane's config, used to send the halves again. Without
     * it a `too_many_regions` answer fails like any other.
     *
     * @param  mixed  $result  What RenderCall::send() returned.
     */
    public static function answered(RegionBatch $batch, mixed $result, string $endpoint, string $what, ?array $config = null): void
    {
        if (is_array($result) && ($result['ok'] ?? false) && is_array($result['fragments'] ?? null)) {
            ServerRegion::deliverBatch(
                $batch,
                $result['fragments'],
                ($result['invalidate'] ?? false) === true,
                $result['refresh'] ?? [],
            );

            return;
        }

        if ($config !== null && self::tooManyRegions($result) && ($halves = $batch->split()) !== null) {
            Log::info("Asgard: the server refused {$what} as too many regions. Asking again in two halves.");

            foreach ($halves as $half) {
                static::dispatch($config, $endpoint, $half);
            }

            return;
        }

        [$reason, $status, $error] = self::failure($result, $endpoint, $what);

        ServerRegion::failedBatch($batch, $reason, $status, $error);
    }

    /** Whether the server refused the request for naming more regions than it allows. */
    public static function tooManyRegions(mixed $result): bool
    {
        return is_array($result) && ($result['status'] ?? null) === 422 && ($result['error'] ?? null) === 'too_many_regions';
    }

    /** A render never came back: every region in it failed. */
    public static function lost(RegionBatch $batch, \Throwable $e, string $what): void
    {
        Log::warning("Asgard: fetching {$what} failed: ".$e->getMessage());

        ServerRegion::failedBatch($batch, ServerRegion::NETWORK, null, $e->getMessage());
    }

    /**
     * Log a failed answer and say why it failed: the reason, the HTTP status
     * (null when nothing came back) and the error.
     *
     * @return array{0: string, 1: ?int, 2: string}
     */
    protected static function failure(mixed $result, string $endpoint, string $what): array
    {
        $status = is_array($result) && is_int($result['status'] ?? null) ? $result['status'] : 0;
        $error = is_array($result) && is_string($result['error'] ?? null) ? $result['error'] : 'no answer';

        Log::warning("Asgard: no fragment for {$what}", [
            'endpoint' => $endpoint,
            'status' => $status,
            'error' => $error,
        ]);

        return [self::reasonFor($status, $error), $status === 0 ? null : $status, $error];
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
        return rtrim($this->config['base'], '/').'/'.$name;
    }
}
