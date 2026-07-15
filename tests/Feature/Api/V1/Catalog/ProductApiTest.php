<?php

use App\Enums\ApiTokenAbility;
use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

function seedApiProduct(Company $company, User $actor, string $name = 'Test Product', string $status = 'draft'): Product
{
    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'name' => $name,
        'slug' => str($name)->slug()->toString(),
        'slug_normalized' => str($name)->slug()->lower()->toString(),
        'status' => $status,
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ])->save();

    $variant = new ProductVariant;
    $variant->forceFill([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'name' => 'Default',
        'status' => $status,
        'sort_order' => 0,
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ])->save();

    $product->default_variant_id = $variant->getKey();
    $product->save();

    return $product->refresh()->load('defaultVariant');
}

test('can list products', function () {
    [$user, $company] = apiCatalogContext();
    seedApiProduct($company, $user, 'List Product');

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products');
    expect($res->status())->toBe(200);
    expect($res->json('meta.current_page'))->toBe(1);
    expect(count($res->json('data')))->toBeGreaterThanOrEqual(1);
});

test('product listing response has correct structure', function () {
    [$user, $company] = apiCatalogContext();
    seedApiProduct($company, $user, 'Structure Product');

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products');
    $res->assertJsonStructure([
        'data' => [
            ['uuid', 'name', 'slug', 'status', 'variant_count'],
        ],
        'meta' => ['current_page', 'per_page', 'total', 'last_page'],
    ]);
});

test('can create product', function () {
    [$user, $company] = apiCatalogContext();

    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'products', [
        'name' => 'New Product',
        'slug' => 'new-product',
    ]);
    expect($res->status())->toBe(201);
    expect($res->json('data.name'))->toBe('New Product');
    expect($res->json('data.status'))->toBe('draft');
});

test('product create automatically creates default variant', function () {
    [$user, $company] = apiCatalogContext();

    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'products', [
        'name' => 'Auto Variant',
    ]);
    expect($res->status())->toBe(201);
    expect($res->json('data.default_variant'))->not->toBeNull();
    expect($res->json('data.variant_count'))->toBe(1);
});

test('can create product with categories', function () {
    [$user, $company] = apiCatalogContext();

    $cat1 = seedApiCategory($company, $user, 'Primary Cat');
    $cat2 = seedApiCategory($company, $user, 'Extra Cat');

    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'products', [
        'name' => 'Categorized Product',
        'primary_category_uuid' => $cat1->uuid,
        'category_uuids' => [$cat2->uuid],
    ]);
    expect($res->status())->toBe(201);
});

test('can show product', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedApiProduct($company, $user, 'Show Product');

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}");
    expect($res->status())->toBe(200);
    expect($res->json('data.uuid'))->toBe($product->uuid);
    expect($res->json('data.name'))->toBe('Show Product');
    expect($res->json('data.status'))->toBe(ProductStatus::Draft->value);
});

test('product show returns correct fields', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedApiProduct($company, $user, 'Fields Product');

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}");
    $data = $res->json('data');

    expect($data)->toHaveKeys([
        'uuid', 'name', 'slug', 'short_description', 'description',
        'brand', 'manufacturer', 'status', 'published_at',
        'variant_count', 'created_at', 'updated_at',
    ]);
    expect($data['uuid'])->toBeString();
    expect($data)->not->toHaveKey('company_id');
    expect($data)->not->toHaveKey('created_by');
    expect($data)->not->toHaveKey('deleted_at');
});

test('can update product', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedApiProduct($company, $user, 'Old Name');

    $res = apiPatch($user, $company, [ApiTokenAbility::CatalogWrite->value], "products/{$product->uuid}", [
        'name' => 'New Name',
        'brand' => 'TestBrand',
    ]);
    expect($res->status())->toBe(200);
    expect($res->json('data.name'))->toBe('New Name');
    expect($res->json('data.brand'))->toBe('TestBrand');
});

test('duplicate slug returns conflict', function () {
    [$user, $company] = apiCatalogContext();
    seedApiProduct($company, $user, 'First Product');

    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'products', [
        'name' => 'First Product',
        'slug' => 'first-product',
    ]);
    expect($res->status())->toBeIn([409, 422]);
});

test('wrong tenant category in product create returns 404', function () {
    [$user, $company] = apiCatalogContext();
    $otherUser = User::factory()->create(['email_verified_at' => now()]);
    $otherCompany = Company::factory()->create();
    $foreignCat = seedApiCategory($otherCompany, $otherUser, 'Foreign Cat');

    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'products', [
        'name' => 'Bad Category Product',
        'primary_category_uuid' => $foreignCat->uuid,
    ]);
    expect($res->status())->toBeIn([404, 422]);
});

test('product store validation fails with empty name', function () {
    [$user, $company] = apiCatalogContext();

    apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'products', [
        'name' => '',
    ])->assertStatus(422);
});

test('non existent product returns 404', function () {
    [$user, $company] = apiCatalogContext();

    apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products/550e8400-e29b-41d4-a716-446655440000')
        ->assertNotFound();
});

function seedApiCategory(Company $company, User $actor, string $name = 'Test Category', string $status = 'active'): Category
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
        'status' => $status,
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ])->save();

    return $cat->refresh();
}
