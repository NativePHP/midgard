<?php

// Stand-in for the nativephp_call() PHP extension function, which only
// exists on a device. Records each call and answers from $GLOBALS.

if (! function_exists('nativephp_call')) {
    function nativephp_call(string $method, string $params = '{}'): ?string
    {
        $GLOBALS['nativephp_test_raw'][] = $params;
        $GLOBALS['nativephp_test_calls'][] = ['method' => $method, 'params' => json_decode($params, true)];

        return $GLOBALS['nativephp_test_response'] ?? null;
    }
}
