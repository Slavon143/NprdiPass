<?php

use App\Exceptions\Billing\BillingProviderException;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Billing\Contracts\StripeBillingProvider;
use App\Services\Billing\HttpStripeBillingProvider;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function billingErrorProvider(): HttpStripeBillingProvider
{
    Http::preventStrayRequests();
    Queue::fake();
    config()->set('billing.stripe_secret', 'fixture-credential');
    config()->set('billing.stripe_api_base', 'https://api.stripe.com/v1');

    return app(HttpStripeBillingProvider::class);
}

test('expired Stripe credentials show one safe billing error without exposing provider details', function () {
    $provider = billingErrorProvider();
    config()->set('features.billing', true);
    config()->set('billing.provider', 'stripe');
    config()->set('billing.plans.growth.stripe_price_id', 'price_fixture_growth');
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $company]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    app()->instance(StripeBillingProvider::class, $provider);
    Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response([
        'error' => ['code' => 'api_key_expired', 'message' => 'Expired API Key provided: credential-canary-123'],
    ], 401)]);
    $message = 'Stripe authentication failed. Update the Stripe API key configured for billing.';
    $this->post(route('settings.billing.checkout'), ['plan' => 'growth'])->assertSessionHas('error', $message);
    expect(CompanySubscription::query()->forCompany($company)->exists())->toBeFalse();
    $page = $this->get(route('settings.billing.index'))->assertOk()->assertSee($message)->assertDontSee('credential-canary-123');
    expect(substr_count($page->getContent(), $message))->toBe(1);
    Http::assertSentCount(1);
});

test('Stripe HTTP failures retain safe status and retry semantics', function (int $status, bool $retryable, string $message) {
    $provider = billingErrorProvider();
    Http::fake(['https://api.stripe.com/v1/subscriptions/sub_fixture' => Http::response([
        'error' => ['message' => 'Upstream credential-canary-123 and private response details'],
    ], $status)]);
    try {
        $provider->retrieveSubscription('sub_fixture');
        $this->fail('The provider failure was accepted.');
    } catch (BillingProviderException $exception) {
        expect($exception->getMessage())->toBe($message)
            ->and($exception->status)->toBe($status)
            ->and($exception->retryable)->toBe($retryable)
            ->and((string) $exception)->not->toContain('credential-canary-123');
    }
})->with([
    [400, false, 'Stripe subscription retrieval failed.'],
    [401, false, 'Stripe authentication failed. Update the Stripe API key configured for billing.'],
    [403, false, 'Stripe access denied. Check the permissions of the Stripe API key configured for billing.'],
    [429, true, 'Stripe subscription retrieval failed.'],
    [503, true, 'Stripe subscription retrieval failed.'],
]);

test('Stripe connection failure excludes raw transport exception details', function () {
    $provider = billingErrorProvider();
    Http::fake(fn () => throw new ConnectionException('Transport credential-canary-123 and private response details'));
    try {
        $provider->retrieveSubscription('sub_fixture');
        $this->fail('The connection failure was accepted.');
    } catch (BillingProviderException $exception) {
        expect($exception->getMessage())->toBe('Stripe subscription retrieval failed.')
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->status)->toBe(0)
            ->and((string) $exception)->not->toContain('credential-canary-123');
    }
});
