<?php

return [
    'oauth_server' => [
        'uri'            => rtrim(env('API_SERVER_URL', env('OAUTH_SERVER_URL')), '/'),
        'ip'             => env('OAUTH_SERVER_IP'),
        'client_id'      => env('OAUTH_CLIENT_ID'),
        'client_secret'  => env('OAUTH_CLIENT_SECRET'),
        'redirect_uri'   => env('APP_URL').'/oauth/authenticate',
        'failure_uri'    => env('APP_URL').'/oauth/unauthenticated',
        'default_scopes' => [],
    ],

    'server' => [
        'uri'     => rtrim(env('API_SERVER_URL'), '/'),
        'ip'      => env('API_SERVER_IP'),
        'version' => env('API_SERVER_VERSION', 'latest'),
    ],

    'session_keys' => [
        'oauth_state'        => 'oauth.state',
        'oauth_access_token' => 'oauth.access_token',
    ],
];
