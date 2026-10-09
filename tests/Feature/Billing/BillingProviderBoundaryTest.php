<?php

use App\Actions\Billing\CancelPendingBillingCheckoutAction;
use App\Actions\Billing\ProcessStripeWebhookAction;
use App\Actions\Billing\StartBillingCheckoutAction;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Exceptions\Billing\BillingProviderException;
use App\Models\AuditLog;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Billing\Contracts\StripeBillingProvider;
use App\Services\Billing\FakeStripeBillingProvider;
use App\Services\Billing\HttpStripeBillingProvider;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function billingProviderBoundaryContext(): array
{
    Http::preventStrayRequests();
    Queue::fake();
    config()->set('features.billing', true);
    config()->set('billing.provider', 'stripe');
    config()->set('billing.stripe_secret', 'fixture-stripe-auth');
    config()->set('billing.stripe_api_base', 'https://api.stripe.com/v1');
    config()->set('billing.plans.growth.stripe_price_id', 'price_fixture_growth');
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $company]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company];
}

test('claimed checkout sends the durable claim id as its Stripe idempotency key', function () {
    [$actor, $company] = billingProviderBoundaryContext();
    Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response([
        'id' => 'cs_http_fixture', 'url' => 'https://checkout.stripe.test/fixture',
    ])]);
    app()->instance(StripeBillingProvider::class, app(HttpStripeBillingProvider::class));
    expect(app(StartBillingCheckoutAction::class)->execute($actor, $company, 'growth'))->toBe('https://checkout.stripe.test/fixture');
    $subscription = CompanySubscription::query()->forCompany($company)->firstOrFail();
    $claim = $subscription->metadata['pending_checkout'];
    expect($claim['session_id'])->toBe('cs_http_fixture')
        ->and($claim['creation_uncertain'] ?? false)->toBeFalse();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->header('Idempotency-Key') === ['nordipass-checkout-'.$claim['claim_id']]
        && $request['mode'] === 'subscription'
        && $request['metadata']['company_uuid'] === $company->uuid
        && $request['line_items'][0]['price'] === 'price_fixture_growth');
});

test('pending cancellation expires its known remote session before restoring local state', function () {
    [$actor, $company] = billingProviderBoundaryContext();
    $subscription = CompanySubscription::query()->create([
        'company_id' => $company->id, 'plan_key' => 'growth', 'status' => BillingSubscriptionStatus::Pending,
        'metadata' => ['pending_checkout' => ['claim_id' => 'fixture-claim', 'session_id' => 'cs_cancel_fixture']],
    ]);
    Http::fake(['https://api.stripe.com/v1/checkout/sessions/cs_cancel_fixture/expire' => function () use ($subscription) {
        expect($subscription->fresh()->status)->toBe(BillingSubscriptionStatus::Pending)
            ->and(AuditLog::query()->count())->toBe(0);

        return Http::response(['id' => 'cs_cancel_fixture', 'status' => 'expired']);
    }]);
    app()->instance(StripeBillingProvider::class, app(HttpStripeBillingProvider::class));
    app(CancelPendingBillingCheckoutAction::class)->execute($actor, $company);
    expect($subscription->fresh()->status)->toBe(BillingSubscriptionStatus::Trialing)
        ->and($subscription->fresh()->metadata)->toBe([])
        ->and(AuditLog::query()->count())->toBe(1);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->header('Idempotency-Key') === ['nordipass-expire-cs_cancel_fixture']);
});

test('pending cancellation verifies an already closed remote session', function (string $remoteStatus) {
    [$actor, $company] = billingProviderBoundaryContext();
    $subscription = CompanySubscription::query()->create([
        'company_id' => $company->id, 'plan_key' => 'growth', 'status' => BillingSubscriptionStatus::Pending,
        'metadata' => ['pending_checkout' => ['claim_id' => 'fixture-claim', 'session_id' => 'cs_closed_fixture']],
    ]);
    Http::fake([
        'https://api.stripe.com/v1/checkout/sessions/cs_closed_fixture/expire' => Http::response(['error' => ['message' => 'Session is not open.']], 400),
        'https://api.stripe.com/v1/checkout/sessions/cs_closed_fixture' => Http::response(['id' => 'cs_closed_fixture', 'status' => $remoteStatus]),
    ]);
    app()->instance(StripeBillingProvider::class, app(HttpStripeBillingProvider::class));
    if ($remoteStatus === 'expired') {
        app(CancelPendingBillingCheckoutAction::class)->execute($actor, $company);
        expect($subscription->fresh()->status)->toBe(BillingSubscriptionStatus::Trialing)
            ->and(AuditLog::query()->count())->toBe(1);
    } else {
        expect(fn () => app(CancelPendingBillingCheckoutAction::class)->execute($actor, $company))->toThrow(BillingProviderException::class);
        expect($subscription->fresh()->status)->toBe(BillingSubscriptionStatus::Pending)
            ->and(AuditLog::query()->count())->toBe(0);
    }
    Http::assertSentCount(2);
})->with(['expired', 'complete']);

test('provider expiration failure preserves the pending claim and returns a billing error', function () {
    [$actor, $company] = billingProviderBoundaryContext();
    $subscription = CompanySubscription::query()->create([
        'company_id' => $company->id, 'plan_key' => 'growth', 'status' => BillingSubscriptionStatus::Pending,
        'metadata' => ['pending_checkout' => ['claim_id' => 'fixture-claim', 'session_id' => 'cs_failed_fixture']],
    ]);
    $original = $subscription->refresh()->getAttributes();
    Http::fake(['https://api.stripe.com/v1/checkout/sessions/cs_failed_fixture/expire' => Http::response(['error' => ['message' => 'Fixture expiration failure.']], 503)]);
    app()->instance(StripeBillingProvider::class, app(HttpStripeBillingProvider::class));
    $this->post(route('settings.billing.checkout.cancel-pending'))->assertSessionHas('error', 'Stripe checkout session expiration failed.');
    expect($subscription->fresh()->getAttributes())->toBe($original)
        ->and(AuditLog::query()->count())->toBe(0);
    Http::assertSentCount(1);
});

test('a retryable HTTP creation response retains the uncertain claim', function () {
    [$actor, $company] = billingProviderBoundaryContext();
    Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response(['error' => ['message' => 'Fixture uncertain response.']], 500)]);
    app()->instance(StripeBillingProvider::class, app(HttpStripeBillingProvider::class));
    expect(fn () => app(StartBillingCheckoutAction::class)->execute($actor, $company, 'growth'))->toThrow(BillingProviderException::class);
    $subscription = CompanySubscription::query()->forCompany($company)->firstOrFail();
    expect($subscription->metadata['pending_checkout']['creation_uncertain'])->toBeTrue();
    expect(fn () => app(CancelPendingBillingCheckoutAction::class)->execute($actor, $company))->toThrow(ValidationException::class);
    Http::assertSentCount(1);
});

test('billing expiry restores the prior trial only for the matching session', function () {
    [$actor, $company] = billingProviderBoundaryContext();
    app()->instance(StripeBillingProvider::class, new FakeStripeBillingProvider);
    $subscription = CompanySubscription::query()->create([
        'company_id' => $company->id, 'plan_key' => 'pilot', 'status' => BillingSubscriptionStatus::Trialing,
        'entitlements' => config('billing.plans.pilot.entitlements'), 'trial_ends_at' => now()->addDays(5),
    ]);
    $original = $subscription->refresh()->only(['status', 'plan_key', 'entitlements', 'trial_ends_at', 'metadata']);
    app(StartBillingCheckoutAction::class)->execute($actor, $company, 'growth');
    $sessionId = $subscription->fresh()->metadata['pending_checkout']['session_id'];
    app(ProcessStripeWebhookAction::class)->execute([
        'id' => 'evt_matching_expiry', 'type' => 'checkout.session.expired',
        'data' => ['object' => ['id' => $sessionId, 'client_reference_id' => $company->uuid]],
    ]);
    $current = $subscription->fresh();
    expect($current)->not->toBeNull();
    $restored = $current->only(array_keys($original));
    expect($restored['status'])->toBe($original['status'])
        ->and($restored['plan_key'])->toBe($original['plan_key'])
        ->and($restored['entitlements'])->toBe($original['entitlements'])
        ->and($restored['trial_ends_at']->equalTo($original['trial_ends_at']))->toBeTrue()
        ->and($restored['metadata'])->toBe($original['metadata']);
});

test('billing expiry cannot release a confirmed pending subscription', function () {
    [$actor, $company] = billingProviderBoundaryContext();
    CompanySubscription::query()->create([
        'company_id' => $company->id, 'plan_key' => 'growth', 'status' => BillingSubscriptionStatus::Pending,
        'stripe_customer_id' => 'cus_confirmed', 'stripe_subscription_id' => 'sub_confirmed',
    ]);
    app(ProcessStripeWebhookAction::class)->execute([
        'id' => 'evt_stale_expiry', 'type' => 'checkout.session.expired',
        'data' => ['object' => ['id' => 'cs_stale', 'client_reference_id' => $company->uuid]],
    ]);
    $current = CompanySubscription::query()->forCompany($company)->firstOrFail();
    expect($current->status)->toBe(BillingSubscriptionStatus::Pending)
        ->and($current->getAttribute('stripe_subscription_id'))->toBe('sub_confirmed')
        ->and(AuditLog::query()->count())->toBe(0);
});

test('unidentified legacy checkout cannot be released by an unrelated expiration event', function () {
    [$actor, $company] = billingProviderBoundaryContext();
    $subscription = CompanySubscription::query()->create([
        'company_id' => $company->id, 'plan_key' => 'growth', 'status' => BillingSubscriptionStatus::Pending,
    ]);
    app(ProcessStripeWebhookAction::class)->execute([
        'id' => 'evt_unidentified_legacy', 'type' => 'checkout.session.expired',
        'data' => ['object' => ['id' => 'cs_unrelated', 'client_reference_id' => $company->uuid]],
    ]);
    expect($subscription->fresh())->not->toBeNull();
    expect($subscription->fresh()->status)->toBe(BillingSubscriptionStatus::Pending)
        ->and(AuditLog::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('unidentified legacy checkout requires reconciliation before production cancellation', function () {
    [$actor, $company] = billingProviderBoundaryContext();
    $subscription = CompanySubscription::query()->create([
        'company_id' => $company->id, 'plan_key' => 'growth', 'status' => BillingSubscriptionStatus::Pending,
    ]);
    expect(fn () => app(CancelPendingBillingCheckoutAction::class)->execute($actor, $company))->toThrow(ValidationException::class);
    expect($subscription->fresh()->status)->toBe(BillingSubscriptionStatus::Pending)
        ->and(AuditLog::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('renewed checkout expiry restores canceled state despite the prior completion marker', function () {
    [$actor, $company] = billingProviderBoundaryContext();
    app()->instance(StripeBillingProvider::class, new FakeStripeBillingProvider);
    $subscription = CompanySubscription::query()->create([
        'company_id' => $company->id, 'plan_key' => 'pilot', 'status' => BillingSubscriptionStatus::Canceled,
        'stripe_customer_id' => 'cus_previous', 'stripe_subscription_id' => 'sub_previous',
        'metadata' => ['checkout_session_id' => 'cs_previous', 'retained' => 'fixture'],
    ]);
    $originalMetadata = $subscription->refresh()->metadata;
    app(StartBillingCheckoutAction::class)->execute($actor, $company, 'growth');
    $pendingMetadata = $subscription->fresh()->metadata;
    expect($pendingMetadata)->not->toHaveKey('checkout_session_id');
    app(ProcessStripeWebhookAction::class)->execute([
        'id' => 'evt_renewed_expiry', 'type' => 'checkout.session.expired',
        'data' => ['object' => ['id' => $pendingMetadata['pending_checkout']['session_id'], 'client_reference_id' => $company->uuid]],
    ]);
    $restored = $subscription->fresh();
    expect($restored)->not->toBeNull();
    expect($restored->status)->toBe(BillingSubscriptionStatus::Canceled)
        ->and($restored->stripe_customer_id)->toBe('cus_previous')
        ->and($restored->stripe_subscription_id)->toBe('sub_previous')
        ->and($restored->plan_key)->toBe('pilot')
        ->and($restored->metadata)->toBe($originalMetadata);
    Http::assertNothingSent();
});
