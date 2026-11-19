<?php

return [

    'default' => env('PAYMENTS_TYPE', 'cash-in-hand'),

    'types' => [
        // Checkout's one payment type for now: the order is placed and waits for the customer's
        // bank transfer. Card (Stripe) and account terms come later.
        'bank-transfer' => [
            'driver' => 'offline',
            'authorized' => 'awaiting-payment',
        ],

        'cash-in-hand' => [
            'driver' => 'offline',
            'authorized' => 'payment-offline',
        ],
    ],

];
