<?php

use App\Actions\Billing\ProcessStripeWebhookAction;
use App\Actions\Billing\StartBillingCheckoutAction;
use App\Actions\Companies\CreateCompanyForOwner;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\Billing\StripeWebhookEventStatus;
use App\Models\Billing\StripeWebhookEvent;
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
        'billing.plans.starter.stripe_price_id' => 'price_starter_ordering',
        'billing.plans.growth.stripe_price_id' => 'price_growth_ordering',
        'billing.plans.business.stripe_price_id' => 'price_business_ordering']);
    Http::preventStrayRequests();
    Queue::fake();
    app()->instance(StripeBillingProvider::class, app(HttpStripeBillingProvider::class));
    $this->actor = User::factory()->create();
    $this->company = app(CreateCompanyForOwner::class)->execute($this->actor, 'Webhook ordering');
    $this->actingAs($this->actor);
    app(CurrentCompany::class)->set($this->company);
});

function commercialRemoteSubscription($company, string $status = 'active', string $price = 'price_starter_ordering'): array
{
    return ['id' => 'sub_current_ordering', 'customer' => 'cus_current_ordering', 'status' => $status,
        'metadata' => ['company_uuid' => $company->uuid],
        'items' => ['data' => [['price' => ['id' => $price], 'current_period_start' => now()->timestamp,
            'current_period_end' => now()->addMonth()->timestamp]]]];
}

function commercialStoredSubscription($company): void
{
    $company->subscription()->firstOrFail()->update(['stripe_subscription_id' => 'sub_current_ordering',
        'stripe_customer_id' => 'cus_current_ordering', 'stripe_price_id' => 'price_starter_ordering',
        'status' => BillingSubscriptionStatus::Active, 'plan_key' => 'starter',
        'entitlements' => config('billing.plans.starter.entitlements')]);
}

test('delayed Stripe events install current provider state rather than stale status and price', function (string $eventType, string $remoteStatus) {
    commercialStoredSubscription($this->company);
    $latest = commercialRemoteSubscription($this->company, $remoteStatus);
    Http::fake(['https://api.stripe.com/v1/subscriptions/sub_current_ordering' => Http::response($latest)]);
    $stale = commercialRemoteSubscription($this->company, 'active', 'price_business_ordering');
    app(ProcessStripeWebhookAction::class)->execute(['id' => 'evt_stale_ordering', 'type' => $eventType, 'data' => ['object' => $stale]]);
    $subscription = $this->company->subscription()->firstOrFail();
    expect($subscription->plan_key)->toBe('starter')->and($subscription->status->value)->toBe($remoteStatus)
        ->and($subscription->grantsEntitlements())->toBe($remoteStatus === 'active');
    Http::assertSentCount(1);
})->with([['customer.subscription.updated', 'canceled'], ['customer.subscription.deleted', 'active']]);

test('events for an older subscription cannot overwrite the current subscription', function () {
    commercialStoredSubscription($this->company);
    $old = commercialRemoteSubscription($this->company, 'canceled', 'price_business_ordering');
    $old['id'] = 'sub_replaced_ordering';
    app(ProcessStripeWebhookAction::class)->execute(['id' => 'evt_replaced_ordering', 'type' => 'customer.subscription.deleted', 'data' => ['object' => $old]]);
    expect($this->company->subscription()->firstOrFail()->status)->toBe(BillingSubscriptionStatus::Active)
        ->and($this->company->subscription()->firstOrFail()->stripe_subscription_id)->toBe('sub_current_ordering');
    Http::assertNothingSent();
});

test('authoritative retrieval with a foreign customer fails without changing tenant allowances', function () {
    commercialStoredSubscription($this->company);
    $remote = commercialRemoteSubscription($this->company, 'active', 'price_business_ordering');
    $remote['customer'] = 'cus_foreign_ordering';
    Http::fake(['https://api.stripe.com/v1/subscriptions/sub_current_ordering' => Http::response($remote)]);
    expect(fn () => app(ProcessStripeWebhookAction::class)->execute(['id' => 'evt_foreign_ordering',
        'type' => 'customer.subscription.updated', 'data' => ['object' => $remote]]))->toThrow(ValidationException::class);
    expect($this->company->subscription()->firstOrFail()->plan_key)->toBe('starter')
        ->and(StripeWebhookEvent::query()->where('stripe_event_id', 'evt_foreign_ordering')->firstOrFail()->status)->toBe(StripeWebhookEventStatus::Failed);
});

test('completion of the current checkout preserves the bounded local trial until subscription confirmation', function () {
    Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response([
        'id' => 'cs_test_ordering', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_ordering',
    ])]);
    $ends = $this->company->subscription()->firstOrFail()->trial_ends_at;
    app(StartBillingCheckoutAction::class)->execute($this->actor, $this->company, 'starter');
    $claim = $this->company->subscription()->firstOrFail()->metadata['pending_checkout']['claim_id'];
    app(ProcessStripeWebhookAction::class)->execute(['id' => 'evt_checkout_ordering', 'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_test_ordering', 'customer' => 'cus_current_ordering', 'subscription' => 'sub_current_ordering',
            'metadata' => ['company_uuid' => $this->company->uuid, 'plan_key' => 'starter', 'checkout_claim_id' => $claim]]]]);
    $subscription = $this->company->subscription()->firstOrFail();
    expect($subscription->status)->toBe(BillingSubscriptionStatus::Pending)
        ->and($subscription->trial_ends_at->equalTo($ends))->toBeTrue()
        ->and($subscription->grantsEntitlements())->toBeTrue()
        ->and(app(EntitlementService::class)->limit($this->company, 'catalog.products.max'))->toBe(25);
    $this->travelTo($ends);
    expect($subscription->fresh()->grantsEntitlements())->toBeFalse();
});

test('subscription arriving before checkout completion must bind the active server claim', function (bool $matches) {
    Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response([
        'id' => 'cs_test_ordering', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_ordering',
    ])]);
    app(StartBillingCheckoutAction::class)->execute($this->actor, $this->company, 'starter');
    $claim = $this->company->subscription()->firstOrFail()->metadata['pending_checkout']['claim_id'];
    $remote = commercialRemoteSubscription($this->company);
    $remote['metadata']['checkout_claim_id'] = $matches ? $claim : 'old-claim';
    Http::fake(['https://api.stripe.com/v1/subscriptions/sub_current_ordering' => Http::response($remote)]);
    $apply = fn () => app(ProcessStripeWebhookAction::class)->execute(['id' => 'evt_subscription_ordering',
        'type' => 'customer.subscription.created', 'data' => ['object' => $remote]]);
    if ($matches) {
        $apply();
        expect($this->company->subscription()->firstOrFail()->status)->toBe(BillingSubscriptionStatus::Active)
            ->and(app(EntitlementService::class)->limit($this->company, 'catalog.products.max'))->toBe(100);
    } else {
        expect($apply)->toThrow(ValidationException::class);
        expect($this->company->subscription()->firstOrFail()->status)->toBe(BillingSubscriptionStatus::Pending);
    }
})->with([true, false]);

test('multiple registered base plans cannot resolve by taking the first item', function () {
    $payload = commercialRemoteSubscription($this->company);
    $payload['items']['data'][] = ['price' => ['id' => 'price_business_ordering']];
    $projection = app(HttpStripeBillingProvider::class)->projectionFromSubscription($payload);
    expect($projection->planKey)->toBe('unknown')->and($projection->metadata['commercial_model'])->toBe('unknown');
});
