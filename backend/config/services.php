<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
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

    'dots' => [
        'base_url' => env('DOTS_API_BASE_URL'),

        'account_token' => env('DOTS_API_ACCOUNT_TOKEN'),

        'token' => env('DOTS_API_TOKEN'),

        'auth_token' => env('DOTS_API_AUTH_TOKEN'),

        'api_version' => env('DOTS_API_VERSION', '2.1.0'),

        'city_id' => env('DOTS_CITY_ID'),

        'company_id' => env('DOTS_COMPANY_ID'),

        'company_address_id' => env('DOTS_COMPANY_ADDRESS_ID'),

        'catalog_cache_ttl_seconds' => env(
            'DOTS_CATALOG_CACHE_TTL_SECONDS',
            300,
        ),
    ],

    'internal' => [
        'token' => env('INTERNAL_API_TOKEN'),

        'session_store' => env('INTERNAL_SESSION_STORE', 'redis'),

        'session_ttl_seconds' => (int) env('INTERNAL_SESSION_TTL_SECONDS', 86400),

        'session_key_prefix' => env('INTERNAL_SESSION_KEY_PREFIX', 'internal-session'),

        'restaurant_slug' => env('INTERNAL_RESTAURANT_SLUG'),
    ],

];
