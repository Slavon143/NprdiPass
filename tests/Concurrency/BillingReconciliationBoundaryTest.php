<?php

use App\Actions\Billing\ReconcileBillingProjectionAction;
use App\Data\Billing\StripeSubscriptionProjection;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Services\Billing\Contracts\StripeBillingProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    if (! in_array(DB::connection()->getDatabaseName(), ['nordipass_testing', 'nordipass_stripe_acceptance_20261004_testing', 'nordipass_commercial_20261004_testing', 'nordipass_reconcile_20261004_testing'], true)) {
        throw new RuntimeException('Owned isolated database required.');
    }
    $this->artisan('migrate')->assertSuccessful();
    DB::beginTransaction();
    DB::table('company_subscriptions')->update(['stripe_subscription_id' => null]);
    $this->company = Company::factory()->create(['commercial_model_version' => 1]);
    $this->subscription = CompanySubscription::query()->create([
        'company_id' => $this->company->id, 'stripe_customer_id' => 'cus_boundary',
        'stripe_subscription_id' => 'sub_boundary', 'stripe_price_id' => 'price_old_boundary',
        'plan_key' => 'starter', 'status' => BillingSubscriptionStatus::Canceled,
        'entitlements' => ['catalog.products.max' => 100], 'metadata' => ['commercial_model' => 'v1'],
    ]);
    config(['billing.plans' => config('billing.commercial_plans')]);
});

afterEach(function () {
    if (isset($this->originalModelDispatcher)) {
        Company::setEventDispatcher($this->originalModelDispatcher);
    }
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

test('reconciliation rejects an unbound remote projection without granting paid access', function (string $mismatch) {
    $remote = new StripeSubscriptionProjection(
        stripeCustomerId: $mismatch === 'customer' ? 'cus_foreign' : 'cus_boundary',
        stripeSubscriptionId: $mismatch === 'subscription' ? 'sub_foreign' : 'sub_boundary',
        stripePriceId: 'price_new_boundary', planKey: 'business', status: BillingSubscriptionStatus::Active,
        currentPeriodStart: now()->toImmutable(), currentPeriodEnd: now()->addMonth()->toImmutable(),
        metadata: ['commercial_model' => 'v1', 'company_uuid' => $mismatch === 'company' ? 'foreign' : $this->company->uuid],
    );
    $provider = Mockery::mock(StripeBillingProvider::class);
    $provider->shouldReceive('retrieveSubscription')->once()->with('sub_boundary')->andReturn($remote);
    app()->instance(StripeBillingProvider::class, $provider);
    expect(fn () => app(ReconcileBillingProjectionAction::class)->execute())->toThrow(ValidationException::class);
    $current = $this->subscription->fresh();
    expect($current->status)->toBe(BillingSubscriptionStatus::Canceled)
        ->and($current->stripe_subscription_id)->toBe('sub_boundary')
        ->and($current->last_reconciled_at)->toBeNull();
})->with(['customer', 'subscription', 'company']);

test('reconciliation rechecks a changed subscription binding after scanning candidates', function () {
    $subscriptionId = $this->subscription->id;
    $companyId = $this->company->id;
    $this->originalModelDispatcher = Company::getEventDispatcher();
    Company::setEventDispatcher(clone $this->originalModelDispatcher);
    // Deterministic scan-to-lock drift; the worker must reread its work candidate.
    Company::retrieved(function (Company $company) use ($subscriptionId, $companyId) {
        if ($company->id === $companyId) {
            CompanySubscription::query()->whereKey($subscriptionId)->update(['stripe_subscription_id' => 'sub_replacement']);
        }
    });
    $provider = Mockery::mock(StripeBillingProvider::class);
    $provider->shouldNotReceive('retrieveSubscription');
    app()->instance(StripeBillingProvider::class, $provider);
    expect(app(ReconcileBillingProjectionAction::class)->execute())->toBe(['checked' => 1, 'updated' => 0, 'skipped' => 1]);
    expect($this->subscription->fresh()->stripe_subscription_id)->toBe('sub_replacement');
});
