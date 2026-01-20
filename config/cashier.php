<?php

return [
    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET'),
    'webhook' => [
        'secret' => env('STRIPE_WEBHOOK_SECRET'),
        'tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
        'events' => [
            'checkout.session.completed',
            'customer.subscription.deleted',
        ],
    ],
    'price_monthly' => env('STRIPE_PRICE_MONTHLY'),
    'price_lifetime' => env('STRIPE_PRICE_LIFETIME'),
];
