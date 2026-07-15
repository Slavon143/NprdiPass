<?php

use App\Enums\ApiTokenAbility;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

function seedMediaProduct($company, $user, string $name = 'Media Product'): Product
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

    return $product->refresh();
}

function fakeJpeg(): UploadedFile
{
    return UploadedFile::fake()->image('test.jpg', 100, 100);
}

function fakePng(): UploadedFile
{
    return UploadedFile::fake()->image('test.png', 100, 100);
}

test('can list product media', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedMediaProduct($company, $user);

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}/media");
    expect($res->status())->toBe(200);
    expect($res->json('data'))->toBeArray();
});

test('can upload product image', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedMediaProduct($company, $user);

    $res = test()->withToken(apiUploadToken($user, $company))
        ->post(apiUrl("products/{$product->uuid}/media"), [
            'image' => fakeJpeg(),
        ]);
    expect($res->status())->toBe(201);
    expect($res->json('data.uuid'))->toBeString();
    expect($res->json('data.mime_type'))->toBe('image/jpeg');
});

test('can upload PNG image', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedMediaProduct($company, $user);

    $res = test()->withToken(apiUploadToken($user, $company))
        ->post(apiUrl("products/{$product->uuid}/media"), [
            'image' => fakePng(),
        ]);
    expect($res->status())->toBe(201);
});

test('invalid image returns 422', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedMediaProduct($company, $user);

    $file = UploadedFile::fake()->create('test.txt', 100, 'text/plain');

    $res = test()->withToken(apiUploadToken($user, $company))
        ->post(apiUrl("products/{$product->uuid}/media"), [
            'image' => $file,
        ], ['Accept' => 'application/json']);
    expect($res->status())->toBe(422);
});

test('media upload requires catalog.media ability', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedMediaProduct($company, $user);

    test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->post(apiUrl("products/{$product->uuid}/media"), [
            'image' => fakeJpeg(),
        ])->assertStatus(403);
});

test('media resource does not expose internal fields', function () {
    [$user, $company] = apiCatalogContext();
    $product = seedMediaProduct($company, $user);

    $upload = test()->withToken(apiUploadToken($user, $company))
        ->post(apiUrl("products/{$product->uuid}/media"), [
            'image' => fakeJpeg(),
        ]);
    $mediaUuid = $upload->json('data.uuid');

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "products/{$product->uuid}/media");
    $data = $res->json('data');
    expect($data)->toBeArray();

    if (count($data) > 0) {
        $first = $data[0];
        expect($first)->toHaveKeys(['uuid', 'mime_type', 'size_bytes', 'width', 'height', 'sort_order']);
        expect($first)->not->toHaveKey('storage_path');
        expect($first)->not->toHaveKey('checksum_sha256');
        expect($first)->not->toHaveKey('company_id');
    }
});

test('wrong product media returns 404', function () {
    [$user, $company] = apiCatalogContext();
    $product1 = seedMediaProduct($company, $user, 'Media Product A');
    $product2 = seedMediaProduct($company, $user, 'Media Product B');

    $upload = test()->withToken(apiUploadToken($user, $company))
        ->post(apiUrl("products/{$product1->uuid}/media"), [
            'image' => fakeJpeg(),
        ]);
    $mediaUuid = $upload->json('data.uuid');

    test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogMedia->value]))
        ->patch(apiUrl("products/{$product2->uuid}/media/{$mediaUuid}"), [
            'alt_text' => 'Should 404',
        ], ['Accept' => 'application/json'])
        ->assertNotFound();
});
