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

    /*
    | Identity registers used for borrower KYC. `sandbox` builds the same requests but answers
    | them locally with a successful match; set `live` and credentials to call the provider.
    */
    'nibss' => [
        'mode' => env('NIBSS_MODE', 'sandbox'),
        'base_url' => env('NIBSS_BASE_URL', 'https://api.nibss-plc.com.ng'),
        'client_id' => env('NIBSS_CLIENT_ID'),
        'client_secret' => env('NIBSS_CLIENT_SECRET'),
        'timeout' => (int) env('NIBSS_TIMEOUT', 10),
    ],

    'nimc' => [
        'mode' => env('NIMC_MODE', 'sandbox'),
        'base_url' => env('NIMC_BASE_URL', 'https://api.nimc.gov.ng'),
        'client_id' => env('NIMC_CLIENT_ID'),
        'client_secret' => env('NIMC_CLIENT_SECRET'),
        'timeout' => (int) env('NIMC_TIMEOUT', 10),
    ],

];
