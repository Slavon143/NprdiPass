<?php

use App\Enums\ApiTokenAbility;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

test('product show includes default_variant when loaded', function () {
    [$user, $company] = apiCatalogContext();

    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'name' => 'Include Test',
        'slug' => 'include-test',
        'slug_normalized' => 'include-test',
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

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}");

    expect($res->status())->toBe(200);
    expect($res->json('data.default_variant'))->not->toBeNull();
    expect($res->json('data.default_variant.uuid'))->toBe($variant->uuid);
});

test('product listing includes variant_count', function () {
    [$user, $company] = apiCatalogContext();

    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'name' => 'Count Test',
        'slug' => 'count-test',
        'slug_normalized' => 'count-test',
        'status' => 'draft',
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ])->save();

    $v1 = new ProductVariant;
    $v1->forceFill([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'name' => 'V1',
        'status' => 'draft',
        'sort_order' => 0,
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ])->save();

    $v2 = new ProductVariant;
    $v2->forceFill([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'name' => 'V2',
        'status' => 'draft',
        'sort_order' => 10,
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ])->save();

    $product->default_variant_id = $v1->getKey();
    $product->save();

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products');
    $data = collect($res->json('data'))->firstWhere('name', 'Count Test');
    expect($data)->not->toBeNull();
    expect($data['variant_count'])->toBe(2);
});

test('product show does not expose deleted_at', function () {
    [$user, $company] = apiCatalogContext();

    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'name' => 'No Deleted',
        'slug' => 'no-deleted',
        'slug_normalized' => 'no-deleted',
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

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}");
    $data = $res->json('data');

    expect($data)->not->toHaveKey('deleted_at');
    expect($data)->not->toHaveKey('company_id');
    expect($data)->not->toHaveKey('created_by');
    expect($data)->not->toHaveKey('updated_by');
    expect($data)->not->toHaveKey('slug_normalized');
});

test('product show has primary_category when loaded', function () {
    [$user, $company] = apiCatalogContext();

    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'name' => 'Cat Include',
        'slug' => 'cat-include',
        'slug_normalized' => 'cat-include',
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

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}");
    expect($res->status())->toBe(200);
    // primary_category may be null when not assigned, check it's present
    expect($res->json('data'))->toHaveKey('primary_category');
});
