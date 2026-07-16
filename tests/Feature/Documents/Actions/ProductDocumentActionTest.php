<?php

use App\Actions\Catalog\Documents\ArchiveProductDocumentAction;
use App\Actions\Catalog\Documents\CreateProductDocumentAction;
use App\Actions\Catalog\Documents\RestoreProductDocumentAction;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Models\AuditLog;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('Document actions', function () {
    beforeEach(function () {
        Storage::fake('product_documents');
    });

    test('create document stores file and produces audit event', function () {
        $company = Company::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $membership = CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => CompanyRole::Owner,
        ]);

        test()->actingAs($user);
        app(CurrentCompany::class)->set($company);

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

        $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n%%EOF";
        $file = UploadedFile::fake()->createWithContent('test.pdf', $pdfContent);

        $action = app(CreateProductDocumentAction::class);
        $document = $action->execute($user, $company, $product, [
            'document_type' => ProductDocumentType::Instruction->value,
            'title' => 'Test Document',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
        ], $file);

        expect($document)->toBeInstanceOf(ProductDocument::class);
        expect($document->status)->toBe(ProductDocumentStatus::Active);
        expect($document->currentVersion)->not->toBeNull();
        expect($document->currentVersion->version_number)->toBe(1);
        expect($document->currentVersion->mime_type)->toBe('application/pdf');
        expect($document->currentVersion->checksum_sha256)->toHaveLength(64);

        Storage::disk('product_documents')->assertExists($document->currentVersion->storage_key);

        $audit = AuditLog::query()
            ->where('event', AuditEvent::CatalogDocumentCreated->value)
            ->where('company_id', $company->id)
            ->first();
        expect($audit)->not->toBeNull();
    });

    test('archive and restore document works', function () {
        $company = Company::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);
        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => CompanyRole::Owner,
        ]);

        test()->actingAs($user);
        app(CurrentCompany::class)->set($company);

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

        $version = new ProductDocumentVersion;
        $version->forceFill([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'document_id' => 0,
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
            'storage_key' => 'test/archive-'.fake()->uuid().'.pdf',
            'created_by_user_id' => $user->id,
        ]);

        $document = ProductDocument::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => ProductDocumentStatus::Active->value,
            'current_version_id' => null,
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $version->document_id = $document->getKey();
        $version->save();
        $document->forceFill(['current_version_id' => $version->getKey()])->save();

        $archiveAction = app(ArchiveProductDocumentAction::class);
        $archived = $archiveAction->execute($user, $company, $document);

        expect($archived->isArchived())->toBeTrue();
        expect($archived->archived_at)->not->toBeNull();

        $restoreAction = app(RestoreProductDocumentAction::class);
        $restored = $restoreAction->execute($user, $company, $archived);

        expect($restored->isActive())->toBeTrue();
        expect($restored->archived_at)->toBeNull();
    });

    test('create document rejects non-pdf file', function () {
        $company = Company::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);
        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => CompanyRole::Owner,
        ]);
        test()->actingAs($user);
        app(CurrentCompany::class)->set($company);

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

        $file = UploadedFile::fake()->create('not-a-pdf.txt', 100);

        $action = app(CreateProductDocumentAction::class);

        expect(fn () => $action->execute($user, $company, $product, [
            'document_type' => ProductDocumentType::Instruction->value,
            'title' => 'Test',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
        ], $file))->toThrow(Exception::class);
    });
});
