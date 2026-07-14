<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $now = now();

    $this->companyA = DB::table('companies')->insertGetId([
        'uuid' => '10000000-0000-0000-0000-000000000001',
        'name' => 'Pointer Company A',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $this->companyB = DB::table('companies')->insertGetId([
        'uuid' => '10000000-0000-0000-0000-000000000002',
        'name' => 'Pointer Company B',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->categoryA = DB::table('categories')->insertGetId([
        'uuid' => '11000000-0000-0000-0000-000000000001',
        'company_id' => $this->companyA,
        'name' => 'Category A',
        'slug' => 'pointer-category-a',
        'slug_normalized' => 'pointer-category-a',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $this->categoryB = DB::table('categories')->insertGetId([
        'uuid' => '11000000-0000-0000-0000-000000000002',
        'company_id' => $this->companyB,
        'name' => 'Category B',
        'slug' => 'pointer-category-b',
        'slug_normalized' => 'pointer-category-b',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->productA1 = DB::table('products')->insertGetId([
        'uuid' => '12000000-0000-0000-0000-000000000001',
        'company_id' => $this->companyA,
        'name' => 'Product A1',
        'slug' => 'pointer-product-a1',
        'slug_normalized' => 'pointer-product-a1',
        'status' => 'draft',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $this->productA2 = DB::table('products')->insertGetId([
        'uuid' => '12000000-0000-0000-0000-000000000002',
        'company_id' => $this->companyA,
        'name' => 'Product A2',
        'slug' => 'pointer-product-a2',
        'slug_normalized' => 'pointer-product-a2',
        'status' => 'draft',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $this->productB = DB::table('products')->insertGetId([
        'uuid' => '12000000-0000-0000-0000-000000000003',
        'company_id' => $this->companyB,
        'name' => 'Product B',
        'slug' => 'pointer-product-b',
        'slug_normalized' => 'pointer-product-b',
        'status' => 'draft',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->variantA1 = DB::table('product_variants')->insertGetId([
        'uuid' => '13000000-0000-0000-0000-000000000001',
        'company_id' => $this->companyA,
        'product_id' => $this->productA1,
        'status' => 'draft',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $this->variantA1Second = DB::table('product_variants')->insertGetId([
        'uuid' => '13000000-0000-0000-0000-000000000002',
        'company_id' => $this->companyA,
        'product_id' => $this->productA1,
        'status' => 'draft',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $this->variantA2 = DB::table('product_variants')->insertGetId([
        'uuid' => '13000000-0000-0000-0000-000000000003',
        'company_id' => $this->companyA,
        'product_id' => $this->productA2,
        'status' => 'draft',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $this->variantB = DB::table('product_variants')->insertGetId([
        'uuid' => '13000000-0000-0000-0000-000000000004',
        'company_id' => $this->companyB,
        'product_id' => $this->productB,
        'status' => 'draft',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $insertMedia = static fn (int $companyId, int $productId, ?int $variantId, string $uuid): int => DB::table('product_media')->insertGetId([
        'uuid' => $uuid,
        'company_id' => $companyId,
        'product_id' => $productId,
        'product_variant_id' => $variantId,
        'original_filename' => 'pointer.jpg',
        'storage_path' => "catalog/{$uuid}.jpg",
        'mime_type' => 'image/jpeg',
        'size_bytes' => 128,
        'checksum_sha256' => hash('sha256', $uuid),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->productMediaA1 = $insertMedia($this->companyA, $this->productA1, null, '14000000-0000-0000-0000-000000000001');
    $this->productMediaA2 = $insertMedia($this->companyA, $this->productA2, null, '14000000-0000-0000-0000-000000000002');
    $this->productMediaB = $insertMedia($this->companyB, $this->productB, null, '14000000-0000-0000-0000-000000000003');
    $this->variantMediaA1 = $insertMedia($this->companyA, $this->productA1, $this->variantA1, '14000000-0000-0000-0000-000000000004');
    $this->variantMediaA1Second = $insertMedia($this->companyA, $this->productA1, $this->variantA1Second, '14000000-0000-0000-0000-000000000005');
});

test('product accepts a primary category from its company', function () {
    expect(DB::table('products')->where('id', $this->productA1)->update([
        'primary_category_id' => $this->categoryA,
    ]))->toBe(1);
});

test('product rejects a primary category from another company', function () {
    $this->expectException(QueryException::class);

    DB::table('products')->where('id', $this->productA1)->update([
        'primary_category_id' => $this->categoryB,
    ]);
});

test('product accepts its own default variant', function () {
    expect(DB::table('products')->where('id', $this->productA1)->update([
        'default_variant_id' => $this->variantA1,
    ]))->toBe(1);
});

test('product rejects a default variant from another product in the same company', function () {
    $this->expectException(QueryException::class);

    DB::table('products')->where('id', $this->productA1)->update([
        'default_variant_id' => $this->variantA2,
    ]);
});

test('product rejects a default variant from another company', function () {
    $this->expectException(QueryException::class);

    DB::table('products')->where('id', $this->productA1)->update([
        'default_variant_id' => $this->variantB,
    ]);
});

test('product accepts primary media belonging to itself', function () {
    expect(DB::table('products')->where('id', $this->productA1)->update([
        'primary_media_id' => $this->productMediaA1,
    ]))->toBe(1);
});

test('product rejects primary media from another product in the same company', function () {
    $this->expectException(QueryException::class);

    DB::table('products')->where('id', $this->productA1)->update([
        'primary_media_id' => $this->productMediaA2,
    ]);
});

test('product rejects primary media from another company', function () {
    $this->expectException(QueryException::class);

    DB::table('products')->where('id', $this->productA1)->update([
        'primary_media_id' => $this->productMediaB,
    ]);
});

test('variant accepts primary media belonging to itself', function () {
    expect(DB::table('product_variants')->where('id', $this->variantA1)->update([
        'primary_media_id' => $this->variantMediaA1,
    ]))->toBe(1);
});

test('variant rejects primary media belonging to another variant', function () {
    $this->expectException(QueryException::class);

    DB::table('product_variants')->where('id', $this->variantA1)->update([
        'primary_media_id' => $this->variantMediaA1Second,
    ]);
});
