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
    $this->company = DB::table('companies')->insertGetId([
        'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa31', 'name' => 'Company', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

test('select value cannot use option from different definition', function () {
    $def1Id = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee31', 'company_id' => $this->company, 'name' => 'Color', 'code' => 'color', 'type' => 'select', 'scope' => 'product', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $def2Id = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee32', 'company_id' => $this->company, 'name' => 'Size', 'code' => 'size', 'type' => 'select', 'scope' => 'product', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $option2Id = DB::table('attribute_options')->insertGetId([
        'company_id' => $this->company, 'attribute_definition_id' => $def2Id, 'label' => 'Large', 'code' => 'large', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc41', 'company_id' => $this->company, 'name' => 'Product 41', 'slug' => 'product-41', 'slug_normalized' => 'product-41', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_attribute_values')->insert([
        'company_id' => $this->company,
        'product_id' => $productId,
        'attribute_definition_id' => $def1Id,
        'value_option_id' => $option2Id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->group('catalog-attribute');

test('select value can use option from its own definition', function () {
    $defId = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee33', 'company_id' => $this->company, 'name' => 'Material', 'code' => 'material', 'type' => 'select', 'scope' => 'product', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $optionId = DB::table('attribute_options')->insertGetId([
        'company_id' => $this->company, 'attribute_definition_id' => $defId, 'label' => 'Cotton', 'code' => 'cotton', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc42', 'company_id' => $this->company, 'name' => 'Product 42', 'slug' => 'product-42', 'slug_normalized' => 'product-42', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $result = DB::table('product_attribute_values')->insert([
        'company_id' => $this->company,
        'product_id' => $productId,
        'attribute_definition_id' => $defId,
        'value_option_id' => $optionId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($result)->toBeTrue();
})->group('catalog-attribute');

test('typed value check prevents multiple value columns populated', function () {
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc43', 'company_id' => $this->company, 'name' => 'Product 43', 'slug' => 'product-43', 'slug_normalized' => 'product-43', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $defId = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee34', 'company_id' => $this->company, 'name' => 'Mixed', 'code' => 'mixed', 'type' => 'text', 'scope' => 'product', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_attribute_values')->insert([
        'company_id' => $this->company,
        'product_id' => $productId,
        'attribute_definition_id' => $defId,
        'value_text' => 'text value',
        'value_integer' => 42,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->group('catalog-attribute');

test('typed value check allows single value column', function () {
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc44', 'company_id' => $this->company, 'name' => 'Product 44', 'slug' => 'product-44', 'slug_normalized' => 'product-44', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $defId = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee35', 'company_id' => $this->company, 'name' => 'Int', 'code' => 'int', 'type' => 'integer', 'scope' => 'product', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $result = DB::table('product_attribute_values')->insert([
        'company_id' => $this->company,
        'product_id' => $productId,
        'attribute_definition_id' => $defId,
        'value_integer' => 42,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($result)->toBeTrue();
})->group('catalog-attribute');

test('product multiselect cannot use an option from another definition', function () {
    $definitionA = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee36', 'company_id' => $this->company, 'name' => 'Product Multi A', 'code' => 'product_multi_a', 'type' => 'multiselect', 'scope' => 'product', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $definitionB = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee37', 'company_id' => $this->company, 'name' => 'Product Multi B', 'code' => 'product_multi_b', 'type' => 'multiselect', 'scope' => 'product', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $optionB = DB::table('attribute_options')->insertGetId([
        'company_id' => $this->company, 'attribute_definition_id' => $definitionB, 'label' => 'Option B', 'code' => 'option_b', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc45', 'company_id' => $this->company, 'name' => 'Product Multi', 'slug' => 'product-multi', 'slug_normalized' => 'product-multi', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $valueId = DB::table('product_attribute_values')->insertGetId([
        'company_id' => $this->company, 'product_id' => $productId, 'attribute_definition_id' => $definitionA, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('product_attribute_value_options')->insert([
        'company_id' => $this->company,
        'attribute_definition_id' => $definitionB,
        'product_attribute_value_id' => $valueId,
        'attribute_option_id' => $optionB,
        'created_at' => now(),
    ]);
})->group('catalog-attribute');

test('product multiselect accepts an option from its definition', function () {
    $definitionId = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee38', 'company_id' => $this->company, 'name' => 'Product Multi Valid', 'code' => 'product_multi_valid', 'type' => 'multiselect', 'scope' => 'product', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $optionId = DB::table('attribute_options')->insertGetId([
        'company_id' => $this->company, 'attribute_definition_id' => $definitionId, 'label' => 'Valid', 'code' => 'valid', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc46', 'company_id' => $this->company, 'name' => 'Product Multi Valid', 'slug' => 'product-multi-valid', 'slug_normalized' => 'product-multi-valid', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $valueId = DB::table('product_attribute_values')->insertGetId([
        'company_id' => $this->company, 'product_id' => $productId, 'attribute_definition_id' => $definitionId, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('product_attribute_value_options')->insert([
        'company_id' => $this->company,
        'attribute_definition_id' => $definitionId,
        'product_attribute_value_id' => $valueId,
        'attribute_option_id' => $optionId,
        'created_at' => now(),
    ]))->toBeTrue();
})->group('catalog-attribute');

test('variant multiselect cannot use an option from another definition', function () {
    $definitionA = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee39', 'company_id' => $this->company, 'name' => 'Variant Multi A', 'code' => 'variant_multi_a', 'type' => 'multiselect', 'scope' => 'variant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $definitionB = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee40', 'company_id' => $this->company, 'name' => 'Variant Multi B', 'code' => 'variant_multi_b', 'type' => 'multiselect', 'scope' => 'variant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $optionB = DB::table('attribute_options')->insertGetId([
        'company_id' => $this->company, 'attribute_definition_id' => $definitionB, 'label' => 'Variant Option B', 'code' => 'variant_option_b', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc47', 'company_id' => $this->company, 'name' => 'Variant Multi Product', 'slug' => 'variant-multi-product', 'slug_normalized' => 'variant-multi-product', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $variantId = DB::table('product_variants')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd31', 'company_id' => $this->company, 'product_id' => $productId, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $valueId = DB::table('variant_attribute_values')->insertGetId([
        'company_id' => $this->company, 'product_variant_id' => $variantId, 'attribute_definition_id' => $definitionA, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('variant_attribute_value_options')->insert([
        'company_id' => $this->company,
        'attribute_definition_id' => $definitionB,
        'variant_attribute_value_id' => $valueId,
        'attribute_option_id' => $optionB,
        'created_at' => now(),
    ]);
})->group('catalog-attribute');

test('variant multiselect accepts an option from its definition', function () {
    $definitionId = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee41', 'company_id' => $this->company, 'name' => 'Variant Multi Valid', 'code' => 'variant_multi_valid', 'type' => 'multiselect', 'scope' => 'variant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $optionId = DB::table('attribute_options')->insertGetId([
        'company_id' => $this->company, 'attribute_definition_id' => $definitionId, 'label' => 'Variant Valid', 'code' => 'variant_valid', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc48', 'company_id' => $this->company, 'name' => 'Variant Multi Valid Product', 'slug' => 'variant-multi-valid-product', 'slug_normalized' => 'variant-multi-valid-product', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $variantId = DB::table('product_variants')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd35', 'company_id' => $this->company, 'product_id' => $productId, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $valueId = DB::table('variant_attribute_values')->insertGetId([
        'company_id' => $this->company, 'product_variant_id' => $variantId, 'attribute_definition_id' => $definitionId, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('variant_attribute_value_options')->insert([
        'company_id' => $this->company,
        'attribute_definition_id' => $definitionId,
        'variant_attribute_value_id' => $valueId,
        'attribute_option_id' => $optionId,
        'created_at' => now(),
    ]))->toBeTrue();
})->group('catalog-attribute');
