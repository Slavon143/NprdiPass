<?php

use App\Enums\ApiTokenAbility;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

function seedVarAttrProduct($company, $user, string $suffix = ''): array
{
    $name = 'Var Attr Product'.$suffix;
    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'name' => $name,
        'slug' => str($name)->slug()->toString(),
        'slug_normalized' => str($name)->slug()->lower()->toString(),
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

    return [$product->refresh(), $variant->refresh()];
}

function seedVarAttrDef($company, $user, string $name, string $code, string $type = 'text', string $scope = 'variant'): AttributeDefinition
{
    $def = new AttributeDefinition;
    $def->forceFill([
        'company_id' => $company->getKey(),
        'name' => $name,
        'code' => $code,
        'type' => $type,
        'scope' => $scope,
        'required' => false,
        'filterable' => false,
        'searchable' => false,
        'sort_order' => 0,
        'status' => 'active',
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ])->save();

    return $def->refresh();
}

test('can read variant attributes', function () {
    [$user, $company] = apiCatalogContext();
    [$product, $variant] = seedVarAttrProduct($company, $user);
    seedVarAttrDef($company, $user, 'Size', 'size', 'text', 'variant');

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}/variants/{$variant->uuid}/attributes");
    expect($res->status())->toBe(200);
    expect($res->json('data'))->toBeArray();
});

test('can sync variant text attribute', function () {
    [$user, $company] = apiCatalogContext();
    [$product, $variant] = seedVarAttrProduct($company, $user);
    $def = seedVarAttrDef($company, $user, 'Size', 'size', 'text', 'variant');

    $res = apiPut($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/variants/{$variant->uuid}/attributes", [
        'attributes' => [
            $def->uuid => 'Large',
        ],
    ]);
    expect($res->status())->toBe(200);
});

test('scope enforcement prevents product definition on variant', function () {
    [$user, $company] = apiCatalogContext();
    [$product, $variant] = seedVarAttrProduct($company, $user);
    $def = seedVarAttrDef($company, $user, 'Product Only Def', 'prod_only', 'text', 'product');

    $res = apiPut($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/variants/{$variant->uuid}/attributes", [
        'attributes' => [$def->uuid => 'Should Fail'],
    ]);
    expect($res->status())->toBeIn([409, 422]);
});

test('variant attributes require correct variant context', function () {
    [$user, $company] = apiCatalogContext();
    [$product1, $variant1] = seedVarAttrProduct($company, $user, ' 1');
    [$product2, $variant2] = seedVarAttrProduct($company, $user, ' 2');
    $variant2->forceFill(['product_id' => $product2->getKey()])->save();

    apiPut($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product1->uuid}/variants/{$variant2->uuid}/attributes", [
        'attributes' => [],
    ])->assertNotFound();
});
