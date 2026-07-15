<?php

use App\Enums\ApiTokenAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

test('catalog:read allows GET products', function () {
    [$user, $company] = apiCatalogContext();
    apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products')->assertOk();
});

test('catalog:read allows GET categories', function () {
    [$user, $company] = apiCatalogContext();
    apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'categories')->assertOk();
});

test('catalog:read allows GET attributes', function () {
    [$user, $company] = apiCatalogContext();
    apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'attributes')->assertOk();
});

test('catalog:write allows POST products', function () {
    [$user, $company] = apiCatalogContext();
    apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'products', [
        'name' => 'Test Product',
    ])->assertCreated();
});

test('catalog:read cannot POST products', function () {
    [$user, $company] = apiCatalogContext();
    apiPost($user, $company, [ApiTokenAbility::CatalogRead->value], 'products', [
        'name' => 'Test Product',
    ])->assertStatus(403)
        ->assertJsonPath('error.code', 'token_ability_missing');
});

test('catalog:read cannot write categories', function () {
    [$user, $company] = apiCatalogContext();
    apiPost($user, $company, [ApiTokenAbility::CatalogRead->value], 'categories', [
        'name' => 'Test', 'sort_order' => 0,
    ])->assertStatus(403);
});

test('catalog:write cannot activate products', function () {
    [$user, $company] = apiCatalogContext();
    $create = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value, ApiTokenAbility::CatalogRead->value], 'products', ['name' => 'LP']);
    $productUuid = $create->json('data.uuid') ?? $create->json('data.id');
    expect($productUuid)->not->toBeNull();

    $token = apiToken($user, $company, [ApiTokenAbility::CatalogWrite->value, ApiTokenAbility::CatalogRead->value]);
    $res = test()->withToken($token)->postJson(apiUrl("products/{$productUuid}/activate"));
    expect($res->status())->toBe(403);
});

test('catalog:write cannot upload media', function () {
    [$user, $company] = apiCatalogContext();
    $create = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value, ApiTokenAbility::CatalogRead->value], 'products', ['name' => 'MP']);
    $productUuid = $create->json('data.uuid') ?? $create->json('data.id');
    expect($productUuid)->not->toBeNull();

    $token = apiToken($user, $company, [ApiTokenAbility::CatalogWrite->value, ApiTokenAbility::CatalogRead->value]);
    $res = test()->withToken($token)->postJson(apiUrl("products/{$productUuid}/media"));
    expect($res->status())->toBe(403);
});

test('missing ability returns correct error format', function () {
    [$user, $company] = apiCatalogContext();
    $res = apiPost($user, $company, [ApiTokenAbility::CatalogRead->value], 'categories', [
        'name' => 'Test', 'sort_order' => 0,
    ]);
    expect($res->status())->toBe(403);
    expect($res->json('error.code'))->toBe('token_ability_missing');
});
