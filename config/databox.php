<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Databox API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Databox Ingestion API. These values are used
    | to authenticate and send data to the Databox platform.
    |
    */

    'api_key' => env('DATABOX_TOKEN'),
    'api_url' => env('DATABOX_ENDPOINT', 'https://push.databox.com/v1/input'),
    'timeout' => env('DATABOX_TIMEOUT', 30),
    'retry_attempts' => env('DATABOX_RETRY_ATTEMPTS', 3),
    'retry_delay' => env('DATABOX_RETRY_DELAY', 1000), // milliseconds
];

