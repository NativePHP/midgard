<?php

namespace Native\Api\Client\Asgard;

use Illuminate\Support\Facades\Log;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\Asgard\Contracts\FragmentSource;
use Native\Mobile\Edge\Asgard\RegionRequest;
use Native\Mobile\Edge\Asgard\ServerRegion;

/**
 * Fetches region fragments from an Asgard server over HTTP.
 *
 * The request runs on a background PHP lane (AsyncTask), so the UI never waits
 * on the network. The answer comes back on the UI thread, where
 * ServerRegion::deliver() hands it to the region
 * that asked (by request id, so a late answer to a superseded request is dropped)
 * and the screen repaints.
 *
 * Any failure leaves the region on its placeholder, which is the protocol's
 * intended degraded state (§3). Failures are logged, never thrown.
 */
class HttpFragmentSource implements FragmentSource
{
    public function __construct(
        protected string $url,
        protected string $path = '_asgard',
        protected float $timeout = 10,
    ) {}

    public function request(RegionRequest $request): void
    {
        $endpoint = rtrim($this->url, '/').'/'.trim($this->path, '/').'/render';
        // The wire body (protocol §9) leaves the protocol version to the transport.
        $body = ['protocol' => 1, ...$request->toArray()];
        $timeout = $this->timeout;

        AsyncTask::dispatch(static fn () => RenderCall::send($endpoint, $body, $timeout))
            ->finished(function ($result) use ($request, $endpoint) {
                if (is_array($result) && ($result['ok'] ?? false)) {
                    ServerRegion::deliver($request, $result['fragment']);

                    return;
                }

                Log::warning('Asgard: no fragment for region ['.$request->region.']', [
                    'endpoint' => $endpoint,
                    'status' => $result['status'] ?? null,
                    'error' => $result['error'] ?? null,
                ]);
            })
            ->failed(function (\Throwable $e) use ($request) {
                Log::warning('Asgard: fetching region ['.$request->region.'] failed: '.$e->getMessage());
            });
    }
}
