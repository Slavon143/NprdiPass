<?php

use App\Enums\Catalog\ProductStatus;
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\User;

describe('ProductDocument model', function () {
    test('ProductDocument has correct casts', function () {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $product = Product::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'slug_normalized' => 'test-product',
            'status' => ProductStatus::Active->value,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $document = ProductDocument::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => ProductDocumentStatus::Active->value,
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect($document->status)->toBeInstanceOf(ProductDocumentStatus::class);
    });

    test('ProductDocument isActive returns correct value', function () {
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

        $active = ProductDocument::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => ProductDocumentStatus::Active->value,
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect($active->isActive())->toBeTrue();
        expect($active->isArchived())->toBeFalse();
    });

    test('ProductDocumentVersion casts enums correctly', function () {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $version = new ProductDocumentVersion;
        $version->forceFill([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'document_id' => 1,
            'version_number' => 1,
            'document_type' => ProductDocumentType::Certificate->value,
            'title' => 'Test',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'storage_key' => 'test/key.pdf',
            'created_by_user_id' => $user->id,
        ]);

        expect($version->document_type)->toBeInstanceOf(ProductDocumentType::class);
        expect($version->visibility)->toBeInstanceOf(ProductDocumentVisibility::class);
    });

    test('ProductDocumentVersion isExpired works correctly', function () {
        $version = new ProductDocumentVersion;
        $version->document_type = ProductDocumentType::Certificate;
        $version->visibility = ProductDocumentVisibility::Internal;

        $version->expires_at = now()->subDay();
        expect($version->isExpired())->toBeTrue();

        $version->expires_at = now()->addDay();
        expect($version->isExpired())->toBeFalse();

        $version->expires_at = null;
        expect($version->isExpired())->toBeFalse();
    });

    test('ProductDocumentVersion expiresSoon works correctly', function () {
        $version = new ProductDocumentVersion;
        $version->document_type = ProductDocumentType::Certificate;
        $version->visibility = ProductDocumentVisibility::Internal;

        $version->expires_at = now()->addDays(5);
        expect($version->expiresSoon(30))->toBeTrue();

        $version->expires_at = now()->addDays(60);
        expect($version->expiresSoon(30))->toBeFalse();

        $version->expires_at = now()->subDay();
        expect($version->expiresSoon(30))->toBeFalse();
    });
});

describe('ProductDocument enums', function () {
    test('ProductDocumentStatus has correct values', function () {
        expect(ProductDocumentStatus::Active->value)->toBe('active');
        expect(ProductDocumentStatus::Archived->value)->toBe('archived');
    });

    test('ProductDocumentType has at least 8 values', function () {
        $cases = ProductDocumentType::cases();
        expect(count($cases))->toBeGreaterThanOrEqual(8);
    });

    test('ProductDocumentType has label for each case', function () {
        foreach (ProductDocumentType::cases() as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
        }
    });

    test('ProductDocumentVisibility has correct values', function () {
        expect(ProductDocumentVisibility::Internal->value)->toBe('internal');
        expect(ProductDocumentVisibility::PassportPublic->value)->toBe('passport_public');
    });
});
