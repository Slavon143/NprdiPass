<?php

use App\Enums\ApiTokenAbility;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

function seedApiVariant(Company $company, User $actor, Product $product, array $fields = []): ProductVariant
{
    $data = array_merge([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'name' => $fields['name'] ?? 'Default',
        'sku' => $fields['sku'] ?? null,
        'gtin' => $fields['gtin'] ?? null,
        'mpn' => $fields['mpn'] ?? null,
        'status' => 'draft',
        'sort_order' => $fields['sort_order'] ?? 0,
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ], $fields);

    $variant = new ProductVariant;
    $variant->forceFill($data)->save();

    return $variant->refresh();
}

function seedApiProductWithVariant(Company $company, User $actor, string $name = 'Test Product'): array
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

    $variant = seedApiVariant($company, $actor, $product);

    $product->default_variant_id = $variant->getKey();
    $product->save();

    return [$product->refresh()->load('defaultVariant'), $variant];
}

test('can list variants for product', function () {
    [$user, $company] = apiCatalogContext();
    [$product] = seedApiProductWithVariant($company, $user);

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}/variants");
    expect($res->status())->toBe(200);
    expect(count($res->json('data')))->toBeGreaterThanOrEqual(1);
});

test('can create variant', function () {
    [$user, $company] = apiCatalogContext();
    [$product] = seedApiProductWithVariant($company, $user);

    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/variants", [
        'name' => 'Extra Variant',
        'sku' => 'EXTRA-SKU',
        'sort_order' => 10,
    ]);
    expect($res->status())->toBe(201);
    expect($res->json('data.name'))->toBe('Extra Variant');
    expect($res->json('data.sku'))->toBe('EXTRA-SKU');
});

test('can show variant', function () {
    [$user, $company] = apiCatalogContext();
    [$product, $variant] = seedApiProductWithVariant($company, $user);

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}/variants/{$variant->uuid}");
    expect($res->status())->toBe(200);
    expect($res->json('data.uuid'))->toBe($variant->uuid);
});

test('can update variant', function () {
    [$user, $company] = apiCatalogContext();
    [$product, $variant] = seedApiProductWithVariant($company, $user);

    $res = apiPatch($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/variants/{$variant->uuid}", [
        'name' => 'Updated Variant',
    ]);
    expect($res->status())->toBe(200);
    expect($res->json('data.name'))->toBe('Updated Variant');
});

test('can set default variant', function () {
    [$user, $company] = apiCatalogContext();
    [$product] = seedApiProductWithVariant($company, $user);
    $newVariant = seedApiVariant($company, $user, $product, ['name' => 'New Default']);

    $res = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogWrite->value]))
        ->postJson(apiUrl("products/{$product->uuid}/variants/{$newVariant->uuid}/set-default"));
    expect($res->status())->toBe(200);
});

test('set default to same variant is no-op', function () {
    [$user, $company] = apiCatalogContext();
    [$product, $variant] = seedApiProductWithVariant($company, $user);

    $res = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogWrite->value]))
        ->postJson(apiUrl("products/{$product->uuid}/variants/{$variant->uuid}/set-default"));
    expect($res->status())->toBe(200);
});

test('variant with valid GTIN can be created', function () {
    [$user, $company] = apiCatalogContext();
    [$product] = seedApiProductWithVariant($company, $user);

    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/variants", [
        'name' => 'GTIN Variant',
        'gtin' => '00012345678905',
    ]);
    expect($res->status())->toBe(201);
});

test('variant with invalid GTIN check digit returns 422', function () {
    [$user, $company] = apiCatalogContext();
    [$product] = seedApiProductWithVariant($company, $user);

    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/variants", [
        'name' => 'Bad GTIN',
        'gtin' => '00012345678900',
    ]);
    expect($res->status())->toBeIn([409, 422]);
});

test('duplicate SKU returns conflict', function () {
    [$user, $company] = apiCatalogContext();
    [$product] = seedApiProductWithVariant($company, $user);

    apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/variants", [
        'name' => 'First SKU',
        'sku' => 'DEMO-SKU-001',
    ]);

    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/variants", [
        'name' => 'Second SKU',
        'sku' => 'DEMO-SKU-001',
    ]);
    expect($res->status())->toBeIn([409, 422]);
});

test('wrong product variant returns 404', function () {
    [$user, $company] = apiCatalogContext();
    [$product1] = seedApiProductWithVariant($company, $user, 'Product 1');
    [$product2, $variant2] = seedApiProductWithVariant($company, $user, 'Product 2');

    apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product1->uuid}/variants/{$variant2->uuid}")
        ->assertNotFound();
});

test('variant resource does not expose internal fields', function () {
    [$user, $company] = apiCatalogContext();
    [$product, $variant] = seedApiProductWithVariant($company, $user);

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}/variants/{$variant->uuid}");
    $data = $res->json('data');

    expect($data)->toHaveKeys(['uuid', 'name', 'sku', 'gtin', 'mpn', 'status', 'sort_order']);
    expect($data)->not->toHaveKey('company_id');
    expect($data)->not->toHaveKey('sku_normalized');
    expect($data)->not->toHaveKey('created_by');
});
