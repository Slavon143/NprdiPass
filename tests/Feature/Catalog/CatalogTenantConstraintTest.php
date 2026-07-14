<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('category cannot have parent from another company', function () {
    $companyA = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa01', 'name' => 'Company A', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $companyB = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa02', 'name' => 'Company B', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $parentId = DB::table('categories')->insertGetId([
        'uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbb01', 'company_id' => $companyA, 'name' => 'Parent A', 'slug' => 'parent-a', 'slug_normalized' => 'parent-a', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('categories')->insert([
        'uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbb02', 'company_id' => $companyB, 'parent_id' => $parentId, 'name' => 'Child B', 'slug' => 'child-b', 'slug_normalized' => 'child-b', 'status' => 'active', 'depth' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
})->group('catalog-tenant');

test('variant cannot belong to product of another company', function () {
    $companyA = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa03', 'name' => 'Company A', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $companyB = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa04', 'name' => 'Company B', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $productAId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc01', 'company_id' => $companyA, 'name' => 'Product A', 'slug' => 'product-a', 'slug_normalized' => 'product-a', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_variants')->insert([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd01', 'company_id' => $companyB, 'product_id' => $productAId, 'name' => 'Variant B', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
})->group('catalog-tenant');

test('category-product assignment cannot cross companies', function () {
    $companyA = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa05', 'name' => 'Company A', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $companyB = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa06', 'name' => 'Company B', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $productAId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc02', 'company_id' => $companyA, 'name' => 'Product A2', 'slug' => 'product-a2', 'slug_normalized' => 'product-a2', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $categoryBId = DB::table('categories')->insertGetId([
        'uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbb03', 'company_id' => $companyB, 'name' => 'Category B', 'slug' => 'category-b', 'slug_normalized' => 'category-b', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('category_product')->insert([
        'company_id' => $companyA, 'product_id' => $productAId, 'category_id' => $categoryBId, 'created_at' => now(),
    ]);
})->group('catalog-tenant');

test('attribute option cannot belong to definition of another company', function () {
    $companyA = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa07', 'name' => 'Company A', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $companyB = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa08', 'name' => 'Company B', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $defAId = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee01', 'company_id' => $companyA, 'name' => 'Def A', 'code' => 'def_a', 'type' => 'select', 'scope' => 'product', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('attribute_options')->insert([
        'company_id' => $companyB, 'attribute_definition_id' => $defAId, 'label' => 'Option B', 'code' => 'opt_b', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
})->group('catalog-tenant');

test('media cannot belong to product of another company', function () {
    $companyA = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa09', 'name' => 'Company A', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $companyB = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa10', 'name' => 'Company B', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $productAId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc03', 'company_id' => $companyA, 'name' => 'Product A3', 'slug' => 'product-a3', 'slug_normalized' => 'product-a3', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_media')->insert([
        'uuid' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
        'company_id' => $companyB,
        'product_id' => $productAId,
        'original_filename' => 'test.jpg',
        'storage_path' => 'test.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 100,
        'checksum_sha256' => str_repeat('a', 64),
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->group('catalog-tenant');

test('media variant must belong to the same product on variant media', function () {
    $company = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa11', 'name' => 'Company', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc04', 'company_id' => $company, 'name' => 'Product 4', 'slug' => 'product-4', 'slug_normalized' => 'product-4', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherProductId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc05', 'company_id' => $company, 'name' => 'Product 5', 'slug' => 'product-5', 'slug_normalized' => 'product-5', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $variantId = DB::table('product_variants')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd02', 'company_id' => $company, 'product_id' => $productId, 'name' => 'Variant', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_media')->insert([
        'uuid' => 'ffffffff-ffff-ffff-ffff-fffffffffff1',
        'company_id' => $company,
        'product_id' => $otherProductId,
        'product_variant_id' => $variantId,
        'original_filename' => 'test.jpg',
        'storage_path' => 'test.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 100,
        'checksum_sha256' => str_repeat('b', 64),
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->group('catalog-tenant');

test('category cannot be updated to become its own parent', function () {
    $company = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa12', 'name' => 'Self Parent Company', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $categoryId = DB::table('categories')->insertGetId([
        'uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbb04', 'company_id' => $company, 'name' => 'Self Parent', 'slug' => 'self-parent', 'slug_normalized' => 'self-parent', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('categories')->where('id', $categoryId)->update(['parent_id' => $categoryId]);
})->group('catalog-tenant');

test('category cannot be inserted as its own parent with an explicit id', function () {
    $company = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa13', 'name' => 'Insert Self Parent Company', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('categories')->insert([
        'id' => 999999,
        'uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbb05',
        'company_id' => $company,
        'parent_id' => 999999,
        'name' => 'Insert Self Parent',
        'slug' => 'insert-self-parent',
        'slug_normalized' => 'insert-self-parent',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->group('catalog-tenant');

test('product attribute value cannot cross the product tenant boundary', function () {
    $companyA = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa14', 'name' => 'Value Company A', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $companyB = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa15', 'name' => 'Value Company B', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $productA = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc06', 'company_id' => $companyA, 'name' => 'Value Product A', 'slug' => 'value-product-a', 'slug_normalized' => 'value-product-a', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $definitionB = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee02', 'company_id' => $companyB, 'name' => 'Value Definition B', 'code' => 'value_definition_b', 'type' => 'text', 'scope' => 'product', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_attribute_values')->insert([
        'company_id' => $companyB,
        'product_id' => $productA,
        'attribute_definition_id' => $definitionB,
        'value_text' => 'cross tenant',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->group('catalog-tenant');

test('variant attribute value cannot cross the variant tenant boundary', function () {
    $companyA = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa16', 'name' => 'Variant Value Company A', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $companyB = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa17', 'name' => 'Variant Value Company B', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $productA = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc07', 'company_id' => $companyA, 'name' => 'Variant Value Product A', 'slug' => 'variant-value-product-a', 'slug_normalized' => 'variant-value-product-a', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $variantA = DB::table('product_variants')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd03', 'company_id' => $companyA, 'product_id' => $productA, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $definitionB = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee03', 'company_id' => $companyB, 'name' => 'Variant Value Definition B', 'code' => 'variant_value_definition_b', 'type' => 'text', 'scope' => 'variant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('variant_attribute_values')->insert([
        'company_id' => $companyB,
        'product_variant_id' => $variantA,
        'attribute_definition_id' => $definitionB,
        'value_text' => 'cross tenant',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->group('catalog-tenant');
