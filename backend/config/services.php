<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    // SMS provider for order alerts (MARKETPLACE_SMS_DRIVER=afromessage). Keep the token out of git.
    'afromessage' => [
        'base_url' => env('AFROMESSAGE_BASE_URL', 'https://api.afromessage.com/api'),
        'token' => env('AFROMESSAGE_TOKEN'),
        'identifier_id' => env('AFROMESSAGE_IDENTIFIER_ID'),
        'sender' => env('AFROMESSAGE_SENDER'),
    ],

    // SMS Ethiopia (MARKETPLACE_SMS_DRIVER=smsethiopia). Keep the key out of git.
    'smsethiopia' => [
        'base_url' => env('SMSETHIOPIA_BASE_URL', 'https://smsethiopia.com/api'),
        'key' => env('SMSETHIOPIA_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Online payments. Keys come from the Chapa dashboard (Settings > API); test keys start with
    // CHASECK_TEST. The webhook secret is the "Secret hash" set for the webhook in the same place.
    'chapa' => [
        'secret_key' => env('CHAPA_SECRET_KEY'),
        'webhook_secret' => env('CHAPA_WEBHOOK_SECRET'),
        'base_url' => env('CHAPA_BASE_URL', 'https://api.chapa.co/v1'),
        'timeout' => (int) env('CHAPA_TIMEOUT', 15),
    ],

    // Google Maps. The browser key shows the map (restrict it to your site's address and the
    // Maps JavaScript API); the server key measures road distance (restrict it to the Routes API).
    // Without a server key, distance is a straight-line estimate; without a browser key the
    // address form offers "Use my location" instead of a map.
    'google_maps' => [
        'browser_key' => env('GOOGLE_MAPS_BROWSER_KEY'),
        'server_key' => env('GOOGLE_MAPS_SERVER_KEY'),
        'map_id' => env('GOOGLE_MAPS_MAP_ID', 'DEMO_MAP_ID'),
        'routes_url' => env('GOOGLE_ROUTES_URL', 'https://routes.googleapis.com/directions/v2:computeRoutes'),
        'timeout' => (int) env('GOOGLE_MAPS_TIMEOUT', 5),
        // Google bills each Routes call. Above this many new trips an hour (all visitors together),
        // distances are estimated from the straight line instead, so a script cannot run up the bill.
        'routes_per_hour' => (int) env('GOOGLE_ROUTES_PER_HOUR', 200),
    ],

];
