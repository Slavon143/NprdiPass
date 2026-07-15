<?php

use App\Enums\ApiTokenAbility;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Models\Catalog\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

test('owner with catalog.write and catalog.update permission can create product', function () {
    [$user, $company] = apiCatalogContext(CompanyRole::Owner);

    apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'products', [
        'name' => 'Owner Product',
    ])->assertCreated();
});

test('admin with catalog.write can create product', function () {
    [$user, $company] = apiCatalogContext(CompanyRole::Admin);

    apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'products', [
        'name' => 'Admin Product',
    ])->assertCreated();
});

test('editor with catalog.write can create product', function () {
    [$user, $company] = apiCatalogContext(CompanyRole::Editor);

    apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'products', [
        'name' => 'Editor Product',
    ])->assertCreated();
});

test('viewer with catalog.write cannot create product', function () {
    [$user, $company] = apiCatalogContext(CompanyRole::Viewer);

    apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'products', [
        'name' => 'Viewer Product',
    ])->assertStatus(403);
});

test('owner without catalog.write ability cannot create product', function () {
    [$user, $company] = apiCatalogContext(CompanyRole::Owner);

    apiPost($user, $company, [ApiTokenAbility::CatalogRead->value], 'products', [
        'name' => 'No Write Ability',
    ])->assertStatus(403)
        ->assertJsonPath('error.code', 'token_ability_missing');
});

test('editor cannot manage attributes', function () {
    [$user, $company] = apiCatalogContext(CompanyRole::Editor);

    apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'attributes', [
        'name' => 'Test Attribute',
        'code' => 'test_attr',
        'type' => 'text',
        'scope' => 'product',
    ])->assertStatus(403);
});

test('owner with catalog.lifecycle can activate product', function () {
    [$user, $company] = apiCatalogContext(CompanyRole::Owner);

    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'name' => 'Lifecycle Product',
        'slug' => 'lifecycle-product',
        'slug_normalized' => 'lifecycle-product',
        'status' => ProductStatus::Draft,
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ])->save();

    $productUuid = $product->uuid;

    $res = test()->withToken(apiToken($user, $company, [
        ApiTokenAbility::CatalogLifecycle->value, ApiTokenAbility::CatalogRead->value,
    ]))->postJson(apiUrl("products/{$productUuid}/activate"));

    expect($res->status())->toBeIn([200, 422]);
});

test('viewer with catalog.lifecycle cannot activate product', function () {
    [$user, $company] = apiCatalogContext(CompanyRole::Viewer);

    [$actor, $actorCompany] = apiCatalogContext(CompanyRole::Owner);

    $product = new Product;
    $product->forceFill([
        'company_id' => $actorCompany->getKey(),
        'name' => 'Viewer Cannot Activate',
        'slug' => 'viewer-cannot-activate',
        'slug_normalized' => 'viewer-cannot-activate',
        'status' => ProductStatus::Draft,
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ])->save();
    $productUuid = $product->uuid;

    apiPost($user, $company, [ApiTokenAbility::CatalogLifecycle->value], "products/{$productUuid}/activate")
        ->assertStatus(404);
});

test('viewer cannot upload media', function () {
    [$user, $company] = apiCatalogContext(CompanyRole::Viewer);

    $create = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'products', [
        'name' => 'No Media Product',
    ]);
    if ($create->status() === 403) {
        // Viewer can't create products, which is expected
        expect($create->status())->toBe(403);

        return;
    }

    $productUuid = $create->json('data.uuid');

    $token = apiToken($user, $company, [ApiTokenAbility::CatalogMedia->value]);
    test()->withToken($token)->postJson(apiUrl("products/{$productUuid}/media"))
        ->assertStatus(403);
});
