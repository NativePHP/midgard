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

        // The app's Asgard key: the same value as the server's ASGARD_KEY.
        // It is a public identifier, not a secret. Without it, regions keep
        // their placeholders.
        'key' => env('ASGARD_KEY'),

        // Cache store for the session (protocol §6). Render calls run on
        // AsyncTask lanes and streams are signed on the UI lane, so the store
        // must be one every lane reads: not `array`. null means the app's
        // default store.
        'session_store' => env('ASGARD_SESSION_STORE'),

        // Hold a Server-Sent Events stream open per component so the server
        // can push fragments. The stream lives in native code. Off by
        // default: each open stream ties up a PHP worker on the server.
        'stream' => (bool) env('ASGARD_STREAM', false),
    ],

];
