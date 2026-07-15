<?php

use App\Enums\ApiTokenAbility;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

test('can archive non-default variant', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedApiLifecycleProduct($user, $company);
    $variant2 = seedApiExtraVariant($company, $user, $product, 'Extra');

    $res = test()->withToken(apiLifecycleToken($user, $company))
        ->postJson(apiUrl("products/{$product->uuid}/variants/{$variant2->uuid}/archive"));
    expect($res->status())->toBe(200);
});

test('cannot archive default variant', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedApiLifecycleProduct($user, $company);
    $defaultVariant = $product->defaultVariant;

    $res = test()->withToken(apiLifecycleToken($user, $company))
        ->postJson(apiUrl("products/{$product->uuid}/variants/{$defaultVariant->uuid}/archive"));
    expect($res->status())->toBeIn([409, 422]);
});

test('cannot archive last available variant', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedApiLifecycleProduct($user, $company);

    $res = test()->withToken(apiLifecycleToken($user, $company))
        ->postJson(apiUrl("products/{$product->uuid}/variants/{$product->defaultVariant->uuid}/archive"));
    expect($res->status())->toBeIn([409, 422]);
});

test('can restore archived variant', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedApiLifecycleProduct($user, $company);
    $variant2 = seedApiExtraVariant($company, $user, $product, 'Archivable');
    $variant2->forceFill(['status' => 'archived'])->save();

    $res = test()->withToken(apiLifecycleToken($user, $company))
        ->postJson(apiUrl("products/{$product->uuid}/variants/{$variant2->uuid}/restore"));
    expect($res->status())->toBe(200);
});

test('variant archive requires catalog.lifecycle ability', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedApiLifecycleProduct($user, $company);
    $variant2 = seedApiExtraVariant($company, $user, $product, 'Bad Ability');

    test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->postJson(apiUrl("products/{$product->uuid}/variants/{$variant2->uuid}/archive"))
        ->assertStatus(403);
});

function seedApiLifecycleProduct($user, $company, string $name = 'Lifecycle Product'): Product
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

    return $product->refresh()->load('defaultVariant');
}

function seedApiExtraVariant($company, $user, Product $product, string $name = 'Extra'): ProductVariant
{
    $variant = new ProductVariant;
    $variant->forceFill([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'name' => $name,
        'status' => 'draft',
        'sort_order' => 10,
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ])->save();

    return $variant->refresh();
}
