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
    ],

];
