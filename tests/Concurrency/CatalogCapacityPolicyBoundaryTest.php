<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Billing\EntitlementOverride;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Catalog\CatalogCapacityReconciler;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function capacityPolicyFixture(bool $initialized): array
{
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    test()->artisan('migrate:fresh')->assertSuccessful();
    Http::preventStrayRequests();
    Queue::fake();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    if ($initialized) {
        app(CatalogCapacityReconciler::class)->reconcile($company->id, true);
    }
    CompanyMembership::factory()->owner()->create(['company_id' => $company->id, 'user_id' => $actor->id]);
    app(CurrentCompany::class)->set($company);
    config(['database.connections.mysql_policy_competitor' => config('database.connections.mysql')]);
    DB::purge('mysql_policy_competitor');

    return [$actor, $company, DB::connection('mysql_policy_competitor')];
}

test('capacity uses committed policy changes behind an older snapshot', function (string $change, bool $allowed, bool $initialized) {
    [$actor, $company, $competitor] = capacityPolicyFixture($initialized);
    $overrideCase = str_starts_with($change, 'override');
    $oldLimit = $allowed ? 0 : 1;
    $subscription = CompanySubscription::query()->create(['company_id' => $company->id, 'plan_key' => 'growth',
        'status' => BillingSubscriptionStatus::Active,
        'entitlements' => ['catalog.products.max' => $overrideCase && $change !== 'override_created' ? 0 : $oldLimit]]);
    $override = in_array($change, ['override_revoked', 'override_increased'], true)
        ? EntitlementOverride::query()->create(['company_id' => $company->id, 'key' => 'catalog.products.max', 'value' => $oldLimit, 'reason' => 'Policy fixture']) : null;
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        expect(app(EntitlementService::class)->limit($company, 'catalog.products.max'))->toBe($oldLimit);
        $competitor->transaction(function () use ($competitor, $company, $subscription, $override, $change, $allowed): void {
            $competitor->table('companies')->where('id', $company->id)->lockForUpdate()->first();
            match ($change) {
                'subscription_reduced', 'subscription_increased' => $competitor->table($subscription->getTable())->where('id', $subscription->id)
                    ->update(['entitlements' => json_encode(['catalog.products.max' => $allowed ? 1 : 0])]),
                'override_created' => $competitor->table((new EntitlementOverride)->getTable())->insert([
                    'company_id' => $company->id, 'key' => 'catalog.products.max', 'value' => '0', 'reason' => 'Policy fixture', 'created_at' => now(), 'updated_at' => now(),
                ]),
                'override_revoked' => $competitor->table($override->getTable())->where('id', $override->id)->update(['revoked_at' => now()]),
                'override_increased' => $competitor->table($override->getTable())->where('id', $override->id)->update(['value' => '1']),
            };
        });
        expect(app(EntitlementService::class)->limit($company, 'catalog.products.max'))->toBe($oldLimit);
        $create = fn () => app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Policy boundary product']);
        if ($allowed) {
            $product = $create();
            expect($product->exists)->toBeTrue()->and($product->default_variant_id)->not->toBeNull();
        } else {
            expect($create)->toThrow(ValidationException::class, 'catalog.products.max');
            expect(Product::query()->forCompany($company)->count())->toBe(0);
        }
    } finally {
        $primary->rollBack();
        DB::disconnect('mysql_policy_competitor');
    }
    expect(Product::query()->forCompany($company)->count())->toBe(0);
})->with([
    ['subscription_reduced', false], ['subscription_increased', true], ['override_created', false],
    ['override_revoked', false], ['override_increased', true],
])->with([false, true]);

test('admission holds its selected entitlement row through the outer transaction', function (bool $overridePolicy, bool $initialized) {
    [$actor, $company, $competitor] = capacityPolicyFixture($initialized);
    $subscription = CompanySubscription::query()->create(['company_id' => $company->id, 'plan_key' => 'growth',
        'status' => BillingSubscriptionStatus::Active, 'entitlements' => ['catalog.products.max' => 1]]);
    $policy = $overridePolicy ? EntitlementOverride::query()->create([
        'company_id' => $company->id, 'key' => 'catalog.products.max', 'value' => 1, 'reason' => 'Policy fixture',
    ]) : $subscription;
    $competitor->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $primary = DB::connection();
    $primary->beginTransaction();
    try {
        app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Accepted product']);
        $change = $overridePolicy ? ['value' => '0'] : ['entitlements' => json_encode(['catalog.products.max' => 0])];
        try {
            $competitor->table($policy->getTable())->where('id', $policy->id)->update($change);
            $this->fail('Selected policy changed before the accepted aggregate committed.');
        } catch (QueryException $exception) {
            expect((int) ($exception->errorInfo[1] ?? 0))->toBe(1205);
        }
        $primary->commit();
        expect($competitor->table($policy->getTable())->where('id', $policy->id)->update($change))->toBe(1);
        expect(fn () => app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Denied product']))
            ->toThrow(ValidationException::class, 'catalog.products.max');
        expect(Product::query()->forCompany($company)->count())->toBe(1);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_policy_competitor');
    }
})->with([false, true])->with([false, true]);
