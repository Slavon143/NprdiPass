<?php

use App\Enums\ApiTokenAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

test('401 error has correct structure', function () {
    $res = $this->getJson(apiUrl('products'));
    expect($res->status())->toBe(401);
    expect($res->json('error.code'))->toBe('unauthenticated');
    expect($res->json('error.message'))->toBeString();
});

test('403 error has correct structure', function () {
    [$user, $company] = apiCatalogContext();
    $res = apiPost($user, $company, [ApiTokenAbility::CatalogRead->value], 'categories', [
        'name' => 'Test', 'sort_order' => 0,
    ]);
    expect($res->status())->toBe(403);
    expect($res->json('error.code'))->toBeString();
    expect($res->json('error.message'))->toBeString();
});

test('404 error has correct structure', function () {
    [$user, $company] = apiCatalogContext();
    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'categories/nonexistent-uuid');
    expect($res->status())->toBe(404);
    expect($res->json('error.code'))->toBeString();
});

test('422 error has correct structure with details', function () {
    [$user, $company] = apiCatalogContext();
    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'categories', [
        'sort_order' => -1,
    ]);
    expect($res->status())->toBe(422);
    expect($res->json('error.code'))->toBeString();
    expect($res->json('error.details'))->toBeArray();
});

test('error response includes request_id', function () {
    [$user, $company] = apiCatalogContext();
    $res = apiPost($user, $company, [ApiTokenAbility::CatalogRead->value], 'categories', [
        'name' => 'Test', 'sort_order' => 0,
    ]);
    expect($res->json('meta.request_id'))->not->toBeNull();
    expect($res->headers->get('X-Request-ID'))->toBeString();
});

test('response does not expose SQL details', function () {
    [$user, $company] = apiCatalogContext();
    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'categories/nonexistent-uuid');
    $body = $res->content();
    expect($body)->not->toContain('SQLSTATE');
    expect($body)->not->toContain('constraint');
});

test('X-Request-ID header is present on API responses', function () {
    [$user, $company] = apiCatalogContext();
    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products');
    expect($res->headers->get('X-Request-ID'))->toBeString();
});
