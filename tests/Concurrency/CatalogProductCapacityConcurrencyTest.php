<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\CatalogProductCapacity;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

test('capacity locking read sees a committed create after an earlier repeatable read snapshot', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $company]);
    CompanySubscription::query()->create(['company_id' => $company->id, 'plan_key' => 'growth',
        'status' => BillingSubscriptionStatus::Active, 'entitlements' => ['catalog.products.max' => 1]]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    config()->set('database.connections.mysql_capacity_competitor', config('database.connections.mysql'));
    DB::purge('mysql_capacity_competitor');
    $primary = DB::connection('mysql');
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        expect(app(CatalogProductCapacity::class)->count($company))->toBe(0);
        config()->set('database.default', 'mysql_capacity_competitor');
        $competitorCompany = Company::query()->findOrFail($company->id);
        DB::transaction(function () use ($company, $competitorCompany): void {
            app(CatalogProductCapacity::class)->assertSlotAvailable($competitorCompany);
            DB::table('products')->insert(['uuid' => (string) Str::uuid(),
                'company_id' => $company->id, 'name' => 'Committed', 'slug' => 'committed',
                'slug_normalized' => 'committed', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        });
        config()->set('database.default', 'mysql');
        expect(app(CatalogProductCapacity::class)->count($company))->toBe(0);
        expect(fn () => app(CatalogProductCapacity::class)->assertSlotAvailable($company))
            ->toThrow(ValidationException::class, 'catalog.products.max');
    } finally {
        config()->set('database.default', 'mysql');
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_capacity_competitor');
    }
});

test('concurrent product creates serialize at the last catalog slot', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');

    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $company]);
    CompanySubscription::query()->create([
        'company_id' => $company->id,
        'plan_key' => 'growth',
        'status' => BillingSubscriptionStatus::Active,
        'entitlements' => ['catalog.products.max' => 1],
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    config()->set('database.connections.mysql_capacity_competitor', config('database.connections.mysql'));
    DB::purge('mysql_capacity_competitor');
    $competitor = DB::connection('mysql_capacity_competitor');
    $competitor->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $primary = DB::connection('mysql');
    $primary->beginTransaction();

    try {
        app(CreateProductAction::class)->execute($actor, $company, ['name' => 'First', 'slug' => 'first']);

        config()->set('database.default', 'mysql_capacity_competitor');
        $competitor->beginTransaction();
        expect(fn () => app(CatalogProductCapacity::class)->assertSlotAvailable($company))
            ->toThrow(QueryException::class);
        $competitor->rollBack();
        config()->set('database.default', 'mysql');
        $primary->commit();

        expect(fn () => app(CreateProductAction::class)->execute($actor, $company, [
            'name' => 'Second', 'slug' => 'second',
        ]))->toThrow(ValidationException::class, 'catalog.products.max');
        expect(Product::query()->forCompany($company)->count())->toBe(1);
    } finally {
        config()->set('database.default', 'mysql');
        if ($competitor->transactionLevel() > 0) {
            $competitor->rollBack();
        }
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_capacity_competitor');
    }
});

test('capacity admits a create after a committed release behind an older snapshot', function (string $release) {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $company]);
    CompanySubscription::query()->create(['company_id' => $company->id, 'plan_key' => 'growth',
        'status' => BillingSubscriptionStatus::Active, 'entitlements' => ['catalog.products.max' => 1]]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $action = app(CreateProductAction::class);
    $old = $action->execute($actor, $company, ['name' => 'Old', 'slug' => 'old']);
    $capacity = app(CatalogProductCapacity::class);
    config()->set('database.connections.mysql_capacity_release', config('database.connections.mysql'));
    DB::purge('mysql_capacity_release');
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();

    try {
        expect($capacity->count($company))->toBe(1);
        DB::connection('mysql_capacity_release')->table('products')
            ->where('company_id', $company->id)->where('id', $old->id)
            ->update($release === 'archive' ? ['status' => 'archived'] : ['deleted_at' => now()]);
        expect($capacity->count($company))->toBe(1);

        $replacement = $action->execute($actor, $company, ['name' => 'Replacement', 'slug' => 'replacement']);
        $primary->commit();

        expect($capacity->count($company))->toBe(1)
            ->and($replacement->defaultVariant?->product_id)->toBe($replacement->id)
            ->and($replacement->defaultVariant?->company_id)->toBe($company->id);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_capacity_release');
    }
})->with(['archive', 'soft delete']);

test('capacity admission does not lock unrelated product fields while company serialization remains held', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $company]);
    CompanySubscription::query()->create(['company_id' => $company->id, 'plan_key' => 'growth',
        'status' => BillingSubscriptionStatus::Active, 'entitlements' => ['catalog.products.max' => 2]]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $product = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Existing', 'slug' => 'existing']);
    config()->set('database.connections.mysql_capacity_editor', config('database.connections.mysql'));
    DB::purge('mysql_capacity_editor');
    $editor = DB::connection('mysql_capacity_editor');
    $editor->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $primary = DB::connection();
    $primary->beginTransaction();

    try {
        app(CatalogProductCapacity::class)->assertSlotAvailable($company);
        expect($editor->table('products')->where('company_id', $company->id)->where('id', $product->id)
            ->update(['brand' => 'Concurrent edit']))->toBe(1);
        $editor->beginTransaction();
        expect(fn () => $editor->table('products')->where('company_id', $company->id)->where('id', $product->id)
            ->update(['status' => 'archived']))->toThrow(QueryException::class, 'Lock wait timeout');
        expect(fn () => $editor->table('companies')->where('id', $company->id)->lockForUpdate()->first())
            ->toThrow(QueryException::class, 'Lock wait timeout');
    } finally {
        if ($editor->transactionLevel() > 0) {
            $editor->rollBack();
        }
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_capacity_editor');
    }

    expect($product->fresh()->brand)->toBe('Concurrent edit');
});
