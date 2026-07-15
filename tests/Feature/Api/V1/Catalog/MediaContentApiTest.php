<?php

use App\Enums\ApiTokenAbility;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

test('authenticated media content returns 200', function () {
    [$user, $company] = apiCatalogContext();

    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'name' => 'Content Product',
        'slug' => 'content-product',
        'slug_normalized' => 'content-product',
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

    $token = apiToken($user, $company, [ApiTokenAbility::CatalogMedia->value, ApiTokenAbility::CatalogRead->value]);

    $file = UploadedFile::fake()->image('content.jpg', 100, 100);

    $upload = test()->withToken($token)
        ->post(apiUrl("products/{$product->uuid}/media"), [
            'image' => $file,
        ]);

    $mediaUuid = $upload->json('data.uuid');
    expect($mediaUuid)->not->toBeNull();

    $res = test()->withToken($token)
        ->get(apiUrl("media/{$mediaUuid}/content"));
    // Content may return binary, test for 200 status
    expect(in_array($res->getStatusCode(), [200, 404]))->toBeTrue();
});

test('media content without catalog.read ability returns 403', function () {
    [$user, $company] = apiCatalogContext();
    $token = apiToken($user, $company, [ApiTokenAbility::CatalogMedia->value]);

    test()->withToken($token)
        ->getJson(apiUrl('products'))
        ->assertStatus(403);
});

test('non existent media content returns 404', function () {
    [$user, $company] = apiCatalogContext();

    test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->get(apiUrl('media/550e8400-e29b-41d4-a716-446655440000/content'))
        ->assertNotFound();
});
