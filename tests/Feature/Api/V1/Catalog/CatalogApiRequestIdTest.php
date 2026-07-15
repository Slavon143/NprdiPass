<?php

use App\Enums\ApiTokenAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

test('X-Request-ID is present on API responses', function () {
    [$user, $company] = apiCatalogContext();

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products');
    expect($res->headers->get('X-Request-ID'))->toBeString();
    expect(strlen($res->headers->get('X-Request-ID')))->toBeGreaterThan(0);
});

test('request_id is present in response meta', function () {
    [$user, $company] = apiCatalogContext();

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products');
    expect($res->json('meta.request_id'))->toBeString();
});

test('request_id in meta matches X-Request-ID header', function () {
    [$user, $company] = apiCatalogContext();

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products');
    expect($res->json('meta.request_id'))->toBe($res->headers->get('X-Request-ID'));
});

test('error response includes request_id in meta', function () {
    [$user, $company] = apiCatalogContext();

    $res = apiPost($user, $company, [ApiTokenAbility::CatalogRead->value], 'categories', [
        'name' => 'Test', 'sort_order' => 0,
    ]);
    expect($res->status())->toBe(403);
    expect($res->json('meta.request_id'))->toBeString();
});

test('X-Request-ID present on 404 responses', function () {
    [$user, $company] = apiCatalogContext();

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products/nonexistent');
    expect($res->headers->get('X-Request-ID'))->toBeString();
});
