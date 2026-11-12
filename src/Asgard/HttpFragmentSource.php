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
 * With streaming on, every region that asks is also subscribed to the
 * server's push stream (RegionStreams), so the server can send it a new
 * fragment without being asked.
 *
 * Any failure leaves the region as it was: on its placeholder, or on the
 * last fragment it painted. Failures are logged, never thrown.
 */
class HttpFragmentSource implements FragmentSource
{
    public function __construct(
        protected string $url,
        protected string $path = '_asgard',
        protected float $timeout = 10,
        protected ?RegionStreams $streams = null,
    ) {}

    public function request(RegionRequest $request): void
    {
        $this->send($request);

        $this->streams?->subscribe($request);
    }

    public function action(ActionRequest $request): void
    {
        $this->send($request);
    }

    protected function send(RegionRequest $request): void
    {
        $endpoint = $this->endpoint('render');
        // The wire body (protocol §9) leaves the protocol version to the transport.
        $body = ['protocol' => 1, ...$request->toArray()];
        $timeout = $this->timeout;
        $what = $request instanceof ActionRequest
            ? "action [{$request->call}] in region [{$request->region}]"
            : "region [{$request->region}]";

        AsyncTask::dispatch(static fn () => RenderCall::send($endpoint, $body, $timeout))
            ->finished(function ($result) use ($request, $endpoint, $what) {
                if (is_array($result) && ($result['ok'] ?? false)) {
                    ServerRegion::deliver($request, $result['fragment']);

                    return;
                }

                Log::warning("Asgard: no fragment for {$what}", [
                    'endpoint' => $endpoint,
                    'status' => $result['status'] ?? null,
                    'error' => $result['error'] ?? null,
                ]);
            })
            ->failed(function (\Throwable $e) use ($what) {
                Log::warning("Asgard: fetching {$what} failed: ".$e->getMessage());
            });
    }

    protected function endpoint(string $name): string
    {
        return rtrim($this->url, '/').'/'.trim($this->path, '/').'/'.$name;
    }
}
