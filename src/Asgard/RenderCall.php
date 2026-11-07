<?php

namespace Native\Api\Client\Asgard;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * One render request to an Asgard server.
 *
 * Runs on the background PHP lane of an AsyncTask, so it is static, takes and
 * returns plain arrays, and leans on nothing but Guzzle.
 *
 * Sent as POST with `X-Asgard-Method: QUERY`, the fallback the protocol
 * defines (§8). It gets through every proxy and WAF, and `php artisan serve`.
 * Negotiating real QUERY comes with the handshake.
 */
class RenderCall
{
    /**
     * @param  array<string, mixed>  $body  The RegionRequest wire body.
     * @return array{ok: bool, status: int, fragment?: mixed, error?: string}
     */
    public static function send(string $url, array $body, float $timeout): array
    {
        try {
            $response = (new Client)->post($url, [
                'json' => $body,
                'timeout' => $timeout,
                'http_errors' => false,
                'headers' => [
                    'Accept' => 'application/json',
                    'Asgard-Protocol' => '1',
                    'X-Asgard-Method' => 'QUERY',
                ],
            ]);
        } catch (GuzzleException $e) {
            return ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
        }

        $status = $response->getStatusCode();
        $json = json_decode((string) $response->getBody(), true);

        if ($status !== 200 || ! is_array($json) || ! array_key_exists('fragment', $json)) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => is_array($json) ? (string) ($json['error'] ?? 'bad response') : 'response was not JSON',
            ];
        }

        return ['ok' => true, 'status' => $status, 'fragment' => $json['fragment']];
    }
}
