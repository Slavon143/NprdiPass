<?php

use App\Enums\ApiTokenAbility;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

function seedAttrDef($company, $user, string $name, string $code, string $type = 'text', string $scope = 'product', bool $required = false): AttributeDefinition
{
    $def = new AttributeDefinition;
    $def->forceFill([
        'company_id' => $company->getKey(),
        'name' => $name,
        'code' => $code,
        'type' => $type,
        'scope' => $scope,
        'required' => $required,
        'filterable' => false,
        'searchable' => false,
        'sort_order' => 0,
        'status' => 'active',
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ])->save();

    return $def->refresh();
}

function seedAttrProduct($company, $user, string $name, string $status = 'draft'): Product
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
        'status' => $status,
        'sort_order' => 0,
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ])->save();

    $product->default_variant_id = $variant->getKey();
    $product->save();

    return $product->refresh();
}

test('can read product attributes', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedAttrProduct($company, $user, 'Attr Product');
    seedAttrDef($company, $user, 'Material', 'material', 'text', 'product');

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}/attributes");
    expect($res->status())->toBe(200);
    expect($res->json('data'))->toBeArray();
});

test('can sync product text attribute', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedAttrProduct($company, $user, 'Text Product');
    $def = seedAttrDef($company, $user, 'Material', 'material', 'text', 'product');

    $res = apiPut($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/attributes", [
        'attributes' => [
            $def->uuid => 'Cotton',
        ],
    ]);
    expect($res->status())->toBe(200);
});

test('can sync product integer attribute', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedAttrProduct($company, $user, 'Int Product');
    $def = seedAttrDef($company, $user, 'Weight', 'weight', 'integer', 'product');

    $res = apiPut($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/attributes", [
        'attributes' => [
            $def->uuid => 42,
        ],
    ]);
    expect($res->status())->toBe(200);
});

test('can sync product decimal attribute', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedAttrProduct($company, $user, 'Decimal Product');
    $def = seedAttrDef($company, $user, 'Width', 'width', 'decimal', 'product');

    $res = apiPut($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/attributes", [
        'attributes' => [
            $def->uuid => '12.5000',
        ],
    ]);
    expect($res->status())->toBe(200);
});

test('can sync product boolean attribute', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedAttrProduct($company, $user, 'Bool Product');
    $def = seedAttrDef($company, $user, 'Is Recyclable', 'is_recyclable', 'boolean', 'product');

    $res = apiPut($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/attributes", [
        'attributes' => [
            $def->uuid => true,
        ],
    ]);
    expect($res->status())->toBe(200);
});

test('can sync product date attribute', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedAttrProduct($company, $user, 'Date Product');
    $def = seedAttrDef($company, $user, 'Release Date', 'release_date', 'date', 'product');

    $res = apiPut($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/attributes", [
        'attributes' => [
            $def->uuid => '2026-07-15',
        ],
    ]);
    expect($res->status())->toBe(200);
});

test('can sync product select attribute', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedAttrProduct($company, $user, 'Select Product');
    $def = seedAttrDef($company, $user, 'Material Select', 'mat_sel', 'select', 'product');

    $opt = new AttributeOption;
    $opt->forceFill([
        'company_id' => $company->getKey(),
        'attribute_definition_id' => $def->getKey(),
        'label' => 'Steel',
        'code' => 'steel',
        'sort_order' => 0,
        'status' => 'active',
    ])->save();

    $res = apiPut($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/attributes", [
        'attributes' => [
            $def->uuid => $opt->id,
        ],
    ]);
    expect($res->status())->toBe(200);
});

test('can clear product attribute', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedAttrProduct($company, $user, 'Clear Product');
    $def = seedAttrDef($company, $user, 'Note', 'note', 'text', 'product');

    // Set first
    apiPut($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/attributes", [
        'attributes' => [$def->uuid => 'Something'],
    ]);

    // Clear
    $res = apiPut($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/attributes", [
        'attributes' => [$def->uuid => null],
    ]);
    expect($res->status())->toBe(200);
});

test('scope enforcement prevents variant attribute on product', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedAttrProduct($company, $user, 'Scope Product');
    $def = seedAttrDef($company, $user, 'Variant Only', 'var_only', 'text', 'variant');

    $res = apiPut($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}/attributes", [
        'attributes' => [$def->uuid => 'Should Fail'],
    ]);
    expect($res->status())->toBeIn([409, 422]);
});

test('attribute sync requires catalog.write ability', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedAttrProduct($company, $user, 'No Write');

    test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->putJson(apiUrl("products/{$product->uuid}/attributes"), ['attributes' => []])
        ->assertStatus(403);
});
