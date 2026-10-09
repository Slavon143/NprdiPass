<?php

use App\Actions\Companies\CreateCompanyForOwner;
use App\Data\Billing\StripeSubscriptionProjection;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\BillingProjectionService;
use App\Services\Billing\CommercialPlanCatalog;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\HttpStripeBillingProvider;

beforeEach(function () {
    config(['billing.commercial_enabled' => true]);
    if (is_array(config('billing.commercial_plans'))) {
        config(['billing.plans' => config('billing.commercial_plans')]);
    }
});

test('company creation without optional country uses the persisted schema default', function () {
    $company = app(CreateCompanyForOwner::class)->execute(User::factory()->create(), 'Default country');
    expect($company->country_code)->toBe('SE')
        ->and($company->fresh()->country_code)->toBe('SE')
        ->and($company->subscription()->firstOrFail()->plan_key)->toBe('growth');
});

test('registration starts a fixed no-card growth trial with trial quotas', function () {
    $this->travelTo(now()->startOfSecond());
    $company = app(CreateCompanyForOwner::class)->execute(User::factory()->create(), 'Commercial trial', '1234567890', 'SE');
    $subscription = $company->subscription()->firstOrFail();
    expect($subscription->plan_key)->toBe('growth')
        ->and($subscription->status)->toBe(BillingSubscriptionStatus::Trialing)
        ->and($subscription->entitlements['catalog.products.max'])->toBe(25)
        ->and($subscription->entitlements['members.max'])->toBe(2)
        ->and($subscription->stripe_subscription_id)->toBeNull()
        ->and($subscription->stripe_customer_id)->toBeNull()
        ->and($subscription->trial_ends_at->equalTo(now()->addDays(14)))->toBeTrue()
        ->and($subscription->grantsEntitlements())->toBeTrue();
    $this->travel(14)->days();
    expect($subscription->fresh()->grantsEntitlements())->toBeFalse()
        ->and($company->fresh())->not->toBeNull();
});

test('commercial trial without an expiration cannot grant access', function () {
    $subscription = new CompanySubscription(['status' => BillingSubscriptionStatus::Trialing,
        'metadata' => ['commercial_model' => 'v1']]);
    expect($subscription->grantsEntitlements())->toBeFalse();
});

test('commercial annual price overrides forged metadata and resolves the correct plan', function () {
    config(['billing.plans.growth.stripe_annual_price_id' => 'price_growth_annual_fixture']);
    $projection = app(HttpStripeBillingProvider::class)->projectionFromSubscription([
        'id' => 'sub_commercial', 'customer' => 'cus_commercial', 'status' => 'active',
        'metadata' => ['plan_key' => 'business', 'commercial_model' => 'legacy'],
        'items' => ['data' => [['price' => ['id' => 'price_growth_annual_fixture']]]],
    ]);
    expect($projection->planKey)->toBe('growth')
        ->and($projection->metadata['billing_interval'])->toBe('year')
        ->and($projection->metadata['commercial_model'])->toBe('v1');
});

test('unknown prices cannot grant a plan through metadata', function () {
    $projection = app(HttpStripeBillingProvider::class)->projectionFromSubscription([
        'id' => 'sub_unknown', 'customer' => 'cus_unknown', 'status' => 'active',
        'metadata' => ['plan_key' => 'business'],
        'items' => ['data' => [['price' => ['id' => 'price_not_registered_fixture']]]],
    ]);
    $subscription = app(BillingProjectionService::class)->upsert(Company::factory()->create(), $projection);
    expect($subscription->plan_key)->toBe('unknown')->and($subscription->entitlements)->toBe([]);
    expect($subscription->grantsEntitlements())->toBeFalse()
        ->and(app(EntitlementService::class)->limit($subscription->company, 'catalog.products.max'))->toBe(0);
});

test('ambiguous monthly and annual price configuration fails closed', function () {
    config(['billing.plans.growth.stripe_price_id' => 'price_duplicate_fixture',
        'billing.plans.business.stripe_annual_price_id' => 'price_duplicate_fixture']);
    expect(app(CommercialPlanCatalog::class)->price('price_duplicate_fixture'))->toBeNull();
});

test('confirmed commercial trial retains trial allowances and active purchase grants paid allowances', function () {
    $company = Company::factory()->create();
    $project = fn (BillingSubscriptionStatus $status) => new StripeSubscriptionProjection(
        stripeCustomerId: 'cus_commercial', stripeSubscriptionId: 'sub_commercial',
        stripePriceId: 'price_growth_fixture', planKey: 'growth', status: $status,
        trialEndsAt: now()->addDays(3)->toImmutable(), metadata: ['commercial_model' => 'v1'],
    );
    $trial = app(BillingProjectionService::class)->upsert($company, $project(BillingSubscriptionStatus::Trialing));
    expect($trial->entitlements['catalog.products.max'])->toBe(25);
    $paid = app(BillingProjectionService::class)->upsert($company, $project(BillingSubscriptionStatus::Active));
    expect($paid->entitlements['catalog.products.max'])->toBe(1000);
});

test('legacy growth price keeps legacy entitlements when reconciled', function () {
    config(['billing.legacy_prices.growth' => 'price_legacy_growth_fixture']);
    $company = Company::factory()->create();
    $projection = app(HttpStripeBillingProvider::class)->projectionFromSubscription([
        'id' => 'sub_legacy', 'customer' => 'cus_legacy', 'status' => 'active',
        'metadata' => [], 'items' => ['data' => [['price' => ['id' => 'price_legacy_growth_fixture']]]],
    ]);
    $subscription = app(BillingProjectionService::class)->upsert($company, $projection);
    expect($subscription->plan_key)->toBe('growth')
        ->and($subscription->metadata['commercial_model'])->toBe('legacy')
        ->and($subscription->entitlements)->toBe(config('billing.legacy_plans.growth.entitlements'));
});

test('reconciliation preserves a negotiated legacy allowance for an unchanged contract', function () {
    $company = Company::factory()->create();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(), 'plan_key' => 'growth',
        'stripe_price_id' => 'price_negotiated_fixture', 'status' => BillingSubscriptionStatus::Active,
        'entitlements' => ['catalog.products.max' => 1700, 'imports.enabled' => true],
    ]);
    $projection = new StripeSubscriptionProjection(
        stripeCustomerId: 'cus_legacy', stripeSubscriptionId: 'sub_legacy',
        stripePriceId: 'price_negotiated_fixture', planKey: 'growth',
        status: BillingSubscriptionStatus::Active, metadata: ['commercial_model' => 'legacy'],
    );
    $result = app(BillingProjectionService::class)->upsert($company, $projection);
    expect($result->entitlements['catalog.products.max'])->toBe(1700);
});

test('commercial past due state without a fixed grace expiration denies access', function () {
    $subscription = new CompanySubscription(['status' => BillingSubscriptionStatus::PastDue,
        'metadata' => ['commercial_model' => 'v1']]);
    expect($subscription->grantsEntitlements())->toBeFalse();
});

test('an expired trial has zero admission slots while an explicitly unlimited active contract remains unlimited', function () {
    $company = Company::factory()->create();
    $subscription = CompanySubscription::query()->create([
        'company_id' => $company->getKey(), 'plan_key' => 'growth',
        'status' => BillingSubscriptionStatus::Trialing, 'trial_ends_at' => now()->subSecond(),
        'metadata' => ['commercial_model' => 'v1'], 'entitlements' => ['catalog.products.max' => 25],
    ]);
    expect(app(EntitlementService::class)->limit($company, 'catalog.products.max'))->toBe(0);
    $subscription->update(['status' => BillingSubscriptionStatus::Active, 'entitlements' => ['catalog.products.max' => null]]);
    expect(app(EntitlementService::class)->limit($company, 'catalog.products.max'))->toBeNull();
});
