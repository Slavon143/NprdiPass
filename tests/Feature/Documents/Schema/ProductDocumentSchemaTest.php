<?php

use App\Enums\Catalog\ProductStatus;
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

describe('ProductDocument schema', function () {
    test('product_documents table exists', function () {
        expect(Schema::hasTable('product_documents'))->toBeTrue();
    });

    test('product_document_versions table exists', function () {
        expect(Schema::hasTable('product_document_versions'))->toBeTrue();
    });

    test('product_documents has required columns', function () {
        $columns = Schema::getColumnListing('product_documents');
        expect($columns)->toContain('id', 'uuid', 'company_id', 'product_id', 'status',
            'current_version_id', 'created_by_user_id', 'updated_by_user_id',
            'archived_at', 'created_at', 'updated_at');
    });

    test('product_document_versions has required columns', function () {
        $columns = Schema::getColumnListing('product_document_versions');
        expect($columns)->toContain('id', 'uuid', 'company_id', 'document_id', 'version_number',
            'document_type', 'title', 'description', 'language', 'visibility',
            'issuer_name', 'issue_date', 'expires_at', 'original_filename',
            'mime_type', 'file_extension', 'size_bytes', 'checksum_sha256',
            'storage_key', 'created_by_user_id', 'created_at', 'updated_at');
    });

    test('product_documents status CHECK constraint enforces valid values', function () {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $product = Product::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'name' => 'Test Product',
            'slug' => 'test-product-'.fake()->unique()->slug(1),
            'slug_normalized' => fake()->unique()->slug(1),
            'status' => ProductStatus::Active->value,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_documents')->insert([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => ProductDocumentStatus::Active->value,
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => DB::table('product_documents')->insert([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => 'invalid_status',
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    test('product_document_versions are immutable via trigger', function () {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $product = Product::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'name' => 'Test Product',
            'slug' => 'test-product-'.fake()->unique()->slug(1),
            'slug_normalized' => fake()->unique()->slug(1),
            'status' => ProductStatus::Active->value,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $documentId = DB::table('product_documents')->insertGetId([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => ProductDocumentStatus::Active->value,
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versionId = DB::table('product_document_versions')->insertGetId([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'document_id' => $documentId,
            'version_number' => 1,
            'document_type' => ProductDocumentType::Instruction->value,
            'title' => 'Test',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'storage_key' => 'test/key'.fake()->uuid().'.pdf',
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => DB::table('product_document_versions')->where('id', $versionId)->update(['title' => 'Modified']))
            ->toThrow(QueryException::class);
    });

    test('product_document_versions version_number unique per document', function () {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $product = Product::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'name' => 'Test Product',
            'slug' => 'test-product-'.fake()->unique()->slug(1),
            'slug_normalized' => fake()->unique()->slug(1),
            'status' => ProductStatus::Active->value,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $documentId = DB::table('product_documents')->insertGetId([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => ProductDocumentStatus::Active->value,
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_document_versions')->insert([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'document_id' => $documentId,
            'version_number' => 1,
            'document_type' => ProductDocumentType::Instruction->value,
            'title' => 'Test',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'storage_key' => 'test/key1'.fake()->uuid().'.pdf',
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => DB::table('product_document_versions')->insert([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'document_id' => $documentId,
            'version_number' => 1,
            'document_type' => ProductDocumentType::Instruction->value,
            'title' => 'Test 2',
            'language' => 'en',
            'visibility' => ProductDocumentVisibility::Internal->value,
            'original_filename' => 'test2.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 2048,
            'checksum_sha256' => str_repeat('b', 64),
            'storage_key' => 'test/key2'.fake()->uuid().'.pdf',
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});
