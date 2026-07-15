<?php

use App\Enums\ApiTokenAbility;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

function seedProduct(Company $company, User $actor, string $name = 'Test Product'): Product
{
    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'name' => $name,
        'slug' => str($name)->slug()->toString(),
        'slug_normalized' => str($name)->slug()->lower()->toString(),
        'status' => 'draft',
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ])->save();

    $variant = new ProductVariant;
    $variant->forceFill([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'name' => 'Default',
        'status' => 'draft',
        'sort_order' => 0,
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ])->save();

    $product->default_variant_id = $variant->getKey();
    $product->save();

    return $product->refresh();
}

function seedCategory(Company $company, User $actor, string $name = 'Test Category'): Category
{
    $slug = str($name)->slug()->toString();
    $cat = new Category;
    $cat->forceFill([
        'company_id' => $company->getKey(),
        'depth' => 0,
        'name' => $name,
        'slug' => $slug,
        'slug_normalized' => $slug,
        'sort_order' => 10,
        'status' => 'active',
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ])->save();

    return $cat->refresh();
}

test('cannot read product from another company', function () {
    [$userA, $companyA] = apiCatalogContext();
    $userB = User::factory()->create(['email_verified_at' => now()]);
    $companyB = Company::factory()->create();
    $productB = seedProduct($companyB, $userB);

    apiGet($userA, $companyA, [ApiTokenAbility::CatalogRead->value], "products/{$productB->uuid}")
        ->assertNotFound();
});

test('cannot update product from another company', function () {
    [$userA, $companyA] = apiCatalogContext();
    $userB = User::factory()->create(['email_verified_at' => now()]);
    $companyB = Company::factory()->create();
    $productB = seedProduct($companyB, $userB);

    apiPatch($userA, $companyA, [ApiTokenAbility::CatalogWrite->value], "products/{$productB->uuid}", [
        'name' => 'Hacked',
    ])->assertNotFound();
});

test('cannot read variant from another company', function () {
    [$userA, $companyA] = apiCatalogContext();
    $userB = User::factory()->create(['email_verified_at' => now()]);
    $companyB = Company::factory()->create();
    $productB = seedProduct($companyB, $userB);
    $variantB = $productB->defaultVariant;

    apiGet($userA, $companyA, [ApiTokenAbility::CatalogRead->value], "products/{$productB->uuid}/variants/{$variantB->uuid}")
        ->assertNotFound();
});

test('cannot read category from another company', function () {
    [$userA, $companyA] = apiCatalogContext();
    $userB = User::factory()->create(['email_verified_at' => now()]);
    $companyB = Company::factory()->create();
    $catB = seedCategory($companyB, $userB);

    apiGet($userA, $companyA, [ApiTokenAbility::CatalogRead->value], "categories/{$catB->uuid}")
        ->assertNotFound();
});

test('cannot update category from another company', function () {
    [$userA, $companyA] = apiCatalogContext();
    $userB = User::factory()->create(['email_verified_at' => now()]);
    $companyB = Company::factory()->create();
    $catB = seedCategory($companyB, $userB);

    apiPatch($userA, $companyA, [ApiTokenAbility::CatalogWrite->value], "categories/{$catB->uuid}", [
        'name' => 'Hacked',
    ])->assertNotFound();
});

test('product from company A does not appear in company B listing', function () {
    [$userA, $companyA] = apiCatalogContext();
    $userB = User::factory()->create(['email_verified_at' => now()]);
    $companyB = Company::factory()->create();
    seedProduct($companyB, $userB, 'Foreign Product');

    $res = apiGet($userA, $companyA, [ApiTokenAbility::CatalogRead->value], 'products');
    $names = collect($res->json('data'))->pluck('name');

    expect($names)->not->toContain('Foreign Product');
});

test('category from company A does not appear in company B listing', function () {
    [$userA, $companyA] = apiCatalogContext();
    $userB = User::factory()->create(['email_verified_at' => now()]);
    $companyB = Company::factory()->create();
    seedCategory($companyB, $userB, 'Foreign Category');

    $res = apiGet($userA, $companyA, [ApiTokenAbility::CatalogRead->value], 'categories');
    $names = collect($res->json('data'))->pluck('name');

    expect($names)->not->toContain('Foreign Category');
});

test('variant from wrong product returns 404', function () {
    [$user, $company] = apiCatalogContext();
    $product1 = seedProduct($company, $user, 'Product 1');
    $product2 = seedProduct($company, $user, 'Product 2');
    $variant2 = $product2->defaultVariant;

    apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product1->uuid}/variants/{$variant2->uuid}")
        ->assertNotFound();
});
