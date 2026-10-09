<?php

use App\Actions\Catalog\CreateProductWithDefaultVariantAction;
use App\Actions\Catalog\Lifecycle\ArchiveProductAction;
use App\Actions\Catalog\Lifecycle\BulkArchiveProductsAction;
use App\Actions\Catalog\Lifecycle\RestoreProductAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\Catalog\ProductStatus;
use App\Models\AuditLog;
use App\Models\Billing\CompanySubscription;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\CatalogCapacityReconciler;
use App\Services\Catalog\CatalogProductCapacity;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function counterFixture(?int $limit = 3): array
{
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    test()->artisan('migrate:fresh')->assertSuccessful();
    Http::preventStrayRequests();
    Queue::fake();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $actor]);
    CompanySubscription::query()->create(['company_id' => $company->id, 'plan_key' => 'growth',
        'status' => BillingSubscriptionStatus::Active, 'entitlements' => ['catalog.products.max' => $limit]]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company];
}

function counterCreate(User $actor, Company $company, string $slug): Product
{
    return app(CreateProductAction::class)->execute($actor, $company, ['name' => $slug, 'slug' => $slug]);
}

function counterValue(Company $company): ?int
{
    return $company->catalogCapacity()->value('products_count');
}

test('reconciliation detects total pool drift independently from managed slots and retains deleted rows', function () {
    [$actor, $company] = counterFixture();
    $product = counterCreate($actor, $company, 'retained-pool');
    $product->delete();
    $reconciler = app(CatalogCapacityReconciler::class);
    $reconciler->reconcile($company->id, true);
    DB::table('catalog_product_capacities')->where('company_id', $company->id)->update(['total_products_count' => 0]);
    $result = $reconciler->reconcile($company->id);
    expect($result['previous_count'])->toBe(0)->and($result['actual_count'])->toBe(0)
        ->and($result['previous_total'])->toBe(0)->and($result['actual_total'])->toBe(1)
        ->and($result['repaired'])->toBeFalse();
    $this->artisan('catalog:capacity-reconcile', ['--company' => $company->uuid])->assertExitCode(1);
    $repaired = $reconciler->reconcile($company->id, true);
    expect($repaired['repaired'])->toBeTrue()
        ->and($company->catalogCapacity()->value('total_products_count'))->toBe(1);
    $this->artisan('catalog:capacity-reconcile', ['--company' => $company->uuid])->assertSuccessful();
});

test('unknown capacity falls back until tenant scoped reconciliation initializes the exact count', function () {
    [$actor, $company] = counterFixture(1);
    $foreign = Company::factory()->create();
    $product = counterCreate($actor, $company, 'before-backfill');
    expect(counterValue($company))->toBeNull();
    expect(fn () => counterCreate($actor, $company, 'full-before-backfill'))->toThrow(ValidationException::class);
    $this->artisan('catalog:capacity-reconcile', ['--company' => $company->uuid])->assertExitCode(1);
    expect(counterValue($company))->toBeNull();
    $this->artisan('catalog:capacity-reconcile', ['--company' => $company->uuid, '--repair' => true])->assertSuccessful();
    expect(counterValue($company))->toBe(1)->and(counterValue($foreign))->toBeNull();
    $this->artisan('catalog:capacity-reconcile', ['--company' => $company->uuid])->assertSuccessful();
    expect(fn () => counterCreate($actor, $company, 'full-after-backfill'))->toThrow(ValidationException::class);
    expect($company->fresh()->toArray())->not->toHaveKey('catalog_products_count');
    expect($company->isFillable('catalog_products_count'))->toBeFalse();
    app(ArchiveProductAction::class)->execute($actor, $company, $product);
    counterCreate($actor, $company, 'replacement-at-known-limit');
    expect(fn () => app(RestoreProductAction::class)->execute($actor, $company, $product->fresh()))
        ->toThrow(ValidationException::class, 'catalog.products.max');
    expect(counterValue($company))->toBe(1)->and($product->fresh()->status)->toBe(ProductStatus::Archived);
});

test('both aggregate creators and idempotent lifecycle actions maintain known capacity once', function () {
    [$actor, $company] = counterFixture();
    app(CatalogCapacityReconciler::class)->reconcile($company->id, true);
    $first = counterCreate($actor, $company, 'first');
    $second = app(CreateProductWithDefaultVariantAction::class)->execute($actor, $company,
        ['name' => 'Second', 'slug' => 'second'], ['name' => 'Default']);
    expect(counterValue($company))->toBe(2)->and($company->productVariants()->count())->toBe(2);
    // Eligible-to-eligible transitions do not acquire a second slot.
    $first->forceFill(['status' => ProductStatus::Active])->save();
    $first->forceFill(['status' => ProductStatus::Draft])->save();
    expect(counterValue($company))->toBe(2);
    app(ArchiveProductAction::class)->execute($actor, $company, $first);
    app(ArchiveProductAction::class)->execute($actor, $company, $first->fresh());
    expect(counterValue($company))->toBe(1);
    app(RestoreProductAction::class)->execute($actor, $company, $first->fresh());
    app(RestoreProductAction::class)->execute($actor, $company, $first->fresh());
    expect(counterValue($company))->toBe(2);
    $bulk = app(BulkArchiveProductsAction::class);
    expect($bulk->execute($actor, $company, [$first->uuid, $second->uuid]))->toHaveCount(2);
    expect($bulk->execute($actor, $company, [$first->uuid, $second->uuid]))->toBe([]);
    expect(counterValue($company))->toBe(0);
});

test('SQL and model soft delete restore physical delete and tenant move preserve exact counts', function () {
    [$actor, $company] = counterFixture();
    $foreign = Company::factory()->create();
    $reconciler = app(CatalogCapacityReconciler::class);
    $reconciler->reconcile($company->id, true);
    $reconciler->reconcile($foreign->id, true);
    $product = counterCreate($actor, $company, 'soft-delete');
    $product->delete();
    expect(counterValue($company))->toBe(0);
    $product->restore();
    expect(counterValue($company))->toBe(1);
    $id = DB::table('products')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'name' => 'SQL', 'slug' => 'sql', 'slug_normalized' => 'sql', 'status' => 'draft']);
    expect(counterValue($company))->toBe(2);
    DB::table('products')->where('id', $id)->update(['company_id' => $foreign->id]);
    expect(counterValue($company))->toBe(1)->and(counterValue($foreign))->toBe(1);
    DB::table('products')->where('id', $id)->update(['status' => 'archived']);
    expect(counterValue($foreign))->toBe(0);
    DB::table('products')->where('id', $id)->update(['company_id' => $company->id, 'status' => 'active']);
    expect(counterValue($company))->toBe(2)->and(counterValue($foreign))->toBe(0);
    DB::table('products')->where('id', $id)->delete();
    expect(counterValue($company))->toBe(1);
    DB::table('products')->where('id', $product->id)->update(['deleted_at' => now()]);
    DB::table('products')->where('id', $product->id)->update(['status' => 'archived']);
    expect(counterValue($company))->toBe(0);
    // Existing aggregate foreign keys still reject deletion while referenced.
    expect(fn () => $product->refresh()->forceDelete())->toThrow(QueryException::class);
    expect(counterValue($company))->toBe(0);
    DB::table('products')->where('company_id', $company->id)->where('id', $product->id)->update(['default_variant_id' => null]);
    $product->refresh()->forceDelete();
    expect(counterValue($company))->toBe(0);
});

test('counter deltas follow aggregate root and savepoint rollback without consuming a retry slot', function () {
    [$actor, $company] = counterFixture(1);
    app(CatalogCapacityReconciler::class)->reconcile($company->id, true);
    expect(fn () => DB::transaction(function () use ($actor, $company): void {
        counterCreate($actor, $company, 'root-rollback');
        expect(counterValue($company))->toBe(1);
        throw new RuntimeException('Root rollback');
    }))->toThrow(RuntimeException::class, 'Root rollback');
    expect(counterValue($company))->toBe(0)->and($company->products()->count())->toBe(0)
        ->and($company->productVariants()->count())->toBe(0);
    DB::transaction(function () use ($actor, $company): void {
        expect(fn () => DB::transaction(function () use ($actor, $company): void {
            counterCreate($actor, $company, 'row-rollback');
            throw new RuntimeException('Row rollback');
        }))->toThrow(RuntimeException::class, 'Row rollback');
        expect(counterValue($company))->toBe(0);
        counterCreate($actor, $company, 'retry');
    });
    expect(counterValue($company))->toBe(1)->and($company->products()->count())->toBe(1)
        ->and($company->productVariants()->count())->toBe(1);
});

test('unlimited writes maintain counters before a finite downgrade and known admission does not scan products', function () {
    [$actor, $company] = counterFixture(null);
    app(CatalogCapacityReconciler::class)->reconcile($company->id, true);
    counterCreate($actor, $company, 'unlimited');
    expect(counterValue($company))->toBe(1);
    $company->subscription()->firstOrFail()->update(['entitlements' => ['catalog.products.max' => 1]]);
    expect(fn () => counterCreate($actor, $company, 'downgraded'))->toThrow(ValidationException::class);
    $company->subscription()->firstOrFail()->update(['entitlements' => ['catalog.products.max' => 2]]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        DB::transaction(fn () => app(CatalogProductCapacity::class)->assertSlotAvailable($company));
        expect(collect(DB::getQueryLog())->contains(fn ($query) => str_contains($query['query'], '`products`')))->toBeFalse();
    } finally {
        DB::disableQueryLog();
    }
});

test('reconciliation refuses outer snapshots detects drift audits it and repairs atomically', function () {
    [$actor, $company] = counterFixture();
    counterCreate($actor, $company, 'existing');
    $reconciler = app(CatalogCapacityReconciler::class);
    expect(fn () => DB::transaction(fn () => $reconciler->reconcile($company->id, true)))
        ->toThrow(LogicException::class, 'fresh root transaction');
    DB::table('catalog_product_capacities')->where('company_id', $company->id)->update(['products_count' => 9]);
    $result = $reconciler->reconcile($company->id);
    expect($result)->toMatchArray(['previous_count' => 9, 'actual_count' => 1, 'repaired' => false]);
    expect(counterValue($company))->toBe(9);
    $audit = AuditLog::query()->where('company_id', $company->id)->where('event', AuditEvent::CatalogCapacityReconciled->value)->firstOrFail();
    expect($audit->properties->get('previous_count'))->toBe(9)->and($audit->properties->get('actual_count'))->toBe(1);
    $this->mock(AuditLogger::class)->shouldReceive('logTenant')->once()->andThrow(new RuntimeException('Audit failed'));
    expect(fn () => app(CatalogCapacityReconciler::class)->reconcile($company->id, true))->toThrow(RuntimeException::class, 'Audit failed');
    expect(counterValue($company))->toBe(9);
    app()->forgetInstance(AuditLogger::class);
    app()->forgetInstance(CatalogCapacityReconciler::class);
    $this->artisan('catalog:capacity-reconcile', ['--all-companies' => true, '--repair' => true])->assertSuccessful();
    expect(counterValue($company))->toBe(1);
});

test('capacity reconciliation rejects ambiguous invalid and missing tenant selectors', function () {
    counterFixture();
    $this->artisan('catalog:capacity-reconcile')->assertExitCode(2);
    $this->artisan('catalog:capacity-reconcile', ['--company' => 'invalid'])->assertExitCode(2);
    $this->artisan('catalog:capacity-reconcile', ['--company' => (string) Str::uuid(), '--all-companies' => true])->assertExitCode(2);
    $this->artisan('catalog:capacity-reconcile', ['--company' => (string) Str::uuid()])->assertExitCode(3);
});

test('known counter admission sees committed cardinality changes behind an older snapshot', function (string $change) {
    [$actor, $company] = counterFixture(1);
    app(CatalogCapacityReconciler::class)->reconcile($company->id, true);
    $product = $change === 'insert' ? null : counterCreate($actor, $company, 'old');
    config(['database.connections.mysql_counter_competitor' => config('database.connections.mysql')]);
    DB::purge('mysql_counter_competitor');
    $competitor = DB::connection('mysql_counter_competitor');
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        $old = counterValue($company);
        if ($product === null) {
            $competitor->table('products')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
                'name' => 'Competitor', 'slug' => 'competitor', 'slug_normalized' => 'competitor', 'status' => 'draft']);
        } else {
            $competitor->table('products')->where('id', $product->id)->update(
                $change === 'archive' ? ['status' => 'archived'] : ['deleted_at' => now()]);
        }
        expect(counterValue($company))->toBe($old);
        if ($change === 'insert') {
            expect(fn () => counterCreate($actor, $company, 'rejected'))->toThrow(ValidationException::class);
        } else {
            counterCreate($actor, $company, 'replacement');
        }
        $primary->commit();
        expect(counterValue($company))->toBe(1)->and(app(CatalogProductCapacity::class)->count($company))->toBe(1);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_counter_competitor');
    }
})->with(['insert', 'archive', 'soft-delete']);

test('known capacity keeps last slot serialized while unrelated tenant and product metadata remain writable', function () {
    [$actor, $company] = counterFixture(2);
    $foreign = Company::factory()->create();
    $reconciler = app(CatalogCapacityReconciler::class);
    $reconciler->reconcile($company->id, true);
    $reconciler->reconcile($foreign->id, true);
    $product = counterCreate($actor, $company, 'first');
    config(['database.connections.mysql_counter_editor' => config('database.connections.mysql')]);
    DB::purge('mysql_counter_editor');
    $editor = DB::connection('mysql_counter_editor');
    $editor->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $primary = DB::connection();
    $primary->beginTransaction();
    try {
        app(CatalogProductCapacity::class)->assertSlotAvailable($company);
        expect($editor->table('products')->where('id', $product->id)->update(['brand' => 'Concurrent']))->toBe(1);
        expect(fn () => $editor->table('products')->where('id', $product->id)->update(['status' => 'archived']))
            ->toThrow(QueryException::class, 'Lock wait timeout');
        $editor->table('products')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $foreign->id,
            'name' => 'Foreign', 'slug' => 'foreign', 'slug_normalized' => 'foreign', 'status' => 'draft']);
        expect($editor->table('catalog_product_capacities')->where('company_id', $foreign->id)->value('products_count'))->toBe(1);
        counterCreate($actor, $company, 'last');
        $primary->commit();
        expect(counterValue($company))->toBe(2);
        expect(fn () => counterCreate($actor, $company, 'full'))->toThrow(ValidationException::class);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_counter_editor');
    }
});
