<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Catalog constraint tests require MySQL 8.');
    }
});

beforeEach(function () {
    $this->companyA = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa21', 'name' => 'Company A', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->companyB = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa22', 'name' => 'Company B', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

test('duplicate product slug in same company is rejected', function () {
    DB::table('products')->insert([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc21', 'company_id' => $this->companyA, 'name' => 'Test', 'slug' => 'test', 'slug_normalized' => 'test', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('products')->insert([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc22', 'company_id' => $this->companyA, 'name' => 'Test 2', 'slug' => 'test-2', 'slug_normalized' => 'test', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
})->group('catalog-unique');

test('same product slug in different companies is allowed', function () {
    DB::table('products')->insert([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc23', 'company_id' => $this->companyA, 'name' => 'Test A', 'slug' => 'test-a', 'slug_normalized' => 'test', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $result = DB::table('products')->insert([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc24', 'company_id' => $this->companyB, 'name' => 'Test B', 'slug' => 'test-b', 'slug_normalized' => 'test', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($result)->toBeTrue();
})->group('catalog-unique');

test('duplicate SKU in same company is rejected', function () {
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc25', 'company_id' => $this->companyA, 'name' => 'P', 'slug' => 'p', 'slug_normalized' => 'p', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('product_variants')->insert([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd21', 'company_id' => $this->companyA, 'product_id' => $productId, 'sku' => 'SKU001', 'sku_normalized' => 'SKU001', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_variants')->insert([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd22', 'company_id' => $this->companyA, 'product_id' => $productId, 'sku' => 'sku-001', 'sku_normalized' => 'SKU001', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
})->group('catalog-unique');

test('same SKU in different companies is allowed', function () {
    $productAId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc26', 'company_id' => $this->companyA, 'name' => 'PA', 'slug' => 'pa', 'slug_normalized' => 'pa', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $productBId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc27', 'company_id' => $this->companyB, 'name' => 'PB', 'slug' => 'pb', 'slug_normalized' => 'pb', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('product_variants')->insert([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd23', 'company_id' => $this->companyA, 'product_id' => $productAId, 'sku' => 'SKU001', 'sku_normalized' => 'SKU001', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $result = DB::table('product_variants')->insert([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd24', 'company_id' => $this->companyB, 'product_id' => $productBId, 'sku' => 'SKU001', 'sku_normalized' => 'SKU001', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($result)->toBeTrue();
})->group('catalog-unique');

test('duplicate GTIN in same company is rejected when not null', function () {
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc28', 'company_id' => $this->companyA, 'name' => 'PG', 'slug' => 'pg', 'slug_normalized' => 'pg', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('product_variants')->insert([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd25', 'company_id' => $this->companyA, 'product_id' => $productId, 'gtin' => '1234567890123', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_variants')->insert([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd26', 'company_id' => $this->companyA, 'product_id' => $productId, 'gtin' => '1234567890123', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
})->group('catalog-unique');

test('multiple NULL GTINs are allowed', function () {
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc29', 'company_id' => $this->companyA, 'name' => 'PN', 'slug' => 'pn', 'slug_normalized' => 'pn', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('product_variants')->insert([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd27', 'company_id' => $this->companyA, 'product_id' => $productId, 'gtin' => null, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $result = DB::table('product_variants')->insert([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd28', 'company_id' => $this->companyA, 'product_id' => $productId, 'gtin' => null, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($result)->toBeTrue();
})->group('catalog-unique');

test('duplicate attribute code in same company is rejected', function () {
    DB::table('attribute_definitions')->insert([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee21', 'company_id' => $this->companyA, 'name' => 'Def1', 'code' => 'color', 'type' => 'text', 'scope' => 'product', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('attribute_definitions')->insert([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee22', 'company_id' => $this->companyA, 'name' => 'Def2', 'code' => 'color', 'type' => 'text', 'scope' => 'product', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
})->group('catalog-unique');

test('duplicate category-product assignment is rejected', function () {
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc30', 'company_id' => $this->companyA, 'name' => 'P30', 'slug' => 'p30', 'slug_normalized' => 'p30', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $categoryId = DB::table('categories')->insertGetId([
        'uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbb21', 'company_id' => $this->companyA, 'name' => 'Cat', 'slug' => 'cat', 'slug_normalized' => 'cat', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('category_product')->insert([
        'company_id' => $this->companyA, 'product_id' => $productId, 'category_id' => $categoryId, 'created_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('category_product')->insert([
        'company_id' => $this->companyA, 'product_id' => $productId, 'category_id' => $categoryId, 'created_at' => now(),
    ]);
})->group('catalog-unique');

test('duplicate product attribute value for same definition is rejected', function () {
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc31', 'company_id' => $this->companyA, 'name' => 'P31', 'slug' => 'p31', 'slug_normalized' => 'p31', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $defId = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee23', 'company_id' => $this->companyA, 'name' => 'Def Text', 'code' => 'def_text', 'type' => 'text', 'scope' => 'product', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('product_attribute_values')->insert([
        'company_id' => $this->companyA, 'product_id' => $productId, 'attribute_definition_id' => $defId, 'value_text' => 'Hello', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_attribute_values')->insert([
        'company_id' => $this->companyA, 'product_id' => $productId, 'attribute_definition_id' => $defId, 'value_text' => 'World', 'created_at' => now(), 'updated_at' => now(),
    ]);
})->group('catalog-unique');

test('GTIN accepts only supported numeric lengths', function (string $gtin) {
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc32', 'company_id' => $this->companyA, 'name' => 'GTIN Valid', 'slug' => 'gtin-valid', 'slug_normalized' => 'gtin-valid', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('product_variants')->insert([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd29', 'company_id' => $this->companyA, 'product_id' => $productId, 'gtin' => $gtin, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]))->toBeTrue();
})->with([
    'GTIN-8' => '12345670',
    'GTIN-12' => '123456789012',
    'GTIN-13' => '1234567890123',
    'GTIN-14' => '12345678901234',
])->group('catalog-unique');

test('GTIN rejects unsupported formats', function (string $gtin) {
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc33', 'company_id' => $this->companyA, 'name' => 'GTIN Invalid', 'slug' => 'gtin-invalid', 'slug_normalized' => 'gtin-invalid', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_variants')->insert([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd30', 'company_id' => $this->companyA, 'product_id' => $productId, 'gtin' => $gtin, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
})->with([
    'letters' => 'ABC',
    'seven digits' => '1234567',
    'nine digits' => '123456789',
    'punctuation' => '1234-5678',
])->group('catalog-unique');

test('duplicate category slug in the same company is rejected', function () {
    DB::table('categories')->insert([
        'uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbb22', 'company_id' => $this->companyA, 'name' => 'Category One', 'slug' => 'category-one', 'slug_normalized' => 'category', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('categories')->insert([
        'uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbb23', 'company_id' => $this->companyA, 'name' => 'Category Two', 'slug' => 'category-two', 'slug_normalized' => 'category', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
})->group('catalog-unique');

test('duplicate attribute option code in one definition is rejected', function () {
    $definitionId = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee24', 'company_id' => $this->companyA, 'name' => 'Option Definition', 'code' => 'option_definition', 'type' => 'select', 'scope' => 'product', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('attribute_options')->insert([
        'company_id' => $this->companyA, 'attribute_definition_id' => $definitionId, 'label' => 'First', 'code' => 'duplicate', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('attribute_options')->insert([
        'company_id' => $this->companyA, 'attribute_definition_id' => $definitionId, 'label' => 'Second', 'code' => 'duplicate', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
})->group('catalog-unique');

test('duplicate variant attribute value for one definition is rejected', function () {
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc34', 'company_id' => $this->companyA, 'name' => 'Variant Value Product', 'slug' => 'variant-value-product', 'slug_normalized' => 'variant-value-product', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $variantId = DB::table('product_variants')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd32', 'company_id' => $this->companyA, 'product_id' => $productId, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $definitionId = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee25', 'company_id' => $this->companyA, 'name' => 'Variant Value Definition', 'code' => 'variant_value_definition', 'type' => 'text', 'scope' => 'variant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('variant_attribute_values')->insert([
        'company_id' => $this->companyA, 'product_variant_id' => $variantId, 'attribute_definition_id' => $definitionId, 'value_text' => 'First', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('variant_attribute_values')->insert([
        'company_id' => $this->companyA, 'product_variant_id' => $variantId, 'attribute_definition_id' => $definitionId, 'value_text' => 'Second', 'created_at' => now(), 'updated_at' => now(),
    ]);
})->group('catalog-unique');

test('soft-deleted product continues to reserve its normalized slug', function () {
    DB::table('products')->insert([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc35', 'company_id' => $this->companyA, 'name' => 'Deleted Product', 'slug' => 'deleted-product', 'slug_normalized' => 'reserved-product', 'status' => 'archived', 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('products')->insert([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc36', 'company_id' => $this->companyA, 'name' => 'Replacement Product', 'slug' => 'replacement-product', 'slug_normalized' => 'reserved-product', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
})->group('catalog-unique');

test('soft-deleted variant continues to reserve its normalized SKU', function () {
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc37', 'company_id' => $this->companyA, 'name' => 'SKU Product', 'slug' => 'sku-product', 'slug_normalized' => 'sku-product', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('product_variants')->insert([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd33', 'company_id' => $this->companyA, 'product_id' => $productId, 'sku' => 'Reserved', 'sku_normalized' => 'RESERVED', 'status' => 'archived', 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_variants')->insert([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd34', 'company_id' => $this->companyA, 'product_id' => $productId, 'sku' => 'Reserved', 'sku_normalized' => 'RESERVED', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
})->group('catalog-unique');
