<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Comma separated. A browser sends its origin without a trailing slash, so one pasted with a
    // slash ("https://shop.vercel.app/") would never match; spaces and trailing slashes are dropped.
    'allowed_origins' => array_values(array_filter(array_map(
        fn (string $origin) => rtrim(trim($origin), '/'),
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
