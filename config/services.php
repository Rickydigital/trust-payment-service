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

    'clickpesa' => [
        'base_url' => env('CLICKPESA_BASE_URL'),
        'api_key' => env('CLICKPESA_API_KEY'),
        'client_id' => env('CLICKPESA_CLIENT_ID'),
        'checksum_key' => env('CLICKPESA_CHECKSUM_KEY'),
    ],

    'azampay' => [
        'environment' => env('AZAMPAY_ENVIRONMENT', 'sandbox'),

        'app_name' => env('AZAMPAY_APP_NAME'),
        'client_id' => env('AZAMPAY_CLIENT_ID'),
        'client_secret' => env('AZAMPAY_CLIENT_SECRET'),
        'api_key' => env('AZAMPAY_API_KEY'),

        'auth_base_url' => env(
            'AZAMPAY_AUTH_BASE_URL',
            'https://authenticator-sandbox.azampay.co.tz'
        ),

        'checkout_base_url' => env(
            'AZAMPAY_CHECKOUT_BASE_URL',
            'https://sandbox.azampay.co.tz'
        ),

        'status_path' => env('AZAMPAY_STATUS_PATH'),

        'webhook_secret' => env('AZAMPAY_WEBHOOK_SECRET'),
    ],

    'main_platform' => [
        'url' => env('MAIN_PLATFORM_URL'),
        'internal_key' => env('INTERNAL_SERVICE_KEY'),
        'callback_secret' => env('PAYMENT_CALLBACK_SECRET'),
    ],

    'toms' => [
        'internal_key' => env('TOMS_INTERNAL_KEY'),
    ],

    'rick' => [
        'token' => env('RICK_CONNECTOR_TOKEN'),
    ],
];
