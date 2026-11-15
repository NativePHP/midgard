<?php

use Native\Api\Client\Asgard\ServerRegion;

return [

    /*
    | Where the app asks for Asgard fragments. Leave the URL empty and regions
    | simply keep their placeholders.
    */
    'asgard' => [
        // The server's base URL, {base} in the protocol (§8). Endpoints sit
        // below it: {base}/session, {base}/render and so on. It must be
        // https, with no query or fragment. Plain http is allowed only while
        // app.debug is on, for a server on the developer's machine.
        'base' => env('ASGARD_BASE_URL'),

        // Seconds one HTTP call may take, for renders, actions and #[Server]
        // calls alike. Keep it longer than the device's own timeouts
        // (screen_timeout and action_timeout below, 15 by default). The
        // device timeout is the one that should fire first, so a slow
        // server reads as a timeout. If this one fired first, the same
        // slow server would read as a network failure.
        'http_timeout' => (float) env('ASGARD_TIMEOUT', 20),

        // Seconds one upload from uploadToServer() may take over HTTP. A
        // 20 MB file on a slow mobile network takes well over the timeout
        // above. Keep it longer than upload_timeout below (120 by default),
        // for the same reason.
        'http_upload_timeout' => (float) env('ASGARD_UPLOAD_TIMEOUT', 150),

        // The app's Asgard key: the same value as the server's ASGARD_KEY.
        // It is a public identifier, not a secret. Without it, regions keep
        // their placeholders.
        'key' => env('ASGARD_KEY'),

        // Cache store for the session (protocol §6). Render calls run on
        // AsyncTask lanes and streams are signed on the UI lane, so the store
        // must be one every lane reads: not `array`. null means the app's
        // default store.
        'session_store' => env('ASGARD_SESSION_STORE'),

        // The region cache. The device keeps a copy of each region and
        // server screen it got, and paints it at once the next time the
        // region loads, even after the app was closed. It still asks the
        // server, and declares the copy, so the server can say the copy is
        // still good or send a new one.
        'cache' => [
            // Seconds a copy counts as good, for every region and server
            // screen. 0 turns the cache off. A region can set its own with
            // @server('stats', cacheTtl: 300), and 0 there turns it off for
            // that region. The server's max age for a region can cut it
            // shorter. A copy older than this is still painted while the
            // request is out, but it is not declared, so the server sends a
            // new fragment.
            'ttl' => (int) env('ASGARD_CACHE_TTL', 0),

            // The cache store the copies live in. It must keep them when the
            // app quits, so not `array`. null means the app's default store,
            // which NativePHP keeps in the app's persistent storage on the
            // device. Call RegionCache::flush() when the user logs out.
            'store' => env('ASGARD_CACHE_STORE'),
        ],

        // Hold one Server-Sent Events stream open per device, so the server
        // can say when a region on screen changed. The stream lives in
        // native code. Off by default: an open stream ties up a PHP worker
        // on the server for as long as it is open.
        'stream' => (bool) env('ASGARD_STREAM', false),

        // Seconds a server screen waits for its body. When none has
        // arrived by then, the app goes back to the screen below, or to `/`
        // when there is none, and fires ServerScreenFailed. A ServerScreen
        // subclass can override screenTimeout(). 0 turns the timer off, but
        // a hard failure (no network, a server error) still gives up at once.
        'screen_timeout' => (float) env('NATIVEPHP_ASGARD_SCREEN_TIMEOUT', 15),

        // Seconds a server action or #[Server] method waits for its answer.
        // After that ServerActionFailed fires on its screen. A component can
        // override serverActionTimeout(). 0 turns it off.
        'action_timeout' => (float) env('NATIVEPHP_ASGARD_ACTION_TIMEOUT', 15),

        // Seconds an @server region waits for its fragment, on the first
        // fetch and on every refresh. After that ServerRegionFailed fires on
        // its screen. A component can override serverRegionTimeout(). 0
        // turns it off, but a hard failure still fires the event at once.
        'region_timeout' => (float) env('NATIVEPHP_ASGARD_REGION_TIMEOUT', 15),

        // Seconds an uploadToServer() call waits for its answer. Longer than
        // action_timeout, since a large file on a mobile network is slow to
        // send. A component can override serverUploadTimeout(). 0 turns it off.
        'upload_timeout' => (float) env('NATIVEPHP_ASGARD_UPLOAD_TIMEOUT', 120),

        // The largest file uploadToServer() sends, in bytes. A bigger one is
        // not sent, and ServerActionFailed fires with reason `too_large`. The
        // server has its own limit too. The whole file is held in memory
        // while it is sealed, so keep it modest.
        'upload_max' => (int) env('NATIVEPHP_ASGARD_UPLOAD_MAX', 20 * 1024 * 1024),

        // Extra state sent with every Asgard request: region renders and
        // batches, server actions, #[Server] calls, uploads and each push
        // stream subscription. The protocol has no field for the platform,
        // app version or anything else about the app, so send what the
        // server should know here. A server component reads a key through a
        // public property of the same name, like any other state.
        //
        // Keys are property names: letters, digits and underscores, not
        // starting with a digit. Values are scalars, null or arrays of
        // those. A value may also be a callable, called each time a request
        // is built: use [SomeClass::class, 'staticMethod'] rather than a
        // closure, because a closure here stops `php artisan config:cache`
        // working, and NativePHP runs that on the device.
        //
        // A component's own public prop or #[Computed] of the same name
        // wins, and its value is sent instead. These keys are not props of
        // the component, so a server delta that names one is ignored.
        //
        // They are part of the state, so they are part of what a shared
        // push region is cached by. Keep the values stable: a region asks
        // the server again when its state changes.
        'state' => [
            'platform' => [ServerRegion::class, 'platform'],
        ],

        // Extra HTTP headers sent with every Asgard request, the push stream
        // and the session handshake included. A value may be a string or a
        // callable, as for `state` above. A callable that gives null leaves
        // the header out.
        //
        // TLS covers these, but the protocol's body encryption and signature
        // do not, so do not use them for anything the encryption is meant to
        // protect. A shared push region is cached without them, so it must
        // not depend on one: send it in `state` instead.
        //
        // Names the transport or the protocol already uses are refused with
        // an exception: Accept, Authorization, Connection, Content-*,
        // Cookie, Host, Last-Event-ID, Signature, Signature-Input,
        // Transfer-Encoding, the method override headers and anything
        // starting Asgard- or X-Asgard-.
        'headers' => [
            // 'X-App-Version' => '1.4.0',
        ],

        // The class every server route opens. Set it to your own subclass of
        // Native\Api\Client\Asgard\ServerScreen to change its skeleton or
        // timeout. null means the package's own.
        'screen' => null,
    ],

];
