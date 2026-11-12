<?php

return [

    /*
    | Where the app asks for Asgard fragments. Leave the URL empty and regions
    | simply keep their placeholders.
    */
    'asgard' => [
        'url' => env('ASGARD_SERVER_URL'),
        'path' => env('ASGARD_PATH', '_asgard'),
        'timeout' => (float) env('ASGARD_TIMEOUT', 10),

        // Hold a Server-Sent Events stream open per component so the server
        // can push fragments. The stream lives in native code. Off by
        // default: each open stream ties up a PHP worker on the server.
        'stream' => (bool) env('ASGARD_STREAM', false),
    ],

];
