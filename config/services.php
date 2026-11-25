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

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-1.5-flash'),
    ],

    'webhooks' => [
        'queue' => env('WEBHOOK_QUEUE', 'webhooks'),
        'require_https' => env('WEBHOOK_REQUIRE_HTTPS', true),
        'allowed_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('WEBHOOK_ALLOWED_HOSTS', ''))
        ))),
        'connect_timeout_seconds' => (int) env('WEBHOOK_CONNECT_TIMEOUT', 5),
        'timeout_seconds' => (int) env('WEBHOOK_TIMEOUT', 15),
        'max_response_bytes' => (int) env('WEBHOOK_MAX_RESPONSE_BYTES', 2000),
    ],

];
