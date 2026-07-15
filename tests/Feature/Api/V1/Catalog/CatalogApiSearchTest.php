<?php

use App\Enums\ApiTokenAbility;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

test('can search products by name keyword', function () {
    [$user, $company] = apiCatalogContext();

    $p1 = seedApProduct($company, $user, 'Work Gloves');
    $p2 = seedApProduct($company, $user, 'Safety Helmet');
    $p3 = seedApProduct($company, $user, 'Work Boots');

    $res = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products?q=gloves'));

    expect($res->status())->toBe(200);
    $names = collect($res->json('data'))->pluck('name');
    expect($names)->toContain('Work Gloves');
});

test('can search products by SKU', function () {
    [$user, $company] = apiCatalogContext();

    $product = seedApProduct($company, $user, 'SKU Product');

    $variant = new ProductVariant;
    $variant->forceFill([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'name' => 'Default',
        'sku' => 'UNIQUE-SKU-12345',
        'sku_normalized' => 'UNIQUE-SKU-12345',
        'status' => 'draft',
        'sort_order' => 0,
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ])->save();
    $product->default_variant_id = $variant->getKey();
    $product->save();

    $res = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products?q=UNIQUE-SKU-12345'));

    expect($res->status())->toBe(200);
});

test('can filter products by status', function () {
    [$user, $company] = apiCatalogContext();

    seedApProduct($company, $user, 'Draft Only', 'draft');
    seedApProduct($company, $user, 'Active Only', 'active');

    $res = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products?product_statuses[]=draft'));

    expect($res->status())->toBe(200);
    $statuses = collect($res->json('data'))->pluck('status');
    foreach ($statuses as $status) {
        expect($status)->toBe('draft');
    }
});

test('products support pagination', function () {
    [$user, $company] = apiCatalogContext();

    for ($i = 1; $i <= 5; $i++) {
        seedApProduct($company, $user, "Product {$i}");
    }

    $res = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products?per_page=1&page=1'));
    expect($res->status())->toBe(200);
    expect($res->json('meta.current_page'))->toBe(1);
    expect($res->json('meta.per_page'))->toBe(1);
    expect($res->json('meta.total'))->toBeGreaterThanOrEqual(5);
    expect($res->json('meta.links.first'))->toBeString();
    expect($res->json('meta.links.last'))->toBeString();
});

test('products default sort is stable', function () {
    [$user, $company] = apiCatalogContext();

    seedApProduct($company, $user, 'Product A');
    seedApProduct($company, $user, 'Product B');

    $first = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products?sort=name&direction=asc'));
    $second = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products?sort=name&direction=asc'));

    $firstUuids = collect($first->json('data'))->pluck('uuid')->toArray();
    $secondUuids = collect($second->json('data'))->pluck('uuid')->toArray();
    expect($firstUuids)->toBe($secondUuids);
});

test('per_page limited to 100', function () {
    [$user, $company] = apiCatalogContext();

    $res = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products?per_page=1000'));

    expect($res->status())->toBe(200);
    expect($res->json('meta.per_page'))->toBeLessThanOrEqual(100);
});

test('brand filter returns matching products', function () {
    [$user, $company] = apiCatalogContext();

    $p1 = seedApProduct($company, $user, 'Brand X Product');
    $p1->forceFill(['brand' => 'BrandX'])->save();
    $p2 = seedApProduct($company, $user, 'Other Product');

    $res = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products?brand=BrandX'));

    expect($res->status())->toBe(200);
    $names = collect($res->json('data'))->pluck('name');
    expect($names)->toContain('Brand X Product');
});

test('search response uses product summary structure', function () {
    [$user, $company] = apiCatalogContext();
    seedApProduct($company, $user, 'Structure Check');

    $res = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products'));

    $res->assertJsonStructure([
        'data' => [
            ['uuid', 'name', 'slug', 'status', 'variant_count', 'created_at', 'updated_at'],
        ],
        'meta' => ['current_page', 'per_page', 'total', 'last_page', 'links'],
    ]);
});

function seedApProduct($company, $user, string $name, string $status = 'draft'): Product
{
    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'name' => $name,
        'slug' => str($name)->slug()->toString(),
        'slug_normalized' => str($name)->slug()->lower()->toString(),
        'status' => $status,
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ])->save();

    $variant = new ProductVariant;
    $variant->forceFill([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'name' => 'Default',
        'status' => $status === 'active' ? 'active' : 'draft',
        'sort_order' => 0,
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ])->save();

    $product->default_variant_id = $variant->getKey();
    $product->save();

    return $product->refresh();
}
