<?php

use App\Data\Billing\StripeSubscriptionProjection;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Services\Billing\BillingProjectionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function billingProjectionBoundaryFixture(): array
{
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    test()->artisan('migrate:fresh')->assertSuccessful();
    $company = Company::factory()->create();
    $subscription = CompanySubscription::query()->create([
        'company_id' => $company->id, 'plan_key' => 'pilot', 'status' => BillingSubscriptionStatus::PastDue,
        'stripe_customer_id' => 'cus_projection', 'stripe_subscription_id' => 'sub_projection',
        'grace_ends_at' => now()->subDay(),
    ]);
    config()->set('database.connections.mysql_projection_competitor', config('database.connections.mysql'));
    DB::purge('mysql_projection_competitor');

    return [$company, $subscription, DB::connection('mysql_projection_competitor')];
}

test('billing projection respects the tenant mutation mutex before replacing a subscription', function () {
    [$company, $subscription, $competitor] = billingProjectionBoundaryFixture();
    $competitor->beginTransaction();
    $competitor->table('companies')->where('id', $company->id)->lockForUpdate()->first();
    $previousTimeout = (int) DB::connection()->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS timeout')->timeout;
    DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = 1');
    try {
        try {
            app(BillingProjectionService::class)->upsert($company, new StripeSubscriptionProjection(
                stripeCustomerId: 'cus_projection', stripeSubscriptionId: 'sub_projection',
                stripePriceId: null, planKey: 'pilot', status: BillingSubscriptionStatus::Active,
            ));
            $this->fail('Projection bypassed the company mutex.');
        } catch (QueryException $exception) {
            expect((int) ($exception->errorInfo[1] ?? 0))->toBe(1205);
        }
        expect($subscription->fresh()->status)->toBe(BillingSubscriptionStatus::PastDue);
    } finally {
        DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = '.$previousTimeout);
        $competitor->rollBack();
        DB::disconnect('mysql_projection_competitor');
    }
});

test('billing projection preserves current grace data behind an older read view', function () {
    [$company, $subscription, $competitor] = billingProjectionBoundaryFixture();
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    $newGrace = now()->addDays(3)->startOfSecond();
    try {
        $subscription->fresh();
        $competitor->table('company_subscriptions')->where('id', $subscription->id)->update(['grace_ends_at' => $newGrace]);
        $current = app(BillingProjectionService::class)->upsert($company, new StripeSubscriptionProjection(
            stripeCustomerId: 'cus_projection', stripeSubscriptionId: 'sub_projection',
            stripePriceId: null, planKey: 'pilot', status: BillingSubscriptionStatus::PastDue,
        ));
        expect($current->grace_ends_at->equalTo($newGrace))->toBeTrue()
            ->and($current->grantsEntitlements())->toBeTrue();
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_projection_competitor');
    }
});
