<?php

return [

    'default' => env('PAYMENTS_TYPE', 'cash-in-hand'),

    'types' => [
        // 30 day account terms, for customers staff have enabled them for: the order is placed
        // and waits for payment (App\Domain\Checkout\PaymentMethods).
        'account-30-day' => [
            'driver' => 'offline',
            'authorized' => 'awaiting-payment',
        ],

        // Card, through Stripe (lunarphp/stripe, docs/cart.md). The order is placed only once Stripe
        // reports the payment as succeeded.
        'card' => [
            'driver' => 'stripe',
        ],

        'cash-in-hand' => [
            'driver' => 'offline',
            'authorized' => 'payment-offline',
        ],
    ],

];
