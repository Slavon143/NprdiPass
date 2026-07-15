<?php

use App\Enums\ApiTokenAbility;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

function seedSerialProduct($company, $user): Product
{
    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'name' => 'Serial Product',
        'slug' => 'serial-product',
        'slug_normalized' => 'serial-product',
        'status' => 'draft',
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ])->save();

    $variant = new ProductVariant;
    $variant->forceFill([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'name' => 'Default',
        'status' => 'draft',
        'sort_order' => 0,
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ])->save();

    $product->default_variant_id = $variant->getKey();
    $product->save();

    return $product->refresh();
}

test('product resource uses UUID not numeric id', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedSerialProduct($company, $user);

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}");
    $data = $res->json('data');

    expect($data)->not->toHaveKey('id');
    expect($data['uuid'])->toBe($product->uuid);
    expect($data['uuid'])->toMatch('/^[0-9a-f-]{36}$/');
});

test('status is serialized as string enum value', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedSerialProduct($company, $user);

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}");
    expect($res->json('data.status'))->toBe('draft');
    expect(is_string($res->json('data.status')))->toBeTrue();
});

test('dates are serialized as ISO 8601', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedSerialProduct($company, $user);

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}");

    $createdAt = $res->json('data.created_at');
    $updatedAt = $res->json('data.updated_at');

    expect($createdAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z$/');
    expect($updatedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z$/');
});

test('no company_id exposed in resources', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedSerialProduct($company, $user);

    // Test product
    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}");
    expect($res->json('data'))->not->toHaveKey('company_id');

    // Test product listing
    $list = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products');
    if (count($list->json('data')) > 0) {
        expect($list->json('data.0'))->not->toHaveKey('company_id');
    }

    // Test categories
    $catRes = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'categories');
    if (count($catRes->json('data')) > 0) {
        expect($catRes->json('data.0'))->not->toHaveKey('company_id');
    }
});

test('variant resource uses UUID', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedSerialProduct($company, $user);
    $variant = $product->defaultVariant;

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}/variants/{$variant->uuid}");
    expect($res->json('data.uuid'))->toBeString();
    expect($res->json('data'))->not->toHaveKey('id');
});

test('no normalized fields exposed', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedSerialProduct($company, $user);

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}");
    expect($res->json('data'))->not->toHaveKey('slug_normalized');

    $variantRes = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}/variants/{$product->defaultVariant->uuid}");
    expect($variantRes->json('data'))->not->toHaveKey('sku_normalized');
});

test('categories resource exposes parent_uuid as uuid', function () {
    [$user, $company] = apiCatalogContext();

    $parent = new Category;
    $parent->forceFill([
        'company_id' => $company->getKey(),
        'depth' => 0,
        'name' => 'Parent',
        'slug' => 'parent',
        'slug_normalized' => 'parent',
        'sort_order' => 10,
        'status' => 'active',
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ])->save();

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "categories/{$parent->uuid}");
    expect($res->json('data.parent_uuid'))->toBeNull();
});
