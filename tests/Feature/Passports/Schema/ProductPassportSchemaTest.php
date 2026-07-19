<?php

use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportAsset;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

beforeEach(function () {
    DB::statement('SET FOREIGN_KEY_CHECKS=0');

    ProductPassportAsset::query()->delete();
    ProductPassportVersion::query()->delete();
    ProductPassport::query()->delete();

    DB::statement('SET FOREIGN_KEY_CHECKS=1');
});

test('product_passports table exists with all required columns', function () {
    $columns = DB::select('SHOW COLUMNS FROM product_passports');
    $columnNames = array_column($columns, 'Field');

    expect($columnNames)->toContain(
        'id', 'uuid', 'public_id', 'company_id', 'product_id',
        'status', 'default_language', 'enabled_languages',
        'current_draft_version_id', 'current_published_version_id',
        'first_published_at', 'last_published_at', 'unpublished_at', 'archived_at',
        'created_by', 'updated_by', 'created_at', 'updated_at',
    );
});

test('product_passport_versions table exists with all required columns', function () {
    $columns = DB::select('SHOW COLUMNS FROM product_passport_versions');
    $columnNames = array_column($columns, 'Field');

    expect($columnNames)->toContain(
        'id', 'uuid', 'company_id', 'passport_id',
        'status', 'version_number', 'draft_revision', 'schema_version',
        'payload', 'content_checksum', 'published_at', 'published_by',
        'superseded_at', 'withdrawn_at', 'created_by', 'updated_by',
        'created_at', 'updated_at',
    );
});

test('product_passport_assets table exists with all required columns', function () {
    $columns = DB::select('SHOW COLUMNS FROM product_passport_assets');
    $columnNames = array_column($columns, 'Field');

    expect($columnNames)->toContain(
        'id', 'uuid', 'company_id', 'passport_id', 'version_id',
        'kind', 'source_resource_uuid', 'role', 'sort_order',
        'language', 'mime_type', 'file_extension', 'size_bytes',
        'width', 'height', 'checksum_sha256', 'storage_key',
        'is_public', 'created_at', 'updated_at',
    );
});

test('all passport unique constraints exist', function () {
    $indexes = DB::select('SHOW INDEX FROM product_passports');

    $uniqueIndexes = collect($indexes)->filter(fn ($i) => $i->Key_name !== 'PRIMARY' && $i->Non_unique == 0)->pluck('Key_name')->unique();

    expect($uniqueIndexes->toArray())->toContain(
        'product_passports_company_product_unique',
        'product_passports_uuid_unique',
        'product_passports_public_id_unique',
        'product_passports_company_id_unique',
    );
});

test('enabled languages must be a non-empty JSON array containing the default language', function () {
    $checks = collect(DB::select(<<<'SQL'
        SELECT CONSTRAINT_NAME
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'product_passports'
          AND CONSTRAINT_TYPE = 'CHECK'
        SQL))->pluck('CONSTRAINT_NAME');

    expect($checks)->toContain('product_passports_enabled_languages_check');
});

test('all version unique constraints exist', function () {
    $indexes = DB::select('SHOW INDEX FROM product_passport_versions');

    $uniqueIndexes = collect($indexes)->filter(fn ($i) => $i->Key_name !== 'PRIMARY' && $i->Non_unique == 0)->pluck('Key_name')->unique();

    expect($uniqueIndexes->toArray())->toContain(
        'product_passport_versions_uuid_unique',
        'product_passport_versions_passport_version_unique',
        'product_passport_versions_company_passport_id_unique',
        'product_passport_versions_active_draft_unique',
    );
});

test('passport composite FK prevents cross-Company Product', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $productB = Product::query()->forceCreate([
        'uuid' => Uuid::uuid7()->toString(),
        'company_id' => $companyB->id,
        'name' => 'Test Product B',
        'slug' => 'test-product-b-'.str()->random(5),
        'slug_normalized' => 'test-product-b-'.str()->random(5),
        'status' => ProductStatus::Active->value,
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    $createdBy = User::factory()->create();
    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    ProductPassport::query()->insert([
        'uuid' => Uuid::uuid7()->toString(),
        'public_id' => Uuid::uuid7()->toString(),
        'company_id' => $companyA->id,
        'product_id' => $productB->id,
        'status' => 'draft',
        'default_language' => 'sv',
        'enabled_languages' => json_encode(['sv', 'en']),
        'created_by' => $createdBy->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    ProductPassport::query()->where('product_id', $productB->id)->delete();
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
});
