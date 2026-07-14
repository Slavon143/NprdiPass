<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa41', 'name' => 'Company', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc51', 'company_id' => $this->company, 'name' => 'Product 51', 'slug' => 'product-51', 'slug_normalized' => 'product-51', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->variantId = DB::table('product_variants')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd41', 'company_id' => $this->company, 'product_id' => $this->productId, 'name' => 'Variant', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

test('product-level media can be inserted', function () {
    $result = DB::table('product_media')->insert([
        'uuid' => 'ffffffff-ffff-ffff-ffff-fffffffffff5',
        'company_id' => $this->company,
        'product_id' => $this->productId,
        'original_filename' => 'test.jpg',
        'storage_path' => 'media/test.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1024,
        'checksum_sha256' => str_repeat('c', 64),
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($result)->toBeTrue();
})->group('catalog-media');

test('variant media must belong to the correct product', function () {
    $otherProductId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc52', 'company_id' => $this->company, 'name' => 'Product 52', 'slug' => 'product-52', 'slug_normalized' => 'product-52', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_media')->insert([
        'uuid' => 'ffffffff-ffff-ffff-ffff-fffffffffff6',
        'company_id' => $this->company,
        'product_id' => $otherProductId,
        'product_variant_id' => $this->variantId,
        'original_filename' => 'test.jpg',
        'storage_path' => 'media/test.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1024,
        'checksum_sha256' => str_repeat('d', 64),
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->group('catalog-media');

test('media with negative size is rejected', function () {
    $this->expectException(QueryException::class);

    DB::table('product_media')->insert([
        'uuid' => 'ffffffff-ffff-ffff-ffff-fffffffffff7',
        'company_id' => $this->company,
        'product_id' => $this->productId,
        'original_filename' => 'test.jpg',
        'storage_path' => 'media/test.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => -1,
        'checksum_sha256' => str_repeat('e', 64),
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->group('catalog-media');

test('media with zero width is rejected when non-null', function () {
    $this->expectException(QueryException::class);

    DB::table('product_media')->insert([
        'uuid' => 'ffffffff-ffff-ffff-ffff-fffffffffff8',
        'company_id' => $this->company,
        'product_id' => $this->productId,
        'original_filename' => 'test.jpg',
        'storage_path' => 'media/test.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 100,
        'width' => 0,
        'checksum_sha256' => str_repeat('f', 64),
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->group('catalog-media');

test('media with null width is allowed', function () {
    $result = DB::table('product_media')->insert([
        'uuid' => 'ffffffff-ffff-ffff-ffff-fffffffffff9',
        'company_id' => $this->company,
        'product_id' => $this->productId,
        'original_filename' => 'test.jpg',
        'storage_path' => 'media/test.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 100,
        'width' => null,
        'checksum_sha256' => str_repeat('a', 64),
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($result)->toBeTrue();
})->group('catalog-media');

test('media checksum must be exactly 64 hexadecimal characters', function (string $checksum) {
    $this->expectException(QueryException::class);

    DB::table('product_media')->insert([
        'uuid' => 'ffffffff-ffff-ffff-ffff-fffffffffffa',
        'company_id' => $this->company,
        'product_id' => $this->productId,
        'original_filename' => 'invalid-checksum.jpg',
        'storage_path' => 'media/invalid-checksum.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 100,
        'checksum_sha256' => $checksum,
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->with([
    'too short' => str_repeat('a', 63),
    'not hexadecimal' => str_repeat('g', 64),
])->group('catalog-media');
