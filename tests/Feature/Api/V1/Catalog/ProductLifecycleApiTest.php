<?php

use App\Enums\ApiTokenAbility;
use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

function seedLifecycleProduct($user, $company, string $name = 'Lifecycle Product', string $status = 'draft'): Product
{
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

    $product = $product->refresh()->load('defaultVariant');

    if ($status !== 'draft' && in_array($status, ['active', 'archived'])) {
        $product->forceFill(['status' => $status])->save();
        $product->defaultVariant->forceFill(['status' => $status === 'active' ? 'active' : 'draft'])->save();
    }

    return $product;
}

test('can read product readiness', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedLifecycleProduct($user, $company);

    $res = test()->withToken(apiLifecycleToken($user, $company))
        ->getJson(apiUrl("products/{$product->uuid}/readiness"));
    expect($res->status())->toBe(200);
    expect($res->json('data.ready'))->toBe(false);
    expect($res->json('data.blockers'))->toBeArray();
    expect($res->json('data.checked_at'))->toBeString();
});

test('readiness response has correct structure', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedLifecycleProduct($user, $company);

    $res = test()->withToken(apiLifecycleToken($user, $company))
        ->getJson(apiUrl("products/{$product->uuid}/readiness"));
    $res->assertJsonStructure([
        'data' => [
            'ready',
            'blockers',
            'warnings',
            'checked_at',
        ],
    ]);
});

test('can return product to draft', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedLifecycleProduct($user, $company, 'Active Product', 'active');

    $res = test()->withToken(apiLifecycleToken($user, $company))
        ->postJson(apiUrl("products/{$product->uuid}/return-to-draft"));
    expect($res->status())->toBe(200);
    expect($res->json('data.status'))->toBe(ProductStatus::Draft->value);
});

test('can archive draft product', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedLifecycleProduct($user, $company, 'Archive Me');

    $res = test()->withToken(apiLifecycleToken($user, $company))
        ->postJson(apiUrl("products/{$product->uuid}/archive"));
    expect($res->status())->toBe(200);
    expect($res->json('data.status'))->toBe(ProductStatus::Archived->value);
});

test('can restore archived product to draft', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedLifecycleProduct($user, $company, 'Restorable', 'archived');

    $res = test()->withToken(apiLifecycleToken($user, $company))
        ->postJson(apiUrl("products/{$product->uuid}/restore"));
    expect($res->status())->toBe(200);
    expect($res->json('data.status'))->toBe(ProductStatus::Draft->value);
});

test('blocked activation returns 422', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedLifecycleProduct($user, $company, 'Unready');

    $product->forceFill(['primary_category_id' => null, 'name' => ''])->save();

    $res = test()->withToken(apiLifecycleToken($user, $company))
        ->postJson(apiUrl("products/{$product->uuid}/activate"));
    expect($res->status())->toBe(422);
});

test('readiness requires catalog.read ability', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedLifecycleProduct($user, $company);

    test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogWrite->value]))
        ->getJson(apiUrl("products/{$product->uuid}/readiness"))
        ->assertStatus(403);
});

test('activate requires catalog.lifecycle ability', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedLifecycleProduct($user, $company);

    test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogWrite->value]))
        ->postJson(apiUrl("products/{$product->uuid}/activate"))
        ->assertStatus(403);
});
