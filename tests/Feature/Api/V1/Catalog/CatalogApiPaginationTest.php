<?php

use App\Enums\ApiTokenAbility;
use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

test('product index supports pagination metadata', function () {
    [$user, $company] = apiCatalogContext();

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products');

    expect($res->status())->toBe(200);
    expect($res->json('meta'))->toHaveKeys(['current_page', 'per_page', 'total', 'last_page', 'links']);
    expect($res->json('meta.links'))->toHaveKeys(['first', 'last']);
});

test('default per_page is 25 for products', function () {
    [$user, $company] = apiCatalogContext();

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products');
    expect($res->json('meta.per_page'))->toBe(25);
});

test('per_page 50 works', function () {
    [$user, $company] = apiCatalogContext();

    $res = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products?per_page=50'));
    expect($res->json('meta.per_page'))->toBe(50);
});

test('per_page 100 works', function () {
    [$user, $company] = apiCatalogContext();

    $res = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products?per_page=100'));
    expect($res->json('meta.per_page'))->toBe(100);
});

test('categories support pagination', function () {
    [$user, $company] = apiCatalogContext();

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'categories');
    expect($res->json('meta'))->toHaveKeys(['current_page', 'per_page', 'total', 'last_page']);
});

test('pagination total is accurate', function () {
    [$user, $company] = apiCatalogContext();

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products');
    $initialTotal = $res->json('meta.total');

    // Create a product directly in the database
    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'name' => 'New Count Product',
        'slug' => 'new-count-product',
        'slug_normalized' => 'new-count-product',
        'status' => ProductStatus::Draft,
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ])->save();

    $res2 = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products');
    expect($res2->json('meta.total'))->toBe($initialTotal + 1);
});

test('page 2 returns correct data when enough records', function () {
    [$user, $company] = apiCatalogContext();

    for ($i = 1; $i <= 3; $i++) {
        $product = new Product;
        $product->forceFill([
            'company_id' => $company->getKey(),
            'name' => "Page Product {$i}",
            'slug' => "page-product-{$i}",
            'slug_normalized' => "page-product-{$i}",
            'status' => ProductStatus::Draft,
            'created_by' => $user->getKey(),
            'updated_by' => $user->getKey(),
        ])->save();
    }

    $page1 = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products?per_page=1&page=1'));
    $page2 = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products?per_page=1&page=2'));

    expect($page1->status())->toBe(200);
    expect($page2->status())->toBe(200);
    expect($page1->json('meta.current_page'))->toBe(1);
    expect($page2->json('meta.current_page'))->toBe(2);
});

test('per_page out of range defaults gracefully', function () {
    [$user, $company] = apiCatalogContext();

    $res = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products?per_page=999999'));

    expect($res->status())->toBe(200);
    expect($res->json('meta.per_page'))->toBeLessThanOrEqual(100);
});
