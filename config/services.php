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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'odoo' => [
        'url' => env('ODOO_URL'),
        'database' => env('ODOO_DATABASE'),
        'username' => env('ODOO_USERNAME'),
        'password' => env('ODOO_PASSWORD'),
        'api_key' => env('ODOO_API_KEY'),
        'timeout' => env('ODOO_TIMEOUT', 15),
        'quote_request_team_id' => env('ODOO_QUOTE_REQUEST_TEAM_ID'),
        'quote_request_user_id' => env('ODOO_QUOTE_REQUEST_USER_ID'),
    ],

    'orders' => [
        'administration_email' => env('ORDER_ADMINISTRATION_EMAIL'),
    ],

];
