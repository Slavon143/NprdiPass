<?php

use App\Actions\Billing\CancelPendingBillingCheckoutAction;
use App\Actions\Billing\StartBillingCheckoutAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Companies\CreateCompanyForOwner;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\User;
use App\Services\Billing\Contracts\StripeBillingProvider;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\HttpStripeBillingProvider;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['billing.commercial_enabled' => true, 'billing.plans' => config('billing.commercial_plans'),
        'billing.provider' => 'stripe', 'billing.stripe_secret' => 'fixture-credential',
        'billing.stripe_api_base' => 'https://api.stripe.com/v1',
        'billing.plans.starter.stripe_price_id' => 'price_starter_fixture']);
    Http::preventStrayRequests();
    Queue::fake();
    app()->instance(StripeBillingProvider::class, app(HttpStripeBillingProvider::class));
    $this->actor = User::factory()->create();
    $this->company = app(CreateCompanyForOwner::class)->execute($this->actor, 'Pending trial');
    $this->actingAs($this->actor);
    app(CurrentCompany::class)->set($this->company);
    Http::fake([
        'https://api.stripe.com/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_pending_trial', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_pending_trial',
        ]),
        'https://api.stripe.com/v1/checkout/sessions/cs_test_pending_trial/expire' => Http::response([
            'id' => 'cs_test_pending_trial', 'status' => 'expired',
        ]),
    ]);
});

test('opening a paid checkout retains the original trial and its smaller allowances', function () {
    $ends = $this->company->subscription()->firstOrFail()->trial_ends_at;
    app(StartBillingCheckoutAction::class)->execute($this->actor, $this->company, 'starter');
    $pending = $this->company->subscription()->firstOrFail();
    expect($pending->status)->toBe(BillingSubscriptionStatus::Pending)
        ->and($pending->grantsEntitlements())->toBeTrue()
        ->and(app(EntitlementService::class)->limit($this->company, 'catalog.products.max'))->toBe(25);
    app(CreateProductAction::class)->execute($this->actor, $this->company, ['name' => 'Still in trial']);
    $this->travelTo($ends);
    expect($pending->fresh()->grantsEntitlements())->toBeFalse();
    expect(fn () => app(CreateProductAction::class)->execute($this->actor, $this->company, ['name' => 'Expired']))
        ->toThrow(ValidationException::class);
    expect($this->company->products()->count())->toBe(1);
});

test('canceling checkout restores the same trial date without restarting it', function () {
    $ends = $this->company->subscription()->firstOrFail()->trial_ends_at;
    app(StartBillingCheckoutAction::class)->execute($this->actor, $this->company, 'starter');
    $this->travel(2)->days();
    $restored = app(CancelPendingBillingCheckoutAction::class)->execute($this->actor, $this->company);
    expect($restored->status)->toBe(BillingSubscriptionStatus::Trialing)
        ->and($restored->trial_ends_at->equalTo($ends))->toBeTrue()
        ->and($restored->metadata)->not->toHaveKey('pending_checkout');
});

test('pending status and scalar provider metadata cannot grant trial access', function () {
    foreach ([['commercial_model' => 'v1', 'source' => 'self_serve_registration'],
        ['commercial_model' => 'v1', 'source' => 'self_serve_registration', 'pending_checkout' => 'forged']] as $metadata) {
        $subscription = new CompanySubscription(['status' => BillingSubscriptionStatus::Pending,
            'trial_ends_at' => now()->addDays(14), 'metadata' => $metadata]);
        expect($subscription->grantsEntitlements())->toBeFalse();
    }
});

test('canceling a commercial checkout without its original snapshot never creates a replacement trial', function () {
    app(StartBillingCheckoutAction::class)->execute($this->actor, $this->company, 'starter');
    $subscription = $this->company->subscription()->firstOrFail();
    $metadata = $subscription->metadata;
    unset($metadata['pending_checkout']['previous_state']);
    $subscription->update(['metadata' => $metadata]);
    $restored = app(CancelPendingBillingCheckoutAction::class)->execute($this->actor, $this->company);
    expect($restored->status)->toBe(BillingSubscriptionStatus::Canceled)
        ->and($restored->grantsEntitlements())->toBeFalse()
        ->and($restored->entitlements)->toBe([]);
});
