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

    /*
     * Razorpay.
     *
     * `key` is public and reaches the browser; `secret` signs and verifies and
     * must never leave the server, so it is never exposed through an API
     * response or a Blade view.
     *
     * `enabled` decides whether we really talk to the gateway. Off in local and
     * test, where a payment is simulated and logged instead, so development can
     * never move real money.
     */
    /*
     * WhatsApp, via MSG91.
     *
     * `enabled` is only half the guard. Messenger also refuses to deliver
     * outside production and during tests, whatever this says - because in v1
     * a single config flag was the only thing between the test suite and real
     * customers' phones, and it was set everywhere.
     */
    'whatsapp' => [
        'enabled' => env('WHATSAPP_ENABLED', false),
        'key' => env('MSG91_AUTH_KEY'),
        'number' => env('MSG91_WHATSAPP_NUMBER'),
        'url' => env('MSG91_WHATSAPP_URL', 'https://api.msg91.com/api/v5/whatsapp/whatsapp-outbound-message/bulk/'),
    ],

    'razorpay' => [
        'key' => env('RAZORPAY_KEY'),
        'secret' => env('RAZORPAY_SECRET'),
        'enabled' => env('RAZORPAY_ENABLED', false),
        'base_url' => 'https://api.razorpay.com/v1',
    ],

];
