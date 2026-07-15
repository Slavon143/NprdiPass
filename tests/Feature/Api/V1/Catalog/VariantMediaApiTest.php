<?php

use App\Enums\ApiTokenAbility;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

function fakeJpegImage(): UploadedFile
{
    return UploadedFile::fake()->image('test.jpg', 100, 100);
}

function seedMediaProduct2($company, $user): array
{
    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'name' => 'Media Product 2',
        'slug' => 'media-product-2',
        'slug_normalized' => 'media-product-2',
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

    return [$product->refresh(), $variant->refresh()];
}

function apiMediaToken($user, $company): string
{
    return apiToken($user, $company, [
        ApiTokenAbility::CatalogMedia->value,
        ApiTokenAbility::CatalogRead->value,
    ]);
}

test('can list variant media', function () {
    [$user, $company] = apiCatalogContext();
    [$product, $variant] = seedMediaProduct2($company, $user);

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}/variants/{$variant->uuid}/media");
    expect($res->status())->toBe(200);
});

test('can upload variant image', function () {
    [$user, $company] = apiCatalogContext();
    [$product, $variant] = seedMediaProduct2($company, $user);

    $res = test()->withToken(apiMediaToken($user, $company))
        ->post(apiUrl("products/{$product->uuid}/variants/{$variant->uuid}/media"), [
            'image' => fakeJpegImage(),
        ]);
    expect($res->status())->toBe(201);
});

test('wrong variant media returns 404', function () {
    [$user, $company] = apiCatalogContext();
    [$product, $variant1] = seedMediaProduct2($company, $user);
    $variant2 = new ProductVariant;
    $variant2->forceFill([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'name' => 'Extra',
        'status' => 'draft',
        'sort_order' => 10,
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ])->save();

    $upload = test()->withToken(apiMediaToken($user, $company))
        ->post(apiUrl("products/{$product->uuid}/variants/{$variant1->uuid}/media"), [
            'image' => fakeJpegImage(),
        ]);
    $mediaUuid = $upload->json('data.uuid');

    test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogMedia->value]))
        ->patch(apiUrl("products/{$product->uuid}/variants/{$variant2->uuid}/media/{$mediaUuid}"), [
            'alt_text' => 'Should 404',
        ], ['Accept' => 'application/json'])
        ->assertNotFound();
});

test('variant media upload requires catalog.media ability', function () {
    [$user, $company] = apiCatalogContext();
    [$product, $variant] = seedMediaProduct2($company, $user);

    test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->post(apiUrl("products/{$product->uuid}/variants/{$variant->uuid}/media"), [
            'image' => fakeJpegImage(),
        ])->assertStatus(403);
});
