<?php

use App\Actions\Catalog\CreateProductWithDefaultVariantAction;
use App\Actions\Catalog\Lifecycle\ArchiveProductAction;
use App\Actions\Catalog\Lifecycle\RestoreProductAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Products\UpdateProductAction;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\Catalog\ProductStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\CatalogProductCapacity;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function capacityContext(int $limit = 1): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $company]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    CompanySubscription::query()->create([
        'company_id' => $company->id,
        'plan_key' => 'growth',
        'status' => BillingSubscriptionStatus::Active,
        'entitlements' => ['catalog.products.max' => $limit],
    ]);

    return [$actor, $company];
}

function capacityProduct(User $actor, Company $company, string $slug): Product
{
    return app(CreateProductAction::class)->execute($actor, $company, [
        'name' => $slug,
        'slug' => $slug,
    ]);
}

test('archived and soft deleted products do not consume catalog slots', function () {
    [$actor, $company] = capacityContext();
    $capacity = app(CatalogProductCapacity::class);
    $first = capacityProduct($actor, $company, 'first');

    expect($capacity->count($company))->toBe(1);
    app(ArchiveProductAction::class)->execute($actor, $company, $first);
    expect($capacity->count($company))->toBe(0);

    $second = capacityProduct($actor, $company, 'second');
    app(RestoreProductAction::class)->execute($actor, $company, $second);
    expect($capacity->count($company))->toBe(1)
        ->and($first->fresh()->status)->toBe(ProductStatus::Archived);

    $second->delete();
    expect($capacity->count($company))->toBe(0);
    capacityProduct($actor, $company, 'third');
    expect($capacity->count($company))->toBe(1);
});

test('restore rechecks capacity and does not change archived product when full', function () {
    [$actor, $company] = capacityContext();
    $archived = capacityProduct($actor, $company, 'archived');
    app(ArchiveProductAction::class)->execute($actor, $company, $archived);
    capacityProduct($actor, $company, 'replacement');

    expect(fn () => app(RestoreProductAction::class)->execute($actor, $company, $archived))
        ->toThrow(ValidationException::class, 'catalog.products.max');
    expect($archived->fresh()->status)->toBe(ProductStatus::Archived)
        ->and(app(CatalogProductCapacity::class)->count($company))->toBe(1);
});

test('both create actions acquire the same catalog slot and editing is unrestricted', function () {
    [$actor, $company] = capacityContext();
    $product = app(CreateProductWithDefaultVariantAction::class)->execute(
        $actor,
        $company,
        ['name' => 'First', 'slug' => 'first'],
        ['name' => 'Default'],
    );

    expect($product->status)->toBe(ProductStatus::Draft)
        ->and(app(CatalogProductCapacity::class)->count($company))->toBe(1);
    expect(fn () => capacityProduct($actor, $company, 'second'))
        ->toThrow(ValidationException::class, 'catalog.products.max');
    expect(fn () => app(CreateProductWithDefaultVariantAction::class)->execute(
        $actor,
        $company,
        ['name' => 'Third', 'slug' => 'third'],
        ['name' => 'Default'],
    ))->toThrow(ValidationException::class, 'catalog.products.max');

    $updated = app(UpdateProductAction::class)->execute($actor, $company, $product, ['name' => 'Edited']);
    expect($updated->name)->toBe('Edited');
});

test('dashboard sidebar and billing show the same non-archived count', function () {
    config()->set('features.billing', true);
    [$actor, $company] = capacityContext();
    $product = capacityProduct($actor, $company, 'archivable');
    app(ArchiveProductAction::class)->execute($actor, $company, $product);

    $this->actingAs($actor)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('0 / 1 products')
        ->assertSee('Drafts count. Archived and deleted products do not.');
    $this->get(route('settings.billing.index'))
        ->assertOk()
        ->assertSee('Drafts count. Archived and deleted products do not.');
});

test('unlimited product capacity avoids scanning the catalogue', function () {
    [$actor, $company] = capacityContext();
    $company->subscription()->firstOrFail()->update(['entitlements' => ['catalog.products.max' => null]]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    DB::transaction(fn () => app(CatalogProductCapacity::class)->assertSlotAvailable($company));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(collect($queries)->contains(fn ($query) => str_contains($query['query'], '`products`')))->toBeFalse();
});

test('rolled back creates leave the last slot available to the same action instance', function () {
    [$actor, $company] = capacityContext();
    $action = app(CreateProductAction::class);
    $capacity = app(CatalogProductCapacity::class);

    expect(fn () => DB::transaction(function () use ($action, $actor, $company): void {
        $action->execute($actor, $company, ['name' => 'Rolled back', 'slug' => 'rolled-back']);
        throw new RuntimeException('Rollback the aggregate');
    }))->toThrow(RuntimeException::class, 'Rollback the aggregate');

    expect($capacity->count($company))->toBe(0)
        ->and($company->productVariants()->count())->toBe(0);
    $product = $action->execute($actor, $company, ['name' => 'Replacement', 'slug' => 'replacement']);
    expect($capacity->count($company))->toBe(1)
        ->and($product->defaultVariant?->product_id)->toBe($product->id);
    expect(fn () => $action->execute($actor, $company, ['name' => 'Excess', 'slug' => 'excess']))
        ->toThrow(ValidationException::class, 'catalog.products.max');
});
