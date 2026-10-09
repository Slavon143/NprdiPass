<?php

use App\Actions\Catalog\Attributes\SyncProductAttributeValuesAction;
use App\Actions\Catalog\Attributes\SyncVariantAttributeValuesAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Enums\Catalog\AttributeScope;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Models\AuditLog;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function mappedStateFixture(string $type = 'multiselect'): array
{
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    test()->artisan('migrate:fresh')->assertSuccessful();
    Http::preventStrayRequests();
    Queue::fake();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $actor]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $product = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Mapped state']);
    $definition = new AttributeDefinition;
    $definition->forceFill(['company_id' => $company->id, 'name' => 'Choice', 'code' => 'choice',
        'type' => $type, 'scope' => AttributeScope::Both, 'status' => 'active', 'sort_order' => 1])->save();
    $options = [];
    foreach (['first', 'second'] as $code) {
        $option = new AttributeOption;
        $option->forceFill(['company_id' => $company->id, 'attribute_definition_id' => $definition->id,
            'label' => $code, 'code' => $code, 'status' => 'active', 'approval_status' => 'approved', 'sort_order' => 1])->save();
        $options[] = $option;
    }
    config(['database.connections.mysql_mapped_state_competitor' => config('database.connections.mysql')]);
    DB::purge('mysql_mapped_state_competitor');
    $competitor = DB::connection('mysql_mapped_state_competitor');
    $competitor->statement('SET SESSION innodb_lock_wait_timeout = 1');

    return [$actor, $company, $product, $definition, ...$options, $competitor];
}

test('attribute mutations reject committed option changes behind an older transaction snapshot', function (string $target, string $type) {
    [$actor, $company, $product, $definition, $option, , $competitor] = mappedStateFixture($type);
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        expect(DB::table('attribute_options')->where('id', $option->id)->value('status'))->toBe('active');
        $competitor->table('attribute_options')->where('id', $option->id)->update(['status' => 'archived']);
        // Prove that the ordinary snapshot remains stale while the mutation must use current state.
        expect(DB::table('attribute_options')->where('id', $option->id)->value('status'))->toBe('active');
        $payload = [$definition->uuid => $type === 'select' ? $option->id : [$option->id]];
        $operation = fn () => $target === 'product'
            ? app(SyncProductAttributeValuesAction::class)->execute($actor, $company, $product, $payload)
            : app(SyncVariantAttributeValuesAction::class)->execute($actor, $company, $product, $product->defaultVariant, $payload);
        expect($operation)->toThrow(AttributeOperationException::class);
        $primary->commit();
        expect(DB::table('product_attribute_values')->count())->toBe(0)
            ->and(DB::table('variant_attribute_values')->count())->toBe(0)
            ->and(AuditLog::query()->whereIn('event', ['catalog.product.attributes.updated', 'catalog.variant.attributes.updated'])->count())->toBe(0);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_mapped_state_competitor');
    }
})->with([['product', 'select'], ['product', 'multiselect'], ['variant', 'select'], ['variant', 'multiselect']]);

test('attribute merge retains the current omitted multiselect rather than reverting a committed selection', function () {
    [$actor, $company, $product, $definition, $first, $second, $competitor] = mappedStateFixture();
    $action = app(SyncProductAttributeValuesAction::class);
    $action->execute($actor, $company, $product, [$definition->uuid => [$first->id]]);
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        DB::table('product_attribute_value_options')->where('company_id', $company->id)->get();
        config(['database.default' => 'mysql_mapped_state_competitor']);
        try {
            $tenant = Company::findOrFail($company->id);
            app(CurrentCompany::class)->set($tenant);
            app(SyncProductAttributeValuesAction::class)->execute(User::findOrFail($actor->id), $tenant,
                $tenant->products()->findOrFail($product->id), [$definition->uuid => [$second->id]]);
        } finally {
            config(['database.default' => 'mysql']);
            app(CurrentCompany::class)->set($company);
        }
        $before = $competitor->table('activity_log')->where('company_id', $company->id)->count();
        $result = $action->merge($actor, $company, $product, []);
        expect($result->attributeValues->firstWhere('attribute_definition_id', $definition->id)->selectedOptions->pluck('id')->all())->toBe([$second->id]);
        $primary->commit();
        expect(DB::table('product_attribute_value_options')->where('company_id', $company->id)->pluck('attribute_option_id')->all())->toBe([$second->id])
            ->and(AuditLog::query()->where('company_id', $company->id)->count())->toBe($before);
    } finally {
        config(['database.default' => 'mysql']);
        app(CurrentCompany::class)->set($company);
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_mapped_state_competitor');
    }
});

test('current option reads hold validity until commit and reject subsequent approval withdrawal', function () {
    [$actor, $company, $product, $definition, $first, , $competitor] = mappedStateFixture('select');
    $action = app(SyncProductAttributeValuesAction::class);
    $action->execute($actor, $company, $product, [$definition->uuid => $first->id]);
    $primary = DB::connection();
    $primary->beginTransaction();
    try {
        $action->merge($actor, $company, $product, []);
        expect(fn () => $competitor->table('attribute_options')->where('id', $first->id)->update(['approval_status' => 'rejected']))
            ->toThrow(QueryException::class, 'Lock wait timeout');
        $primary->commit();
        expect($competitor->table('attribute_options')->where('id', $first->id)->update(['approval_status' => 'rejected']))->toBe(1);
        $primary->beginTransaction();
        try {
            expect(fn () => $action->merge($actor, $company, $product, []))->toThrow(AttributeOperationException::class);
        } finally {
            $primary->rollBack();
        }
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_mapped_state_competitor');
    }
});

test('attribute no-op returns current parent values definition and option despite an older snapshot', function (string $target) {
    [$actor, $company, $product, $definition, $first, $second, $competitor] = mappedStateFixture('select');
    $variant = $product->defaultVariant;
    $sync = function (User $editor, Company $tenant, $entry, $child, int $option) use ($target, $definition) {
        $payload = [$definition->uuid => $option];

        return $target === 'product'
            ? app(SyncProductAttributeValuesAction::class)->execute($editor, $tenant, $entry, $payload)
            : app(SyncVariantAttributeValuesAction::class)->execute($editor, $tenant, $entry, $child, $payload);
    };
    $sync($actor, $company, $product, $variant, $first->id);
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        DB::table('attribute_options')->where('id', $second->id)->first();
        config(['database.default' => 'mysql_mapped_state_competitor']);
        try {
            $tenant = Company::findOrFail($company->id);
            app(CurrentCompany::class)->set($tenant);
            $entry = $tenant->products()->findOrFail($product->id);
            $sync(User::findOrFail($actor->id), $tenant, $entry, $entry->defaultVariant, $second->id);
            DB::table($target === 'product' ? 'products' : 'product_variants')->where('id', $target === 'product' ? $product->id : $variant->id)->update(['name' => 'Current parent']);
            DB::table('attribute_definitions')->where('id', $definition->id)->update(['name' => 'Current definition']);
            DB::table('attribute_options')->where('id', $second->id)->update(['label' => 'Current option']);
        } finally {
            config(['database.default' => 'mysql']);
            app(CurrentCompany::class)->set($company);
        }
        $before = $competitor->table('activity_log')->where('company_id', $company->id)->count();
        $result = $sync($actor, $company, $product, $variant, $second->id);
        expect($result->name)->toBe('Current parent');
        $value = $result->attributeValues->firstWhere('attribute_definition_id', $definition->id);
        expect($value->value_option_id)->toBe($second->id)
            ->and($value->definition->name)->toBe('Current definition')
            ->and($value->selectedOption->label)->toBe('Current option');
        $primary->commit();
        expect(AuditLog::query()->where('company_id', $company->id)->count())->toBe($before);
    } finally {
        config(['database.default' => 'mysql']);
        app(CurrentCompany::class)->set($company);
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_mapped_state_competitor');
    }
})->with(['product', 'variant']);
