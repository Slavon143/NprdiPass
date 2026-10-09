<?php

use App\Actions\Billing\StartBillingCheckoutAction;
use App\Actions\Companies\CreateCompanyForOwner;
use App\Models\User;
use App\Services\Billing\Contracts\StripeBillingProvider;
use App\Services\Billing\HttpStripeBillingProvider;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['billing.commercial_enabled' => true, 'billing.plans' => config('billing.commercial_plans'),
        'billing.provider' => 'stripe', 'billing.stripe_secret' => 'fixture-credential',
        'billing.stripe_api_base' => 'https://api.stripe.com/v1',
        'billing.plans.growth.stripe_price_id' => 'price_growth_month_fixture',
        'billing.plans.growth.stripe_annual_price_id' => 'price_growth_year_fixture']);
    Http::preventStrayRequests();
    Queue::fake();
    app()->instance(StripeBillingProvider::class, app(HttpStripeBillingProvider::class));
    $this->actor = User::factory()->create();
    $this->company = app(CreateCompanyForOwner::class)->execute($this->actor, 'Checkout intervals');
    $this->actingAs($this->actor);
    app(CurrentCompany::class)->set($this->company);
    Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response([
        'id' => 'cs_test_interval_fixture', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_interval_fixture',
    ])]);
});

test('checkout sends the selected registered price and persists its interval without restarting the trial', function (string $interval, string $price) {
    $ends = $this->company->subscription()->firstOrFail()->trial_ends_at;
    $url = app(StartBillingCheckoutAction::class)->execute($this->actor, $this->company, 'growth', $interval);
    expect($url)->toBe('https://checkout.stripe.com/c/pay/cs_test_interval_fixture');
    $subscription = $this->company->subscription()->firstOrFail();
    expect($subscription->metadata['pending_checkout']['billing_interval'])->toBe($interval)
        ->and($subscription->trial_ends_at->equalTo($ends))->toBeTrue();
    Http::assertSent(fn ($request) => $request['line_items'][0]['price'] === $price
        && $request['line_items'][0]['quantity'] === 1
        && $request['subscription_data']['metadata']['billing_interval'] === $interval
        && ! isset($request['subscription_data']['trial_period_days'])
        && $request->hasHeader('Idempotency-Key', 'nordipass-checkout-'.$subscription->metadata['pending_checkout']['claim_id']));
    Http::assertSentCount(1);
})->with(['monthly' => ['month', 'price_growth_month_fixture'], 'annual' => ['year', 'price_growth_year_fixture']]);

test('invalid billing intervals cannot create a checkout claim', function () {
    expect(fn () => app(StartBillingCheckoutAction::class)->execute($this->actor, $this->company, 'growth', 'week'))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
    expect($this->company->subscription()->firstOrFail()->metadata)->not->toHaveKey('pending_checkout');
});

test('enterprise and retired pilot cannot be purchased through either provider', function (string $provider, string $plan) {
    config(['billing.provider' => $provider]);
    expect(fn () => app(StartBillingCheckoutAction::class)->execute($this->actor, $this->company, $plan))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with([['stripe', 'enterprise'], ['fake', 'enterprise'], ['stripe', 'pilot'], ['fake', 'pilot']]);

test('a second checkout cannot replace an annual claim with a monthly price', function () {
    app(StartBillingCheckoutAction::class)->execute($this->actor, $this->company, 'growth', 'year');
    expect(fn () => app(StartBillingCheckoutAction::class)->execute($this->actor, $this->company, 'growth', 'month'))
        ->toThrow(ValidationException::class);
    expect($this->company->subscription()->firstOrFail()->metadata['pending_checkout']['billing_interval'])->toBe('year');
    Http::assertSentCount(1);
});
