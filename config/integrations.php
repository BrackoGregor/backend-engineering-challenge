<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Integration Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for external data source integrations (GitHub, Strava, etc.)
    |
    */

    'github' => [
        'enabled' => env('GITHUB_ENABLED', true),
        'personal_access_token' => env('GITHUB_PERSONAL_ACCESS_TOKEN'),
        'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
        'timeout' => env('GITHUB_TIMEOUT', 30),
        'rate_limit_requests' => env('GITHUB_RATE_LIMIT_REQUESTS', 5000),
        'rate_limit_window' => env('GITHUB_RATE_LIMIT_WINDOW', 3600), // seconds
    ],

    'strava' => [
        'enabled' => env('STRAVA_ENABLED', true),
        'client_id' => env('STRAVA_CLIENT_ID'),
        'client_secret' => env('STRAVA_CLIENT_SECRET'),
        'redirect_uri' => env('STRAVA_REDIRECT_URI'),
        'api_url' => env('STRAVA_API_URL', 'https://www.strava.com/api/v3'),
        'auth_url' => env('STRAVA_AUTH_URL', 'https://www.strava.com/oauth/authorize'),
        'token_url' => env('STRAVA_TOKEN_URL', 'https://www.strava.com/oauth/token'),
        'timeout' => env('STRAVA_TIMEOUT', 30),
        'scope' => env('STRAVA_SCOPE', 'activity:read_all'),
    ],
];



