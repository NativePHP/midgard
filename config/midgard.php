<?php

return [

    /*
    | Where the app asks for Asgard fragments. Leave the URL empty and regions
    | simply keep their placeholders.
    */
    'asgard' => [
        'url' => env('ASGARD_SERVER_URL'),
        'path' => env('ASGARD_PATH', '_asgard'),
        // Seconds one HTTP call may take, for renders, actions and #[Server]
        // calls alike. Keep it longer than the device's own timeouts
        // (nativephp.asgard.screen_timeout and action_timeout, 15 by
        // default). The device timeout is the one that should fire first,
        // so a slow server reads as a timeout. If this one fired first, the
        // same slow server would read as a network failure.
        'timeout' => (float) env('ASGARD_TIMEOUT', 20),

        // The app's Asgard key: the same value as the server's ASGARD_KEY.
        // It is a public identifier, not a secret. Without it, regions keep
        // their placeholders.
        'key' => env('ASGARD_KEY'),

        // Cache store for the session (protocol §6). Render calls run on
        // AsyncTask lanes and streams are signed on the UI lane, so the store
        // must be one every lane reads: not `array`. null means the app's
        // default store.
        'session_store' => env('ASGARD_SESSION_STORE'),

        // Hold one Server-Sent Events stream open per device, so the server
        // can say when a region on screen changed. The stream lives in
        // native code. Off by default: an open stream ties up a PHP worker
        // on the server for as long as it is open.
        'stream' => (bool) env('ASGARD_STREAM', false),
    ],

];
