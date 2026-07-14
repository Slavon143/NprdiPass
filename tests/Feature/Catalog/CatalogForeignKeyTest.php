<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Catalog constraint tests require MySQL 8.');
    }
});

test('catalog constraints are enforced at database level', function () {
    $expected = [
        'products_primary_category_foreign' => [
            ['company_id', 'categories', 'company_id'],
            ['primary_category_id', 'categories', 'id'],
        ],
        'products_default_variant_foreign' => [
            ['company_id', 'product_variants', 'company_id'],
            ['id', 'product_variants', 'product_id'],
            ['default_variant_id', 'product_variants', 'id'],
        ],
        'products_primary_media_foreign' => [
            ['company_id', 'product_media', 'company_id'],
            ['id', 'product_media', 'product_id'],
            ['primary_media_id', 'product_media', 'id'],
        ],
        'variants_primary_media_foreign' => [
            ['company_id', 'product_media', 'company_id'],
            ['product_id', 'product_media', 'product_id'],
            ['id', 'product_media', 'product_variant_id'],
            ['primary_media_id', 'product_media', 'id'],
        ],
        'product_attr_value_opts_value_foreign' => [
            ['company_id', 'product_attribute_values', 'company_id'],
            ['attribute_definition_id', 'product_attribute_values', 'attribute_definition_id'],
            ['product_attribute_value_id', 'product_attribute_values', 'id'],
        ],
        'variant_attr_value_opts_value_foreign' => [
            ['company_id', 'variant_attribute_values', 'company_id'],
            ['attribute_definition_id', 'variant_attribute_values', 'attribute_definition_id'],
            ['variant_attribute_value_id', 'variant_attribute_values', 'id'],
        ],
    ];

    foreach ($expected as $constraint => $columns) {
        $actual = collect(DB::select(<<<'SQL'
            SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND CONSTRAINT_NAME = ?
            ORDER BY ORDINAL_POSITION
        SQL, [$constraint]))->map(static fn (object $row): array => [
            $row->COLUMN_NAME,
            $row->REFERENCED_TABLE_NAME,
            $row->REFERENCED_COLUMN_NAME,
        ])->all();

        expect($actual)->toBe($columns, "Unexpected columns for {$constraint}");
    }
});

test('all catalog tables have company_id foreign key to companies', function () {
    $tables = [
        'categories', 'products', 'product_variants', 'category_product',
        'attribute_definitions', 'attribute_options',
        'product_attribute_values', 'variant_attribute_values',
        'product_attribute_value_options', 'variant_attribute_value_options',
        'product_media',
    ];

    foreach ($tables as $table) {
        $exists = DB::selectOne("
            SELECT COUNT(*) as cnt
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = 'company_id'
              AND REFERENCED_TABLE_NAME = 'companies'
        ", [$table]);

        expect((int) $exists->cnt)->toBeGreaterThan(0, "Table '{$table}' must have company_id FK to companies");
    }
});

test('unique constraints are enforced at database level', function () {
    $indexes = [
        ['categories', 'categories_company_slug_unique'],
        ['products', 'products_company_slug_unique'],
        ['product_variants', 'variants_company_sku_unique'],
        ['product_variants', 'variants_company_gtin_unique'],
        ['attribute_definitions', 'attr_defs_company_code_unique'],
        ['attribute_options', 'attr_options_company_definition_code_unique'],
        ['category_product', 'category_product_unique'],
        ['product_attribute_values', 'product_attr_values_entity_def_unique'],
        ['variant_attribute_values', 'variant_attr_values_entity_def_unique'],
    ];

    foreach ($indexes as [$table, $index]) {
        $actual = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS aggregate
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
              AND NON_UNIQUE = 0
        SQL, [$table, $index]);

        expect((int) $actual->aggregate)->toBeGreaterThan(0, "Missing unique index {$index}");
    }
});

test('composite unique keys support foreign key referencing', function () {
    $indexes = [
        ['product_variants', 'variants_company_product_id_unique'],
        ['product_attribute_values', 'product_attr_values_company_def_id_unique'],
        ['variant_attribute_values', 'variant_attr_values_company_def_id_unique'],
        ['product_media', 'media_company_product_id_unique'],
        ['product_media', 'media_company_product_variant_id_unique'],
    ];

    foreach ($indexes as [$table, $index]) {
        $actual = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS aggregate
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
              AND NON_UNIQUE = 0
        SQL, [$table, $index]);

        expect((int) $actual->aggregate)->toBeGreaterThan(0, "Missing FK support key {$index}");
    }
});

test('status and data type CHECK constraints are active', function () {
    $constraints = [
        'categories_depth_check',
        'categories_status_check',
        'products_status_check',
        'variants_gtin_format_check',
        'variants_status_check',
        'attr_defs_type_check',
        'attr_defs_scope_check',
        'product_attr_values_one_value_check',
        'variant_attr_values_one_value_check',
        'media_checksum_format_check',
        'media_size_check',
    ];

    foreach ($constraints as $constraint) {
        $actual = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS aggregate
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND CONSTRAINT_NAME = ?
              AND CONSTRAINT_TYPE = 'CHECK'
        SQL, [$constraint]);

        expect((int) $actual->aggregate)->toBe(1, "Missing CHECK constraint {$constraint}");
    }
});

test('category self-parent protection triggers are installed', function () {
    $triggerNames = DB::table('information_schema.TRIGGERS')
        ->where('TRIGGER_SCHEMA', DB::raw('DATABASE()'))
        ->whereIn('TRIGGER_NAME', [
            'categories_prevent_self_parent_insert',
            'categories_prevent_self_parent_update',
        ])
        ->pluck('TRIGGER_NAME')
        ->all();

    expect($triggerNames)->toContain('categories_prevent_self_parent_insert')
        ->and($triggerNames)->toContain('categories_prevent_self_parent_update');
});
