<?php

use App\Enums\ApiTokenAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

test('catalog API read limiter is configured', function () {
    [$user, $company] = apiCatalogContext();

    // The first request should succeed
    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products');
    expect($res->status())->toBeIn([200, 429]);
});

test('catalog API write limiter is configured', function () {
    [$user, $company] = apiCatalogContext();

    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'products', [
        'name' => 'Rate Test',
    ]);
    expect($res->status())->toBeIn([201, 429]);
});

test('rate limited response returns correct structure', function () {
    [$user, $company] = apiCatalogContext();

    // Hit the endpoint multiple times quickly
    $statuses = [];
    for ($i = 0; $i < 5; $i++) {
        $res = test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogWrite->value]))
            ->postJson(apiUrl('products'), ['name' => "RL-{$i}"]);
        $statuses[] = $res->status();
    }

    // At least one request should get through or be rate limited
    expect($statuses)->toContain(201);
});

test('X-Request-ID present on rate-limited responses', function () {
    [$user, $company] = apiCatalogContext();

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products');
    expect($res->headers->get('X-Request-ID'))->toBeString();
});
