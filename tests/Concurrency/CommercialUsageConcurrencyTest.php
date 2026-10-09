<?php

use App\Enums\Billing\BillingSubscriptionStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Services\Billing\CommercialUsageAdmission;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function commercialUsageConcurrencyFixture(): Company
{
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toBeIn(['nordipass_testing', 'nordipass_commercial_20261004_testing']);
    test()->artisan('migrate:fresh')->assertSuccessful();
    $company = Company::factory()->create();
    $company->forceFill(['commercial_model_version' => 1])->save();
    CompanySubscription::query()->create(['company_id' => $company->id, 'plan_key' => 'growth',
        'status' => BillingSubscriptionStatus::Active, 'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addDays(29), 'metadata' => ['commercial_model' => 'v1', 'billing_interval' => 'month'],
        'entitlements' => ['imports.enabled' => true, 'imports.jobs.max' => 1]]);

    return $company;
}

test('usage admission sees committed consumption behind an older repeatable read snapshot', function () {
    $company = commercialUsageConcurrencyFixture();
    $configuration = DB::connection()->getConfig();
    config(['database.connections.mysql_usage_competitor' => [...$configuration, 'name' => 'mysql_usage_competitor']]);
    DB::purge('mysql_usage_competitor');
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        expect(DB::table('commercial_usage_events')->count())->toBe(0);
        config(['database.default' => 'mysql_usage_competitor']);
        DB::transaction(fn () => app(CommercialUsageAdmission::class)->consume($company, 'imports', (string) Str::uuid()));
        config(['database.default' => 'mysql']);
        expect(DB::table('commercial_usage_events')->count())->toBe(0);
        expect(fn () => app(CommercialUsageAdmission::class)->consume($company, 'imports', (string) Str::uuid()))
            ->toThrow(ValidationException::class);
    } finally {
        config(['database.default' => 'mysql']);
        $primary->rollBack();
        DB::disconnect('mysql_usage_competitor');
    }
    expect(DB::table('commercial_usage_events')->count())->toBe(1)
        ->and(DB::table('commercial_usage_buckets')->value('used'))->toBe(1);
});

test('the last usage unit is locked until commit without blocking an unrelated tenant', function () {
    $company = commercialUsageConcurrencyFixture();
    $foreign = Company::factory()->create();
    $foreign->forceFill(['commercial_model_version' => 1])->save();
    CompanySubscription::query()->create(['company_id' => $foreign->id, 'plan_key' => 'growth',
        'status' => BillingSubscriptionStatus::Active, 'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addDays(29), 'metadata' => ['commercial_model' => 'v1'],
        'entitlements' => ['imports.enabled' => true, 'imports.jobs.max' => 1]]);
    $configuration = DB::connection()->getConfig();
    config(['database.connections.mysql_usage_competitor' => [...$configuration, 'name' => 'mysql_usage_competitor']]);
    DB::purge('mysql_usage_competitor');
    DB::connection('mysql_usage_competitor')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $primary = DB::connection();
    $primary->beginTransaction();
    try {
        app(CommercialUsageAdmission::class)->consume($company, 'imports', (string) Str::uuid());
        config(['database.default' => 'mysql_usage_competitor']);
        expect(fn () => DB::transaction(fn () => app(CommercialUsageAdmission::class)->consume($company, 'imports', (string) Str::uuid())))
            ->toThrow(DeadlockException::class, 'Lock wait timeout');
        expect(DB::connection()->transactionLevel())->toBe(0);
        DB::transaction(fn () => app(CommercialUsageAdmission::class)->consume($foreign, 'imports', (string) Str::uuid()));
        config(['database.default' => 'mysql']);
        $primary->commit();
        expect(fn () => DB::transaction(fn () => app(CommercialUsageAdmission::class)->consume($company, 'imports', (string) Str::uuid())))
            ->toThrow(ValidationException::class);
        expect(DB::table('commercial_usage_events')->where('company_id', $company->id)->count())->toBe(1)
            ->and(DB::table('commercial_usage_events')->where('company_id', $foreign->id)->count())->toBe(1);
    } finally {
        config(['database.default' => 'mysql']);
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_usage_competitor');
    }
});
