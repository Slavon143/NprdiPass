<?php

use App\Actions\Billing\CancelPendingBillingCheckoutAction;
use App\Actions\Billing\CreateBillingPortalSessionAction;
use App\Actions\Billing\ProcessStripeWebhookAction;
use App\Actions\Billing\StartBillingCheckoutAction;
use App\Authorization\CompanyAuthorizer;
use App\Data\Billing\StripeCheckoutSession;
use App\Data\Billing\StripePortalSession;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\CompanyPermission;
use App\Exceptions\Billing\BillingProviderException;
use App\Models\AuditLog;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Billing\Contracts\StripeBillingProvider;
use App\Services\Billing\FakeStripeBillingProvider;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingBoundaryProvider extends FakeStripeBillingProvider
{
    public ?Closure $beforeList = null;

    public ?Closure $beforeCheckout = null;

    public ?Closure $beforePortal = null;

    public int $checkoutCalls = 0;

    public int $portalCalls = 0;

    public function listSubscriptions(string $stripeCustomerId): array
    {
        if ($this->beforeList !== null) {
            ($this->beforeList)();
        }

        return [];
    }

    public function createCheckoutSession(Company $company, string $planKey, string $successUrl, string $cancelUrl, string $interval = 'month'): StripeCheckoutSession
    {
        $this->checkoutCalls++;
        if ($this->beforeCheckout !== null) {
            ($this->beforeCheckout)();
        }

        return new StripeCheckoutSession('cs_boundary_'.$this->checkoutCalls, 'https://checkout.stripe.test/boundary');
    }

    public function createPortalSession(Company $company, CompanySubscription $subscription, string $returnUrl): StripePortalSession
    {
        $this->portalCalls++;
        if ($this->beforePortal !== null) {
            ($this->beforePortal)();
        }

        return parent::createPortalSession($company, $subscription, $returnUrl);
    }
}

function billingBoundaryFixture(string $status = 'canceled'): array
{
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    test()->artisan('migrate:fresh')->assertSuccessful();
    Http::preventStrayRequests();
    Queue::fake();
    config()->set('billing.provider', 'stripe');
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $company]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $subscription = $status === 'missing' ? null : CompanySubscription::query()->create([
        'company_id' => $company->id,
        'plan_key' => 'pilot',
        'status' => $status,
        'stripe_customer_id' => $status === 'pending' ? null : 'cus_boundary',
        'stripe_subscription_id' => $status === 'pending' ? null : 'sub_boundary_old',
        'entitlements' => config('billing.plans.pilot.entitlements'),
    ]);
    $subscription?->refresh();
    config()->set('database.connections.mysql_billing_competitor', config('database.connections.mysql'));
    DB::purge('mysql_billing_competitor');
    $provider = new BillingBoundaryProvider;
    app()->instance(StripeBillingProvider::class, $provider);

    return [$actor, $company, $membership, $subscription, $provider];
}

test('billing rejects a role revoked behind an older transaction snapshot', function (string $operation) {
    [$actor, $company, $membership, $subscription, $provider] = billingBoundaryFixture($operation === 'cancel' ? 'pending' : 'canceled');
    $original = $subscription->getAttributes();
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        expect(app(CompanyAuthorizer::class)->allows($actor, $company, CompanyPermission::BillingManage))->toBeTrue();
        DB::connection('mysql_billing_competitor')->table('company_user')->where('id', $membership->id)
            ->update(['role' => 'viewer', 'is_owner' => false]);
        expect(fn () => match ($operation) {
            'start' => app(StartBillingCheckoutAction::class)->execute($actor, $company, 'growth'),
            'cancel' => app(CancelPendingBillingCheckoutAction::class)->execute($actor, $company),
            'portal' => app(CreateBillingPortalSessionAction::class)->execute($actor, $company),
        })->toThrow(AuthorizationException::class);
        expect($subscription->fresh()->getAttributes())->toBe($original)
            ->and($provider->checkoutCalls)->toBe(0)
            ->and($provider->portalCalls)->toBe(0)
            ->and(AuditLog::query()->count())->toBe(0);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_billing_competitor');
    }
})->with(['start', 'cancel', 'portal']);

test('billing rejects revocation between preflight and transaction entry', function (string $operation) {
    [$actor, $company, $membership, $subscription, $provider] = billingBoundaryFixture($operation === 'cancel' ? 'pending' : 'canceled');
    $original = $subscription->getAttributes();
    $primary = DB::connection();
    $revoked = false;
    Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use ($primary, $membership, &$revoked): void {
        if ($event->connection !== $primary || $revoked) {
            return;
        }
        $revoked = true;
        DB::connection('mysql_billing_competitor')->table('company_user')->where('id', $membership->id)
            ->update(['role' => 'viewer', 'is_owner' => false]);
    });
    try {
        expect(fn () => $operation === 'start'
            ? app(StartBillingCheckoutAction::class)->execute($actor, $company, 'growth')
            : app(CancelPendingBillingCheckoutAction::class)->execute($actor, $company))->toThrow(AuthorizationException::class);
        expect($revoked)->toBeTrue()
            ->and($subscription->fresh()->getAttributes())->toBe($original)
            ->and($provider->checkoutCalls)->toBe(0)
            ->and(AuditLog::query()->count())->toBe(0);
    } finally {
        DB::disconnect('mysql_billing_competitor');
    }
})->with(['start', 'cancel']);

test('checkout rechecks access after remote subscription inspection', function () {
    [$actor, $company, $membership, $subscription, $provider] = billingBoundaryFixture();
    $original = $subscription->only(['status', 'plan_key', 'stripe_customer_id', 'stripe_subscription_id', 'metadata', 'entitlements']);
    $provider->beforeList = function () use ($membership): void {
        DB::connection('mysql_billing_competitor')->table('company_user')->where('id', $membership->id)
            ->update(['role' => 'viewer', 'is_owner' => false]);
    };
    try {
        expect(fn () => app(StartBillingCheckoutAction::class)->execute($actor, $company, 'growth'))->toThrow(AuthorizationException::class);
        expect($provider->checkoutCalls)->toBe(0)
            ->and($subscription->fresh()->only(array_keys($original)))->toBe($original);
    } finally {
        DB::disconnect('mysql_billing_competitor');
    }
});

test('failed checkout compensation leaves newer committed subscription state intact', function (string $takeover) {
    [$actor, $company, $membership, $subscription, $provider] = billingBoundaryFixture($takeover === 'new active' ? 'missing' : 'canceled');
    $failed = false;
    $changed = false;
    $provider->beforeCheckout = function () use (&$failed): void {
        $failed = true;
        throw new BillingProviderException('Definite fixture failure.');
    };
    $primary = DB::connection();
    Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use ($primary, $company, $takeover, &$failed, &$changed): void {
        if ($event->connection !== $primary || ! $failed || $changed) {
            return;
        }
        $changed = true;
        $competitor = DB::connection('mysql_billing_competitor');
        $competitor->transaction(function () use ($competitor, $company, $takeover): void {
            $competitor->table('companies')->where('id', $company->id)->lockForUpdate()->first();
            $competitor->table('company_subscriptions')->where('company_id', $company->id)->update([
                'status' => $takeover === 'replacement pending' ? 'pending' : 'active',
                'plan_key' => 'pilot',
                'stripe_customer_id' => $takeover === 'replacement pending' ? null : 'cus_confirmed',
                'stripe_subscription_id' => $takeover === 'replacement pending' ? null : 'sub_confirmed',
                'metadata' => json_encode($takeover === 'replacement pending'
                    ? ['pending_checkout' => ['claim_id' => 'replacement-claim', 'plan_key' => 'pilot']]
                    : ['source' => 'confirmed-projection'], JSON_THROW_ON_ERROR),
            ]);
        });
    });
    try {
        expect(fn () => app(StartBillingCheckoutAction::class)->execute($actor, $company, 'growth'))
            ->toThrow(BillingProviderException::class, 'Definite fixture failure.');
        $current = CompanySubscription::query()->forCompany($company)->first();
        expect($changed)->toBeTrue()
            ->and($current)->not->toBeNull()
            ->and($current->status)->toBe($takeover === 'replacement pending' ? BillingSubscriptionStatus::Pending : BillingSubscriptionStatus::Active)
            ->and($current->plan_key)->toBe('pilot')
            ->and($current->metadata)->toBe($takeover === 'replacement pending'
                ? ['pending_checkout' => ['claim_id' => 'replacement-claim', 'plan_key' => 'pilot']]
                : ['source' => 'confirmed-projection']);
    } finally {
        DB::disconnect('mysql_billing_competitor');
    }
})->with(['new active', 'existing active', 'replacement pending']);

test('checkout cannot replace a confirmed subscription hidden by an older read view', function () {
    [$actor, $company, $membership, $subscription, $provider] = billingBoundaryFixture();
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        $subscription->fresh();
        DB::connection('mysql_billing_competitor')->table('company_subscriptions')->where('id', $subscription->id)
            ->update(['status' => 'active', 'stripe_subscription_id' => 'sub_confirmed']);
        expect(fn () => app(StartBillingCheckoutAction::class)->execute($actor, $company, 'growth'))
            ->toThrow(ValidationException::class);
        expect($provider->checkoutCalls)->toBe(0)
            ->and(DB::connection('mysql_billing_competitor')->table('company_subscriptions')->where('id', $subscription->id)->value('status'))->toBe('active');
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_billing_competitor');
    }
});

test('an expired older session cannot release the current checkout claim', function () {
    [$actor, $company, $membership, $subscription, $provider] = billingBoundaryFixture('missing');
    app(StartBillingCheckoutAction::class)->execute($actor, $company, 'growth');
    app(ProcessStripeWebhookAction::class)->execute([
        'id' => 'evt_old_session_expired', 'type' => 'checkout.session.expired',
        'data' => ['object' => ['id' => 'cs_older', 'client_reference_id' => $company->uuid]],
    ]);
    $current = CompanySubscription::query()->forCompany($company)->first();
    expect($current)->not->toBeNull()
        ->and($current->status)->toBe(BillingSubscriptionStatus::Pending)
        ->and($current->plan_key)->toBe('growth');
});

test('ambiguous checkout creation retains its claim and blocks unsafe cancellation', function () {
    [$actor, $company, $membership, $subscription, $provider] = billingBoundaryFixture('missing');
    $provider->beforeCheckout = fn () => throw BillingProviderException::retryable('Response lost after possible creation.');
    expect(fn () => app(StartBillingCheckoutAction::class)->execute($actor, $company, 'growth'))
        ->toThrow(BillingProviderException::class);
    $current = CompanySubscription::query()->forCompany($company)->first();
    expect($current)->not->toBeNull()
        ->and($current->status)->toBe(BillingSubscriptionStatus::Pending)
        ->and($current->metadata['pending_checkout']['creation_uncertain'] ?? false)->toBeTrue();
    expect(fn () => app(CancelPendingBillingCheckoutAction::class)->execute($actor, $company))->toThrow(ValidationException::class);
    expect(fn () => app(StartBillingCheckoutAction::class)->execute($actor, $company, 'growth'))->toThrow(ValidationException::class);
    expect($provider->checkoutCalls)->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('portal session creation holds authorization until the provider call completes', function () {
    [$actor, $company, $membership, $subscription, $provider] = billingBoundaryFixture();
    $competitor = DB::connection('mysql_billing_competitor');
    $competitor->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $provider->beforePortal = function () use ($competitor, $membership): void {
        try {
            $competitor->table('company_user')->where('id', $membership->id)->update(['role' => 'viewer', 'is_owner' => false]);
            test()->fail('Portal authorization was revoked during session creation.');
        } catch (QueryException $exception) {
            expect((int) ($exception->errorInfo[1] ?? 0))->toBe(1205);
        }
    };
    try {
        expect(app(CreateBillingPortalSessionAction::class)->execute($actor, $company))->toBe(route('settings.billing.index'));
        $competitor->table('company_user')->where('id', $membership->id)->update(['role' => 'viewer', 'is_owner' => false]);
        expect(fn () => app(CreateBillingPortalSessionAction::class)->execute($actor, $company))->toThrow(AuthorizationException::class);
        expect($provider->portalCalls)->toBe(1);
    } finally {
        DB::disconnect('mysql_billing_competitor');
    }
});

test('checkout claim is durable before the external session creation starts', function () {
    [$actor, $company, $membership, $subscription, $provider] = billingBoundaryFixture('missing');
    $observed = false;
    $provider->beforeCheckout = function () use ($company, &$observed): void {
        $row = DB::connection('mysql_billing_competitor')->table('company_subscriptions')->where('company_id', $company->id)->first();
        expect($row)->not->toBeNull();
        $metadata = json_decode($row->metadata, true, 512, JSON_THROW_ON_ERROR);
        expect($row->status)->toBe('pending')
            ->and(Str::isUuid($metadata['pending_checkout']['claim_id']))->toBeTrue()
            ->and($metadata['pending_checkout']['creation_uncertain'])->toBeTrue();
        $observed = true;
    };
    try {
        app(StartBillingCheckoutAction::class)->execute($actor, $company, 'growth');
        $current = CompanySubscription::query()->forCompany($company)->firstOrFail();
        expect($observed)->toBeTrue()
            ->and($current->metadata['pending_checkout']['session_id'])->toBe('cs_boundary_1')
            ->and($current->metadata['pending_checkout']['creation_uncertain'] ?? false)->toBeFalse();
    } finally {
        DB::disconnect('mysql_billing_competitor');
    }
});
