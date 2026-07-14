<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('all catalog tables exist', function () {
    $tables = [
        'categories',
        'products',
        'product_variants',
        'category_product',
        'attribute_definitions',
        'attribute_options',
        'product_attribute_values',
        'variant_attribute_values',
        'product_attribute_value_options',
        'variant_attribute_value_options',
        'product_media',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Table '{$table}' must exist");
    }
});

test('categories table has required columns', function () {
    expect(Schema::hasColumns('categories', [
        'id', 'uuid', 'company_id', 'parent_id', 'depth',
        'name', 'slug', 'slug_normalized', 'description',
        'sort_order', 'status', 'created_by', 'updated_by',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

test('products table has required columns', function () {
    expect(Schema::hasColumns('products', [
        'id', 'uuid', 'company_id', 'primary_category_id',
        'default_variant_id', 'primary_media_id',
        'name', 'slug', 'slug_normalized',
        'short_description', 'description', 'brand', 'manufacturer',
        'status', 'published_at', 'created_by', 'updated_by',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

test('product_variants table has required columns', function () {
    expect(Schema::hasColumns('product_variants', [
        'id', 'uuid', 'company_id', 'product_id', 'primary_media_id',
        'name', 'sku', 'sku_normalized', 'gtin', 'mpn',
        'is_default', 'status', 'sort_order',
        'created_by', 'updated_by',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

test('category_product pivot has required columns', function () {
    expect(Schema::hasColumns('category_product', [
        'id', 'company_id', 'product_id', 'category_id', 'created_at',
    ]))->toBeTrue();
});

test('attribute_definitions table has required columns', function () {
    expect(Schema::hasColumns('attribute_definitions', [
        'id', 'uuid', 'company_id', 'name', 'code',
        'description', 'type', 'scope', 'unit',
        'required', 'filterable', 'searchable',
        'validation_rules', 'sort_order', 'status',
        'created_by', 'updated_by', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

test('attribute_options table has required columns', function () {
    expect(Schema::hasColumns('attribute_options', [
        'id', 'company_id', 'attribute_definition_id',
        'label', 'code', 'sort_order', 'status',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});

test('product_attribute_values table has typed value columns', function () {
    expect(Schema::hasColumns('product_attribute_values', [
        'id', 'company_id', 'product_id', 'attribute_definition_id',
        'value_text', 'value_integer', 'value_decimal',
        'value_boolean', 'value_date', 'value_option_id',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});

test('variant_attribute_values table has typed value columns', function () {
    expect(Schema::hasColumns('variant_attribute_values', [
        'id', 'company_id', 'product_variant_id', 'attribute_definition_id',
        'value_text', 'value_integer', 'value_decimal',
        'value_boolean', 'value_date', 'value_option_id',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});

test('product_media table has required columns', function () {
    expect(Schema::hasColumns('product_media', [
        'id', 'uuid', 'company_id', 'product_id', 'product_variant_id',
        'original_filename', 'storage_path', 'mime_type',
        'size_bytes', 'width', 'height', 'checksum_sha256',
        'alt_text', 'caption', 'sort_order', 'uploaded_by',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

test('migration status shows all migrations as ran', function () {
    $migrations = DB::table('migrations')->pluck('migration')->toArray();

    expect($migrations)->toContain('2026_07_14_000001_create_categories_table');
    expect($migrations)->toContain('2026_07_14_000002_create_products_table');
    expect($migrations)->toContain('2026_07_14_000003_create_product_variants_table');
    expect($migrations)->toContain('2026_07_14_000004_create_category_product_table');
    expect($migrations)->toContain('2026_07_14_000005_create_attribute_definitions_table');
    expect($migrations)->toContain('2026_07_14_000006_create_attribute_options_table');
    expect($migrations)->toContain('2026_07_14_000007_create_product_attribute_values_table');
    expect($migrations)->toContain('2026_07_14_000008_create_variant_attribute_values_table');
    expect($migrations)->toContain('2026_07_14_000009_create_product_attribute_value_options_table');
    expect($migrations)->toContain('2026_07_14_000010_create_variant_attribute_value_options_table');
    expect($migrations)->toContain('2026_07_14_000011_create_product_media_table');
    expect($migrations)->toContain('2026_07_14_000012_add_catalog_deferred_foreign_keys');
});
