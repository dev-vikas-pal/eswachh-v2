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

        /*
         * The WhatsApp Business account a template belongs to.
         *
         * Only the template export uses it. Defaulted to the namespace every
         * file in docs/msg91template carries, so exporting works on a fresh
         * checkout with nothing added to .env - it is an account identifier
         * rather than a credential, and it appears in every one of those files
         * already. A second WABA is the reason to set it.
         */
        'namespace' => env('MSG91_TEMPLATE_NAMESPACE', 'e73e5b95_f483_40b7_b637_5bf1d90ba813'),

        /*
         * Send every message here instead of to the customer.
         *
         * For testing on the live site, where the delivery gate means a real
         * message is the only kind there is. Empty means off, which is what it
         * must be the moment testing stops - while it is set, not one customer
         * is being messaged.
         */
        'test_recipient' => env('MSG91_TEST_RECIPIENT'),
    ],

    'razorpay' => [
        'key' => env('RAZORPAY_KEY'),
        'secret' => env('RAZORPAY_SECRET'),
        'enabled' => env('RAZORPAY_ENABLED', false),
        'base_url' => 'https://api.razorpay.com/v1',
    ],

];
