<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Products\ProductAggregateCreator;
use App\Actions\Fortnox\ConnectFortnoxAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Data\Billing\StripeCheckoutSession;
use App\Data\Billing\StripeSubscriptionProjection;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\Billing\StripeWebhookEventStatus;
use App\Enums\CompanyRole;
use App\Enums\Imports\ImportMode;
use App\Exceptions\Billing\BillingProviderException;
use App\Models\Billing\CompanySubscription;
use App\Models\Billing\StripeWebhookEvent;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Billing\Contracts\StripeBillingProvider;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\FakeStripeBillingProvider;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

function billingContext(CompanyRole $role = CompanyRole::Owner): array
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create([
        'billing_email' => 'billing@nordipass.test',
    ]);

    $factory = CompanyMembership::factory()
        ->for($actor, 'user')
        ->for($company);

    (match ($role) {
        CompanyRole::Owner => $factory->owner(),
        CompanyRole::Admin => $factory->admin(),
        CompanyRole::Editor => $factory->editor(),
        CompanyRole::Viewer => $factory->viewer(),
    })->create();

    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company];
}

function signedStripeWebhook(array $payload, string $secret = 'whsec_test'): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    return [$body, "t={$timestamp},v1={$signature}"];
}

test('owner can view billing and start checkout through fake provider', function () {
    config()->set('features.billing', true);

    [$actor, $company] = billingContext();

    $this->actingAs($actor)
        ->get(route('settings.billing.index'))
        ->assertOk()
        ->assertSee('Billing')
        ->assertSee('data-testid="navigation-billing"', false)
        ->assertSee('Pilot');

    $this->actingAs($actor)
        ->post(route('settings.billing.checkout'), ['plan' => 'pilot'])
        ->assertRedirect(route('settings.billing.index', ['checkout' => 'fake', 'plan' => 'pilot']));
});

test('billing index renders checkout return feedback', function () {
    config()->set('features.billing', true);

    [$actor] = billingContext();

    $this->actingAs($actor)
        ->get(route('settings.billing.index', ['checkout' => 'success']))
        ->assertOk()
        ->assertSee('Checkout completed');

    $this->actingAs($actor)
        ->get(route('settings.billing.index', ['checkout' => 'cancelled']))
        ->assertOk()
        ->assertSee('Checkout was cancelled');

    $this->actingAs($actor)
        ->get(route('settings.billing.index', ['checkout' => 'fake']))
        ->assertOk()
        ->assertSee('Simulated checkout completed');

    $this->actingAs($actor)
        ->get(route('settings.billing.index', ['checkout' => 'unexpected']))
        ->assertOk()
        ->assertDontSee('Checkout completed');
});

test('viewer cannot manage billing', function () {
    config()->set('features.billing', true);

    [$actor] = billingContext(CompanyRole::Viewer);

    $this->actingAs($actor)
        ->get(route('settings.billing.index'))
        ->assertForbidden();
});

test('stripe webhook rejects invalid signature', function () {
    config()->set('features.billing', true);
    config()->set('billing.stripe_webhook_secret', 'whsec_test');

    $this->call(
        'POST',
        route('stripe.webhook'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=invalid',
        ],
        '{"id":"evt_bad","type":"customer.subscription.updated"}',
    )->assertBadRequest();
});

test('stripe subscription webhook is idempotent and updates company projection', function () {
    config()->set('features.billing', true);
    config()->set('billing.stripe_webhook_secret', 'whsec_test');
    config()->set('billing.plans.pilot.stripe_price_id', 'price_pilot');

    $company = Company::factory()->create();
    $payload = [
        'id' => 'evt_subscription_updated',
        'type' => 'customer.subscription.updated',
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'sub_123',
                'customer' => 'cus_123',
                'status' => 'active',
                'current_period_end' => now()->addMonth()->timestamp,
                'metadata' => [
                    'company_uuid' => $company->uuid,
                    'plan_key' => 'pilot',
                ],
                'items' => [
                    'data' => [[
                        'price' => ['id' => 'price_pilot'],
                    ]],
                ],
            ],
        ],
    ];

    [$body, $signature] = signedStripeWebhook($payload);

    $this->call('POST', route('stripe.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $body)->assertOk();

    $this->call('POST', route('stripe.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $body)->assertOk();

    expect(StripeWebhookEvent::query()->count())->toBe(1)
        ->and(StripeWebhookEvent::query()->first()?->status)->toBe(StripeWebhookEventStatus::Processed)
        ->and(CompanySubscription::query()->count())->toBe(1);

    $subscription = CompanySubscription::query()->firstOrFail();

    expect($subscription->company_id)->toBe($company->getKey())
        ->and($subscription->stripe_customer_id)->toBe('cus_123')
        ->and($subscription->stripe_subscription_id)->toBe('sub_123')
        ->and($subscription->status)->toBe(BillingSubscriptionStatus::Active)
        ->and($subscription->entitlements)->toHaveKey('catalog.products.max');
});

test('billing reconcile command refreshes local projections', function () {
    config()->set('features.billing', true);
    config()->set('billing.provider', 'fake');

    $company = Company::factory()->create();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_old',
        'stripe_subscription_id' => 'sub_reconcile',
        'stripe_price_id' => 'price_old',
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::PastDue,
    ]);

    $this->artisan('billing:reconcile')
        ->expectsOutputToContain('checked=1 updated=1 skipped=0')
        ->assertSuccessful();

    $subscription = CompanySubscription::query()->firstOrFail();

    expect($subscription->status)->toBe(BillingSubscriptionStatus::Active)
        ->and($subscription->last_reconciled_at)->not->toBeNull();
});

test('canceled subscription blocks default plan entitlement fallback', function () {
    $company = Company::factory()->create();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_canceled',
        'stripe_subscription_id' => 'sub_canceled',
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::Canceled,
        'entitlements' => [
            'catalog.products.max' => 100,
            'imports.enabled' => true,
        ],
    ]);

    $service = app(EntitlementService::class);

    expect($service->value($company, 'catalog.products.max'))->toBeNull()
        ->and($service->enabled($company, 'imports.enabled'))->toBeFalse();
});

test('past due subscription grants entitlements only inside grace window', function () {
    $company = Company::factory()->create();
    $subscription = CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_grace',
        'stripe_subscription_id' => 'sub_grace',
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::PastDue,
        'entitlements' => [
            'imports.enabled' => true,
        ],
        'grace_ends_at' => now()->addDay(),
    ]);

    $service = app(EntitlementService::class);

    expect($service->enabled($company, 'imports.enabled'))->toBeTrue();

    $subscription->forceFill(['grace_ends_at' => now()->subMinute()])->save();

    expect($service->enabled($company, 'imports.enabled'))->toBeFalse();
});

test('checkout session completed webhook creates a pending projection', function () {
    config()->set('features.billing', true);
    config()->set('billing.stripe_webhook_secret', 'whsec_test');
    config()->set('billing.plans.pilot.stripe_price_id', 'price_pilot');

    $company = Company::factory()->create();
    $payload = [
        'id' => 'evt_checkout_completed',
        'type' => 'checkout.session.completed',
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'cs_test_123',
                'client_reference_id' => $company->uuid,
                'customer' => 'cus_checkout',
                'subscription' => null,
                'metadata' => [
                    'company_uuid' => $company->uuid,
                    'plan_key' => 'pilot',
                ],
            ],
        ],
    ];

    [$body, $signature] = signedStripeWebhook($payload);

    $this->call('POST', route('stripe.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $body)->assertOk();

    $subscription = CompanySubscription::query()->firstOrFail();

    expect($subscription->company_id)->toBe($company->getKey())
        ->and($subscription->status)->toBe(BillingSubscriptionStatus::Pending)
        ->and($subscription->plan_key)->toBe('pilot')
        ->and($subscription->stripe_customer_id)->toBe('cus_checkout')
        ->and($subscription->stripe_subscription_id)->toBeNull()
        ->and($subscription->grantsEntitlements())->toBeFalse();
});

test('checkout session completed webhook is idempotent and does not downgrade a confirmed subscription', function () {
    config()->set('features.billing', true);
    config()->set('billing.stripe_webhook_secret', 'whsec_test');
    config()->set('billing.plans.pilot.stripe_price_id', 'price_pilot');

    $company = Company::factory()->create();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_confirmed',
        'stripe_subscription_id' => 'sub_confirmed',
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::Active,
        'entitlements' => ['catalog.products.max' => 100],
    ]);

    $payload = [
        'id' => 'evt_checkout_late',
        'type' => 'checkout.session.completed',
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'cs_test_late',
                'client_reference_id' => $company->uuid,
                'customer' => 'cus_checkout',
                'subscription' => 'sub_new',
                'metadata' => [
                    'company_uuid' => $company->uuid,
                    'plan_key' => 'pilot',
                ],
            ],
        ],
    ];

    [$body, $signature] = signedStripeWebhook($payload);

    $this->call('POST', route('stripe.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $body)->assertOk();

    $subscription = CompanySubscription::query()->firstOrFail();

    expect($subscription->status)->toBe(BillingSubscriptionStatus::Active)
        ->and($subscription->stripe_subscription_id)->toBe('sub_confirmed')
        ->and($subscription->stripe_customer_id)->toBe('cus_confirmed');
});

test('pending projection is confirmed by a later subscription webhook', function () {
    config()->set('features.billing', true);
    config()->set('billing.stripe_webhook_secret', 'whsec_test');
    config()->set('billing.plans.pilot.stripe_price_id', 'price_pilot');

    $company = Company::factory()->create();

    $checkoutPayload = [
        'id' => 'evt_checkout_first',
        'type' => 'checkout.session.completed',
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'cs_test_first',
                'client_reference_id' => $company->uuid,
                'customer' => 'cus_flow',
                'subscription' => 'sub_flow',
                'metadata' => [
                    'company_uuid' => $company->uuid,
                    'plan_key' => 'pilot',
                ],
            ],
        ],
    ];

    [$checkoutBody, $checkoutSignature] = signedStripeWebhook($checkoutPayload);

    $this->call('POST', route('stripe.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $checkoutSignature,
    ], $checkoutBody)->assertOk();

    expect(CompanySubscription::query()->firstOrFail()->status)->toBe(BillingSubscriptionStatus::Pending);

    $subscriptionPayload = [
        'id' => 'evt_subscription_created',
        'type' => 'customer.subscription.created',
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'sub_flow',
                'customer' => 'cus_flow',
                'status' => 'trialing',
                'trial_end' => now()->addDays(14)->timestamp,
                'current_period_end' => now()->addDays(14)->timestamp,
                'metadata' => [
                    'company_uuid' => $company->uuid,
                    'plan_key' => 'pilot',
                ],
                'items' => [
                    'data' => [[
                        'price' => ['id' => 'price_pilot'],
                    ]],
                ],
            ],
        ],
    ];

    [$subscriptionBody, $subscriptionSignature] = signedStripeWebhook($subscriptionPayload);

    $this->call('POST', route('stripe.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $subscriptionSignature,
    ], $subscriptionBody)->assertOk();

    $subscription = CompanySubscription::query()->firstOrFail();

    expect($subscription->status)->toBe(BillingSubscriptionStatus::Trialing)
        ->and($subscription->trial_ends_at)->not->toBeNull()
        ->and($subscription->grantsEntitlements())->toBeTrue();
});

test('stripe provider grants no entitlements without a subscription', function () {
    config()->set('billing.provider', 'stripe');

    $company = Company::factory()->create();
    $service = app(EntitlementService::class);

    expect($service->value($company, 'catalog.products.max'))->toBeNull()
        ->and($service->enabled($company, 'imports.enabled'))->toBeFalse()
        ->and($service->enabled($company, 'fortnox.enabled'))->toBeFalse()
        ->and($service->enabled($company, 'public_passports.enabled'))->toBeFalse();
});

test('pending subscription grants no entitlements and blocks duplicate checkout', function () {
    config()->set('features.billing', true);

    [$actor, $company] = billingContext();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_pending',
        'stripe_subscription_id' => null,
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::Pending,
        'entitlements' => ['imports.enabled' => true],
    ]);

    $service = app(EntitlementService::class);

    expect($service->enabled($company, 'imports.enabled'))->toBeFalse();

    $this->actingAs($actor)
        ->post(route('settings.billing.checkout'), ['plan' => 'growth'])
        ->assertSessionHas('error', 'This company already has a subscription that is pending or active (pending).');
});

test('any non-terminal subscription blocks checkout for every plan', function () {
    config()->set('features.billing', true);

    [$actor, $company] = billingContext();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_active',
        'stripe_subscription_id' => 'sub_active',
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::Active,
    ]);

    $this->actingAs($actor)
        ->post(route('settings.billing.checkout'), ['plan' => 'pilot'])
        ->assertSessionHas('error', 'This company already has a subscription that is pending or active (active).');

    $this->actingAs($actor)
        ->post(route('settings.billing.checkout'), ['plan' => 'growth'])
        ->assertSessionHas('error', 'This company already has a subscription that is pending or active (active).');
});

test('checkout with a canceled subscription is allowed again', function () {
    config()->set('features.billing', true);

    [$actor, $company] = billingContext();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_canceled_renew',
        'stripe_subscription_id' => 'sub_canceled_renew',
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::Canceled,
    ]);

    $this->actingAs($actor)
        ->post(route('settings.billing.checkout'), ['plan' => 'pilot'])
        ->assertRedirect(route('settings.billing.index', ['checkout' => 'fake', 'plan' => 'pilot']));
});

test('checkout is blocked when stripe has a non-terminal subscription for the same customer', function () {
    config()->set('features.billing', true);
    config()->set('billing.provider', 'stripe');

    [$actor, $company] = billingContext();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_drift',
        'stripe_subscription_id' => 'sub_old_canceled',
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::Canceled,
    ]);

    // Local projection says canceled, but Stripe still has an active subscription (drift).
    app()->instance(StripeBillingProvider::class, new class extends FakeStripeBillingProvider
    {
        public function listSubscriptions(string $stripeCustomerId): array
        {
            return [
                new StripeSubscriptionProjection(
                    stripeCustomerId: $stripeCustomerId,
                    stripeSubscriptionId: 'sub_still_active',
                    stripePriceId: null,
                    planKey: 'pilot',
                    status: BillingSubscriptionStatus::Active,
                ),
            ];
        }
    });

    $this->actingAs($actor)
        ->post(route('settings.billing.checkout'), ['plan' => 'pilot'])
        ->assertSessionHas('error', 'This company already has a subscription that is pending or active (active).');

    $subscription = CompanySubscription::query()->forCompany($company)->first();

    expect($subscription?->status)->toBe(BillingSubscriptionStatus::Canceled)
        ->and($subscription?->stripe_subscription_id)->toBe('sub_old_canceled');
});

test('checkout claim is released when the provider fails', function () {
    config()->set('features.billing', true);
    config()->set('billing.provider', 'stripe');

    [$actor, $company] = billingContext();

    app()->instance(StripeBillingProvider::class, new class extends FakeStripeBillingProvider
    {
        public function createCheckoutSession(Company $company, string $planKey, string $successUrl, string $cancelUrl): StripeCheckoutSession
        {
            throw new BillingProviderException('Stripe is unavailable.');
        }
    });

    $this->actingAs($actor)
        ->post(route('settings.billing.checkout'), ['plan' => 'pilot'])
        ->assertSessionHas('error', 'Stripe is unavailable.');

    expect(CompanySubscription::query()->forCompany($company)->first())->toBeNull();
});

test('second checkout attempt is blocked by the pending claim from the first', function () {
    config()->set('features.billing', true);
    config()->set('billing.provider', 'stripe');

    [$actor, $company] = billingContext();

    app()->instance(StripeBillingProvider::class, new class extends FakeStripeBillingProvider
    {
        public function createCheckoutSession(Company $company, string $planKey, string $successUrl, string $cancelUrl): StripeCheckoutSession
        {
            return new StripeCheckoutSession(
                id: 'cs_claim_'.$company->uuid,
                url: 'https://checkout.stripe.test/session',
            );
        }
    });

    $this->actingAs($actor)
        ->post(route('settings.billing.checkout'), ['plan' => 'pilot'])
        ->assertRedirect('https://checkout.stripe.test/session');

    // The first attempt claimed a pending row; a second attempt must be blocked.
    expect(CompanySubscription::query()->forCompany($company)->first()?->status)->toBe(BillingSubscriptionStatus::Pending);

    $this->actingAs($actor)
        ->post(route('settings.billing.checkout'), ['plan' => 'pilot'])
        ->assertSessionHas('error', 'This company already has a subscription that is pending or active (pending).');
});

test('billing index shows confirming state for a pending subscription', function () {
    config()->set('features.billing', true);

    [$actor, $company] = billingContext();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_pending_ui',
        'stripe_subscription_id' => null,
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::Pending,
    ]);

    $this->actingAs($actor)
        ->get(route('settings.billing.index', ['checkout' => 'success']))
        ->assertOk()
        ->assertSee('Checkout completed')
        ->assertSee('Confirming subscription…')
        ->assertDontSee('No subscription');
});

test('billing index shows no plan and no product limit without a subscription', function () {
    config()->set('features.billing', true);
    config()->set('billing.provider', 'stripe');

    [$actor, $company] = billingContext();
    app(ProductAggregateCreator::class)->create($actor, $company, [
        'name' => 'Existing product',
        'slug' => 'existing-product',
        'short_description' => null,
        'description' => null,
        'brand' => null,
        'manufacturer' => null,
    ], [
        'name' => 'Default',
        'sku' => null,
        'sku_normalized' => null,
        'gtin' => null,
        'mpn' => null,
        'sort_order' => 0,
    ]);

    $this->actingAs($actor)
        ->get(route('settings.billing.index'))
        ->assertOk()
        ->assertSee('No subscription')
        ->assertDontSee('unlimited')
        ->assertDontSee('/ 100');
});

test('billing index renders human readable entitlement labels instead of raw keys', function () {
    config()->set('features.billing', true);

    [$actor] = billingContext();

    $this->actingAs($actor)
        ->get(route('settings.billing.index'))
        ->assertOk()
        ->assertSee('Max products')
        ->assertSee('Production imports')
        ->assertSee('Fortnox integration')
        ->assertSee('Public passports')
        ->assertDontSee('catalog.products.max')
        ->assertDontSee('imports.enabled')
        ->assertDontSee('fortnox.enabled')
        ->assertDontSee('public_passports.enabled');
});

test('failed subscription webhook persists a failed inbox row instead of vanishing', function () {
    config()->set('features.billing', true);
    config()->set('billing.stripe_webhook_secret', 'whsec_test');

    $payload = [
        'id' => 'evt_unresolvable_company',
        'type' => 'customer.subscription.created',
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'sub_missing_company',
                'customer' => 'cus_missing',
                'status' => 'active',
                'metadata' => [
                    'company_uuid' => '00000000-0000-0000-0000-000000000000',
                    'plan_key' => 'pilot',
                ],
                'items' => ['data' => []],
            ],
        ],
    ];

    [$body, $signature] = signedStripeWebhook($payload);

    $this->call('POST', route('stripe.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $body)->assertBadRequest();

    $event = StripeWebhookEvent::query()->where('stripe_event_id', 'evt_unresolvable_company')->first();

    expect($event)->not->toBeNull()
        ->and($event?->status)->toBe(StripeWebhookEventStatus::Failed)
        ->and($event?->error_message)->not->toBeNull();
});

test('modern stripe payload resolves billing period from subscription items', function () {
    config()->set('features.billing', true);
    config()->set('billing.stripe_webhook_secret', 'whsec_test');
    config()->set('billing.plans.pilot.stripe_price_id', 'price_pilot');

    $company = Company::factory()->create();

    // Modern Stripe shape: no top-level current_period_end; the period lives on the item.
    $payload = [
        'id' => 'evt_modern_items',
        'type' => 'customer.subscription.updated',
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'sub_modern',
                'customer' => 'cus_modern',
                'status' => 'active',
                'metadata' => [
                    'company_uuid' => $company->uuid,
                    'plan_key' => 'pilot',
                ],
                'items' => [
                    'data' => [[
                        'id' => 'si_modern',
                        'price' => ['id' => 'price_pilot'],
                        'current_period_start' => now()->startOfMonth()->timestamp,
                        'current_period_end' => now()->addMonth()->startOfMonth()->timestamp,
                    ]],
                ],
            ],
        ],
    ];

    [$body, $signature] = signedStripeWebhook($payload);

    $this->call('POST', route('stripe.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $body)->assertOk();

    $subscription = CompanySubscription::query()->firstOrFail();

    expect($subscription->current_period_start)->not->toBeNull()
        ->and($subscription->current_period_end)->not->toBeNull()
        ->and($subscription->current_period_end->timestamp)->toBe(now()->addMonth()->startOfMonth()->timestamp);
});

test('trialing pilot subscription webhook projects trial dates from stripe', function () {
    config()->set('features.billing', true);
    config()->set('billing.stripe_webhook_secret', 'whsec_test');
    config()->set('billing.plans.pilot.stripe_price_id', 'price_pilot');

    $company = Company::factory()->create();
    $trialStart = now()->timestamp;
    $trialEnd = now()->addDays(14)->timestamp;

    $payload = [
        'id' => 'evt_trialing',
        'type' => 'customer.subscription.created',
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'sub_trialing',
                'customer' => 'cus_trialing',
                'status' => 'trialing',
                'trial_start' => $trialStart,
                'trial_end' => $trialEnd,
                'metadata' => [
                    'company_uuid' => $company->uuid,
                    'plan_key' => 'pilot',
                ],
                'items' => [
                    'data' => [[
                        'price' => ['id' => 'price_pilot'],
                        'current_period_start' => now()->timestamp,
                        'current_period_end' => $trialEnd,
                    ]],
                ],
            ],
        ],
    ];

    [$body, $signature] = signedStripeWebhook($payload);

    $this->call('POST', route('stripe.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $body)->assertOk();

    $subscription = CompanySubscription::query()->firstOrFail();

    expect($subscription->status)->toBe(BillingSubscriptionStatus::Trialing)
        ->and($subscription->trial_ends_at)->not->toBeNull()
        ->and($subscription->trial_ends_at->timestamp)->toBe($trialEnd)
        ->and($subscription->grantsEntitlements())->toBeTrue();
});

test('subscription deleted webhook cancels the projection', function () {
    config()->set('features.billing', true);
    config()->set('billing.stripe_webhook_secret', 'whsec_test');
    config()->set('billing.plans.pilot.stripe_price_id', 'price_pilot');

    $company = Company::factory()->create();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_cancel',
        'stripe_subscription_id' => 'sub_cancel',
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::Active,
    ]);

    $payload = [
        'id' => 'evt_subscription_deleted',
        'type' => 'customer.subscription.deleted',
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'sub_cancel',
                'customer' => 'cus_cancel',
                'status' => 'canceled',
                'metadata' => [
                    'company_uuid' => $company->uuid,
                    'plan_key' => 'pilot',
                ],
                'items' => [
                    'data' => [[
                        'price' => ['id' => 'price_pilot'],
                    ]],
                ],
            ],
        ],
    ];

    [$body, $signature] = signedStripeWebhook($payload);

    $this->call('POST', route('stripe.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $body)->assertOk();

    $subscription = CompanySubscription::query()->firstOrFail();

    expect($subscription->status)->toBe(BillingSubscriptionStatus::Canceled)
        ->and($subscription->canceled_at)->not->toBeNull()
        ->and($subscription->grantsEntitlements())->toBeFalse();
});

test('checkout session expired webhook releases the pending claim', function () {
    config()->set('features.billing', true);
    config()->set('billing.stripe_webhook_secret', 'whsec_test');

    $company = Company::factory()->create();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => null,
        'stripe_subscription_id' => null,
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::Pending,
    ]);

    $payload = [
        'id' => 'evt_checkout_expired',
        'type' => 'checkout.session.expired',
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'cs_expired',
                'client_reference_id' => $company->uuid,
                'metadata' => [
                    'company_uuid' => $company->uuid,
                    'plan_key' => 'pilot',
                ],
            ],
        ],
    ];

    [$body, $signature] = signedStripeWebhook($payload);

    $this->call('POST', route('stripe.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $body)->assertOk();

    expect(CompanySubscription::query()->forCompany($company)->first())->toBeNull();
});

test('checkout session expired restores a canceled subscription instead of dropping its customer', function () {
    config()->set('features.billing', true);
    config()->set('billing.stripe_webhook_secret', 'whsec_test');

    $company = Company::factory()->create();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_keep',
        'stripe_subscription_id' => null,
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::Pending,
    ]);

    $payload = [
        'id' => 'evt_checkout_expired_restore',
        'type' => 'checkout.session.expired',
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'cs_expired_2',
                'client_reference_id' => $company->uuid,
                'metadata' => [
                    'company_uuid' => $company->uuid,
                    'plan_key' => 'pilot',
                ],
            ],
        ],
    ];

    [$body, $signature] = signedStripeWebhook($payload);

    $this->call('POST', route('stripe.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $body)->assertOk();

    $subscription = CompanySubscription::query()->forCompany($company)->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription?->status)->toBe(BillingSubscriptionStatus::Canceled)
        ->and($subscription?->stripe_customer_id)->toBe('cus_keep');
});

test('product create action enforces product count entitlement', function () {
    config()->set('features.billing', true);

    [$actor, $company] = billingContext();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_limit',
        'stripe_subscription_id' => 'sub_limit',
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::Active,
        'entitlements' => [
            'catalog.products.max' => 1,
        ],
    ]);

    app(ProductAggregateCreator::class)->create($actor, $company, [
        'name' => 'Existing product',
        'slug' => 'existing-product',
        'short_description' => null,
        'description' => null,
        'brand' => null,
        'manufacturer' => null,
    ], [
        'name' => 'Default',
        'sku' => null,
        'sku_normalized' => null,
        'gtin' => null,
        'mpn' => null,
        'sort_order' => 0,
    ]);

    app(CreateProductAction::class)->execute($actor, $company, [
        'name' => 'Blocked product',
        'slug' => 'blocked-product',
    ]);
})->throws(ValidationException::class, 'catalog.products.max');

test('import and fortnox actions enforce plan capability entitlements', function () {
    config()->set('features.billing', true);

    [$actor, $company] = billingContext();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_capability',
        'stripe_subscription_id' => 'sub_capability',
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::Active,
        'entitlements' => [
            'imports.enabled' => false,
            'fortnox.enabled' => false,
        ],
    ]);

    $file = UploadedFile::fake()->create('products.csv', 1, 'text/csv');

    expect(fn () => app(CreateImportJobAction::class)->execute($actor, $company, $file, ImportMode::Create))
        ->toThrow(ValidationException::class, 'imports.enabled')
        ->and(fn () => app(ConnectFortnoxAction::class)->execute($actor, $company))
        ->toThrow(ValidationException::class, 'fortnox.enabled');
});
