<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure CORS to allow the frontend to call the API and send cookies
    | (HttpOnly JWT). Adjust the allowed_origins for your environments.
    |
    */

    // Apply CORS to API routes only
    'paths' => ['api/*'],

    // Allowed HTTP methods
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Frontend origins allowed to access the API (exact match, no wildcard)
    // Configure production URL(s) in .env: FRONTEND_URL, FRONTEND_URL_ALT
    'allowed_origins' => array_values(array_filter([
        env('FRONTEND_URL'),
        env('FRONTEND_URL_ALT'),
        // Dev/local
        'http://192.168.147.1:8080',
        'http://127.0.0.1:8080',
        'http://localhost:8080',
    ])),

    'allowed_origins_patterns' => [],

    // Headers allowed in requests
    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'Accept', 'Origin', 'Authorization'],

    // Headers exposed in responses
    'exposed_headers' => [],

    // Cache preflight response (in seconds)
    'max_age' => 3600,

    // Allow cookies/credentials to be sent
    'supports_credentials' => true,

];
