<?php

namespace Native\Api\Client\Support\Concerns;

use Illuminate\Support\Arr;

trait ConfiguresCurl
{
    /**
     * Resolve the given host to the given IP address. Pass a pre-filled config array as the last parameter to
     * update it accordingly.
     *
     * @param  string  $url
     * @param  string  $ip
     * @param  array  $config
     * @return array
     */
    protected function manuallyResolveHost($url, $ip, &$config = [])
    {
        $url = parse_url($url);

        $host = $url['host'];

        $scheme = 'unknown';

        if (isset($url['scheme'])) {
            $scheme = $url['scheme'];
        }

        if (isset($url['port'])) {
            $port = $url['port'];
        }

        if (! isset($port)) {
            switch ($scheme) {
                case 'http':
                    $port = 80;
                    break;

                case 'https':
                    $port = 443;
                    break;

                default:
                    $port = 443;
            }
        }

        $resolution = implode(':', [$host, $port, $ip]);

        return Arr::set($config, 'curl.'.CURLOPT_RESOLVE, [$resolution]);
    }
}
