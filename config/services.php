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

    // Card payments (docs/cart.md, Card payments). Used by lunarphp/stripe, which reads these exact
    // keys: `key` is the secret key, `public_key` the publishable key, and `webhooks.lunar` the
    // signing secret of the webhook endpoint (/stripe/webhook). Card payment is off until the
    // secret and publishable keys are both set (App\Domain\Checkout\PaymentMethods).
    'stripe' => [
        'key' => env('STRIPE_SECRET_KEY'),
        'public_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhooks' => [
            'lunar' => env('STRIPE_WEBHOOK_SECRET'),
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
