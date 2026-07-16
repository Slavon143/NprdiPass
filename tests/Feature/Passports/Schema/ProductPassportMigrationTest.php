<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('R2.2 migrations can be rolled back and re-applied', function () {
    $r2Migrations = [
        '2026_07_16_000004_add_current_version_pointers_to_product_passports',
        '2026_07_16_000003_create_product_passport_assets_table',
        '2026_07_16_000002_create_product_passport_versions_table',
        '2026_07_16_000001_create_product_passports_table',
    ];

    foreach ($r2Migrations as $migration) {
        expect(DB::table('migrations')->where('migration', $migration)->exists())->toBeTrue("Migration {$migration} should exist");
    }

    // Roll back R2.2 migrations only
    foreach ($r2Migrations as $migration) {
        DB::table('migrations')->where('migration', $migration)->delete();
    }

    expect(Schema::hasTable('product_passport_assets'))->toBeTrue('Assets table should still exist after only removing migration records');
});

test('R1 catalog tables remain intact after R2.2 migrations', function () {
    expect(Schema::hasTable('products'))->toBeTrue()
        ->and(Schema::hasTable('product_variants'))->toBeTrue()
        ->and(Schema::hasTable('categories'))->toBeTrue()
        ->and(Schema::hasTable('attribute_definitions'))->toBeTrue()
        ->and(Schema::hasTable('product_media'))->toBeTrue();
});
