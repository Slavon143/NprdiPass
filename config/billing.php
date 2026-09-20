<?php

return [
    'provider' => env(
        'BILLING_PROVIDER',
        in_array(env('APP_ENV', 'production'), ['local', 'testing'], true) ? 'fake' : 'stripe',
    ),

    'stripe_secret' => (string) env('STRIPE_SECRET', ''),
    'stripe_webhook_secret' => (string) env('STRIPE_WEBHOOK_SECRET', ''),
    'stripe_api_base' => rtrim((string) env('STRIPE_API_BASE', 'https://api.stripe.com/v1'), '/'),
    'stripe_webhook_tolerance_seconds' => (int) env('STRIPE_WEBHOOK_TOLERANCE_SECONDS', 300),
    'ca_bundle' => (string) env('STRIPE_CA_BUNDLE', ''),

    'success_path' => '/settings/billing?checkout=success',
    'cancel_path' => '/settings/billing?checkout=cancelled',

    'plans' => [
        'pilot' => [
            'name' => 'Pilot',
            'stripe_price_id' => (string) env('STRIPE_PRICE_PILOT', ''),
            'trial_days' => 14,
            'entitlements' => [
                'catalog.products.max' => 100,
                'imports.enabled' => true,
                'fortnox.enabled' => true,
                'public_passports.enabled' => true,
            ],
        ],
        'growth' => [
            'name' => 'Growth',
            'stripe_price_id' => (string) env('STRIPE_PRICE_GROWTH', ''),
            'trial_days' => 14,
            'entitlements' => [
                'catalog.products.max' => 1000,
                'imports.enabled' => true,
                'fortnox.enabled' => true,
                'public_passports.enabled' => true,
            ],
        ],
    ],

    'default_plan' => env('BILLING_DEFAULT_PLAN', 'pilot'),
];
