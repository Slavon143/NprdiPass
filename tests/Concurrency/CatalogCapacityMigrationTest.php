<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\CatalogCapacityReconciler;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function capacityMigrationFixture(): array
{
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    test()->artisan('migrate:fresh')->assertSuccessful();
    Http::preventStrayRequests();
    Queue::fake();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $company]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    app(CatalogCapacityReconciler::class)->reconcile($company->id, true);
    $product = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Migrated']);

    return [$actor, $company, $product];
}

test('capacity migration roundtrip preserves known and unknown counts and active SQL deltas', function () {
    [, $company, $product] = capacityMigrationFixture();
    $unknown = Company::factory()->create();
    expect(Schema::hasColumn('companies', 'catalog_products_count'))->toBeFalse();
    expect($company->load('catalogCapacity')->toArray())->not->toHaveKey('catalog_capacity');
    $migration = require database_path('migrations/2026_10_03_000004_separate_catalog_capacity_mutex.php');
    $migration->down();
    expect(Schema::hasTable('catalog_product_capacities'))->toBeFalse()
        ->and(DB::table('companies')->where('id', $company->id)->value('catalog_products_count'))->toBe(1)
        ->and(DB::table('companies')->where('id', $unknown->id)->value('catalog_products_count'))->toBeNull();
    expect($company->fresh()->toArray())->not->toHaveKey('catalog_products_count');
    DB::table('products')->where('company_id', $company->id)->where('id', $product->id)->update(['status' => 'archived']);
    expect(DB::table('companies')->where('id', $company->id)->value('catalog_products_count'))->toBe(0);
    $migration->up();
    expect(Schema::hasColumn('companies', 'catalog_products_count'))->toBeFalse()
        ->and($company->catalogCapacity()->value('products_count'))->toBe(0)
        ->and($unknown->catalogCapacity()->value('products_count'))->toBeNull();
    DB::table('products')->where('company_id', $company->id)->where('id', $product->id)->update(['status' => 'draft']);
    expect(app(CatalogCapacityReconciler::class)->reconcile($company->id))->toMatchArray(['previous_count' => 1, 'actual_count' => 1, 'repaired' => false]);
    $unknown->forceDelete();
    expect(DB::table('catalog_product_capacities')->where('company_id', $unknown->id)->exists())->toBeFalse();
});

test('missing capacity rows deny application and SQL writes until explicit root repair', function () {
    [$actor, $company] = capacityMigrationFixture();
    DB::table('catalog_product_capacities')->where('company_id', $company->id)->delete();
    expect(fn () => app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Denied']))
        ->toThrow(ModelNotFoundException::class);
    expect(fn () => DB::table('products')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'name' => 'SQL denied', 'slug' => 'sql-denied', 'slug_normalized' => 'sql-denied', 'status' => 'draft']))
        ->toThrow(QueryException::class, 'Missing catalogue capacity row');
    expect($company->products()->count())->toBe(1);
    expect(fn () => app(CatalogCapacityReconciler::class)->reconcile($company->id))->toThrow(LogicException::class);
    expect(app(CatalogCapacityReconciler::class)->reconcile($company->id, true))->toMatchArray(['previous_count' => null, 'actual_count' => 1, 'repaired' => true]);
    app(CreateProductAction::class)->execute($actor, $company, ['name' => 'After repair']);
    expect(app(CatalogCapacityReconciler::class)->reconcile($company->id))->toMatchArray(['previous_count' => 2, 'actual_count' => 2, 'repaired' => false]);
});
