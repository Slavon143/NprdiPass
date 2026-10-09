<?php

$config = [
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

    'commercial_enabled' => (bool) env('BILLING_COMMERCIAL_ENABLED', false),

    'trial' => [
        'plan_key' => 'growth', 'days' => 14,
        'entitlements' => [
            'catalog.products.max' => 25, 'catalog.total.max' => 100000,
            'members.max' => 2, 'storage.bytes.max' => 500000000,
            'imports.jobs.max' => 10, 'imports.enabled' => true,
            'fortnox.enabled' => true, 'api.enabled' => true, 'api.requests.max' => 1000,
            'webhooks.enabled' => false, 'public_passports.enabled' => true,
        ],
    ],
    'legacy_prices' => ['growth' => (string) env('STRIPE_PRICE_GROWTH', '')],
    'legacy_plans' => [
        'pilot' => ['entitlements' => ['catalog.products.max' => 100, 'imports.enabled' => true, 'fortnox.enabled' => true, 'public_passports.enabled' => true]],
        'growth' => ['entitlements' => ['catalog.products.max' => 1000, 'imports.enabled' => true, 'fortnox.enabled' => true, 'public_passports.enabled' => true]],
    ],

    'plans' => [
        'starter' => [
            'name' => 'Starter', 'self_service' => true, 'commercial_model' => 'v1',
            'monthly_eur' => 49, 'annual_eur' => 490, 'trial_days' => 0,
            'stripe_price_id' => (string) env('STRIPE_PRICE_STARTER_MONTHLY', ''),
            'stripe_annual_price_id' => (string) env('STRIPE_PRICE_STARTER_ANNUAL', ''),
            'entitlements' => [
                'catalog.products.max' => 100, 'catalog.total.max' => 100000,
                'members.max' => 2, 'storage.bytes.max' => 2000000000,
                'imports.jobs.max' => 10, 'imports.enabled' => true,
                'fortnox.enabled' => false, 'api.enabled' => false,
                'webhooks.enabled' => false, 'public_passports.enabled' => true,
            ],
        ],
        'business' => [
            'name' => 'Business', 'self_service' => true, 'commercial_model' => 'v1',
            'monthly_eur' => 399, 'annual_eur' => 3990, 'trial_days' => 0,
            'stripe_price_id' => (string) env('STRIPE_PRICE_BUSINESS_MONTHLY', ''),
            'stripe_annual_price_id' => (string) env('STRIPE_PRICE_BUSINESS_ANNUAL', ''),
            'entitlements' => [
                'catalog.products.max' => 10000, 'catalog.total.max' => 100000,
                'members.max' => 30, 'storage.bytes.max' => 100000000000,
                'imports.jobs.max' => 500, 'imports.enabled' => true,
                'fortnox.enabled' => true, 'api.enabled' => true, 'api.requests.max' => 500000,
                'webhooks.enabled' => true, 'public_passports.enabled' => true,
            ],
        ],
        'enterprise' => [
            'name' => 'Enterprise', 'self_service' => false, 'contact_sales' => true,
            'commercial_model' => 'v1', 'trial_days' => 0, 'entitlements' => [],
        ],

        'pilot' => [
            'name' => 'Pilot (legacy)',
            'self_service' => false,
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
            'name' => 'Growth', 'self_service' => true, 'commercial_model' => 'v1',
            'monthly_eur' => 149, 'annual_eur' => 1490, 'trial_days' => 0,
            'stripe_price_id' => (string) env('STRIPE_PRICE_GROWTH_MONTHLY', ''),
            'stripe_annual_price_id' => (string) env('STRIPE_PRICE_GROWTH_ANNUAL', ''),
            'entitlements' => [
                'catalog.products.max' => 1000, 'catalog.total.max' => 100000,
                'members.max' => 10, 'storage.bytes.max' => 20000000000,
                'imports.jobs.max' => 100, 'imports.enabled' => true,
                'fortnox.enabled' => true, 'api.enabled' => true, 'api.requests.max' => 50000,
                'webhooks.enabled' => true, 'public_passports.enabled' => true,
            ],
        ],
    ],

    'default_plan' => env('BILLING_DEFAULT_PLAN', 'pilot'),
];

// Keep the deployed purchase and registration policy until every commercial gate is ready.
$config['commercial_plans'] = $config['plans'];
if (! $config['commercial_enabled']) {
    $config['plans'] = [
        'pilot' => ['name' => 'Pilot', 'stripe_price_id' => (string) env('STRIPE_PRICE_PILOT', ''), 'trial_days' => 14,
            'entitlements' => $config['legacy_plans']['pilot']['entitlements']],
        'growth' => ['name' => 'Growth', 'stripe_price_id' => (string) env('STRIPE_PRICE_GROWTH', ''), 'trial_days' => 14,
            'entitlements' => $config['legacy_plans']['growth']['entitlements']],
    ];
    $config['legacy_prices'] = [];
}

return $config;
