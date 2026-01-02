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
    'api_url' => env('DATABOX_ENDPOINT', 'https://api.databox.com/v1'),
    'dataset_id' => env('DATABOX_DATASET_ID'), // Default dataset ID (legacy)
    'dataset_id_github' => env('DATABOX_DATASET_ID_GITHUB'), // GitHub dataset ID
    'dataset_id_strava' => env('DATABOX_DATASET_ID_STRAVA'), // Strava dataset ID
    'timeout' => env('DATABOX_TIMEOUT', 30),
    'retry_attempts' => env('DATABOX_RETRY_ATTEMPTS', 3),
    'retry_delay' => env('DATABOX_RETRY_DELAY', 1000), // milliseconds
];

