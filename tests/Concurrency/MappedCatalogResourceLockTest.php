<?php

use App\Actions\Catalog\Attributes\CreateAttributeDefinitionAction;
use App\Actions\Catalog\Attributes\SyncProductAttributeValuesAction;
use App\Actions\Catalog\Categories\CreateCategoryAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Products\UpdateProductAction;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\CatalogWriteTransaction;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('root mapped writes permit independent assignments without releasing resource validation locks', function (string $resource, bool $populated) {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    Http::preventStrayRequests();
    Queue::fake();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $actor]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $first = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'First']);
    $second = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Second']);
    $category = app(CreateCategoryAction::class)->execute($actor, $company, ['name' => 'Shared']);
    $definition = app(CreateAttributeDefinitionAction::class)->execute($actor, $company, ['name' => 'Text', 'code' => 'text', 'type' => 'text', 'scope' => 'product']);
    if ($populated) {
        foreach ([$first, $second] as $seeded) {
            app(UpdateProductAction::class)->execute($actor, $company, $seeded, [], $category->uuid, syncCategories: true);
            app(SyncProductAttributeValuesAction::class)->execute($actor, $company, $seeded, [$definition->uuid => 'Seed']);
        }
    }
    config(['database.connections.mysql_mapped_resource_competitor' => config('database.connections.mysql')]);
    DB::purge('mysql_mapped_resource_competitor');
    DB::connection('mysql_mapped_resource_competitor')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $isolation = $primary->scalar('SELECT @@SESSION.transaction_isolation', [], false);
    try {
        try {
            app(CatalogWriteTransaction::class)->run(function () use ($resource, $actor, $company, $first, $second, $category, $definition): void {
                if ($resource === 'attributes') {
                    app(SyncProductAttributeValuesAction::class)->execute($actor, $company, $first, [$definition->uuid => 'Pending']);
                } else {
                    app(UpdateProductAction::class)->execute($actor, $company, $first, [], $category->uuid, syncCategories: true);
                }
                config(['database.default' => 'mysql_mapped_resource_competitor']);
                try {
                    $tenant = Company::findOrFail($company->id);
                    app(CurrentCompany::class)->set($tenant);
                    $editor = User::findOrFail($actor->id);
                    $target = $tenant->products()->findOrFail($second->id);
                    app(UpdateProductAction::class)->execute($editor, $tenant, $target, ['brand' => 'Independent']);
                    if ($resource === 'attributes') {
                        app(SyncProductAttributeValuesAction::class)->execute($editor, $tenant, $target, [$definition->uuid => 'Racing']);
                        expect($target->attributeValues()->sole()->value_text)->toBe('Racing');
                    } else {
                        app(UpdateProductAction::class)->execute($editor, $tenant, $target, [], $category->uuid, syncCategories: true);
                        expect($target->fresh()->primary_category_id)->toBe($category->id);
                    }
                    $table = $resource === 'attributes' ? 'attribute_definitions' : 'categories';
                    $id = $resource === 'attributes' ? $definition->id : $category->id;
                    try {
                        DB::table($table)->where('id', $id)->update(['status' => 'archived']);
                        $this->fail('Raw resource mutation bypassed the shared validation lock.');
                    } catch (QueryException $exception) {
                        expect((int) $exception->errorInfo[1])->toBe(1205)->and($exception->getSql())->toContain($table);
                    }
                } finally {
                    config(['database.default' => 'mysql']);
                    app(CurrentCompany::class)->set($company);
                }
                throw new RuntimeException('Rollback the pending assignment');
            });
            $this->fail('Rollback fixture did not throw.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('Rollback the pending assignment');
        }
        expect($primary->scalar('SELECT @@SESSION.transaction_isolation', [], false))->toBe($isolation)
            ->and($second->fresh()->brand)->toBe('Independent')
            ->and($first->fresh()->primary_category_id)->toBe($populated ? $category->id : null)
            ->and(DB::table('product_attribute_values')->count())->toBe($populated ? 2 : ($resource === 'attributes' ? 1 : 0))
            ->and(DB::table('category_product')->count())->toBe($populated ? 2 : ($resource === 'categories' ? 1 : 0));
        if ($populated) {
            expect($first->attributeValues()->sole()->value_text)->toBe('Seed');
        }
    } finally {
        config(['database.default' => 'mysql']);
        app(CurrentCompany::class)->set($company);
        DB::disconnect('mysql_mapped_resource_competitor');
    }
})->with([['attributes', false], ['attributes', true], ['categories', false], ['categories', true]]);

test('catalogue write transaction preserves outer repeatable read isolation and restores successful root state', function () {
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $transaction = app(CatalogWriteTransaction::class);
    expect($transaction->run(fn () => DB::scalar('SELECT @@SESSION.transaction_isolation', [], false)))->toBe('READ-COMMITTED');
    expect(DB::scalar('SELECT @@SESSION.transaction_isolation', [], false))->toBe('REPEATABLE-READ');
    DB::beginTransaction();
    try {
        expect($transaction->run(fn () => DB::scalar('SELECT @@SESSION.transaction_isolation', [], false)))->toBe('REPEATABLE-READ');
        expect(DB::transactionLevel())->toBe(1);
    } finally {
        DB::rollBack();
    }
});
