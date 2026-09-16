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

    'ups' => [
        'oauth_url' => env('UPS_OAUTH_URL', 'https://onlinetools.ups.com/security/v1/oauth/token'),
        'oauth_url_test' => env('UPS_OAUTH_URL_TEST', 'https://wwwcie.ups.com/security/v1/oauth/token'),
        'rate_url' => env('UPS_RATE_URL', 'https://onlinetools.ups.com/api/rating/v2409/rate'),
        'rate_url_test' => env('UPS_RATE_URL_TEST', 'https://wwwcie.ups.com/api/rating/v2409/rate'),
        'time_in_transit_url' => env('UPS_TIME_IN_TRANSIT_URL', 'https://onlinetools.ups.com/api/shipments/v1/transittimes'),
        'time_in_transit_url_test' => env('UPS_TIME_IN_TRANSIT_URL_TEST', 'https://wwwcie.ups.com/api/shipments/v1/transittimes'),
        'tracking_url' => env('UPS_TRACKING_URL', 'https://onlinetools.ups.com/api/track/v1/details'),
        'tracking_url_test' => env('UPS_TRACKING_URL_TEST', 'https://wwwcie.ups.com/api/track/v1/details'),
        'tracking_reference_url' => env('UPS_TRACKING_REFERENCE_URL', 'https://onlinetools.ups.com/api/track/v1/reference/details'),
        'tracking_reference_url_test' => env('UPS_TRACKING_REFERENCE_URL_TEST', 'https://wwwcie.ups.com/api/track/v1/reference/details'),
        'ship_url' => env('UPS_SHIP_URL', 'https://onlinetools.ups.com/api/shipments/v2409/ship'),
        'ship_url_test' => env('UPS_SHIP_URL_TEST', 'https://wwwcie.ups.com/api/shipments/v2409/ship'),
        'transaction_src' => env('UPS_TRANSACTION_SRC', 'testing'),
    ],

    'dhl' => [
        'api_url' => env('DHL_API_URL', 'https://express.api.dhl.com/mydhlapi'),
        'api_url_test' => env('DHL_API_URL_TEST', 'https://express.api.dhl.com/mydhlapi/test'),
    ],

    'restcountries' => [
        'api_key' => env('RESTCOUNTRIES_API_KEY'),
        'base_url' => env('RESTCOUNTRIES_BASE_URL', 'https://api.restcountries.com/countries/v5'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

    // Cloudflare R2 (S3-compatible) credentials for storing shipment label files — see R2Service.
    'r2' => [
        'access_key_id' => env('R2_ACCESS_KEY_ID'),
        'secret_access_key' => env('R2_SECRET_ACCESS_KEY'),
        'bucket' => env('R2_BUCKET'),
        'endpoint' => env('R2_ENDPOINT'),
    ],

];
