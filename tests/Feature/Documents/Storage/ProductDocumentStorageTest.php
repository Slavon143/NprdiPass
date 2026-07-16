<?php

use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\Documents\DocumentFileStorage;
use App\Services\Catalog\Documents\PdfDocumentValidator;
use App\Services\Catalog\Documents\ValidatedPdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('Document storage', function () {
    beforeEach(function () {
        Storage::fake('product_documents');
    });

    test('storage key is relative and safe', function () {
        $storage = app(DocumentFileStorage::class);

        $company = Company::factory()->create();
        $product = Product::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'name' => 'Test',
            'slug' => 'test-'.fake()->unique()->slug(1),
            'slug_normalized' => fake()->unique()->slug(1),
            'status' => ProductStatus::Active->value,
            'created_by' => User::factory()->create()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $key = $storage->buildStorageKey($company, $product, fake()->uuid(), fake()->uuid());

        expect($key)->not->toStartWith('/');
        expect($key)->not->toStartWith('\\');
        expect($key)->not->toContain('\\');
        expect($key)->not->toContain('../');
        expect($key)->toContain('companies/');
        expect($key)->toEndWith('.pdf');
    });

    test('path traversal is rejected', function () {
        $storage = app(DocumentFileStorage::class);

        expect(fn () => $storage->assertSafeRelative('/etc/passwd'))
            ->toThrow(RuntimeException::class);

        expect(fn () => $storage->assertSafeRelative('../../etc/passwd'))
            ->toThrow(RuntimeException::class);

        expect(fn () => $storage->assertSafeRelative('test\\windows\\path.pdf'))
            ->toThrow(RuntimeException::class);
    });

    test('pdf validator rejects non-pdf content', function () {
        $validator = app(PdfDocumentValidator::class);

        $file = UploadedFile::fake()->create('test.txt', 100);

        expect(fn () => $validator->validate($file))
            ->toThrow(Exception::class);
    });

    test('pdf validator accepts valid pdf', function () {
        $validator = app(PdfDocumentValidator::class);
        $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";
        $file = UploadedFile::fake()->createWithContent('valid.pdf', $pdfContent);

        $result = $validator->validate($file);

        expect($result)->toBeInstanceOf(ValidatedPdf::class);
        expect($result->mimeType)->toBe('application/pdf');
        expect($result->extension)->toBe('pdf');
        expect($result->checksum)->toHaveLength(64);
    });

    test('pdf validator rejects empty file', function () {
        $validator = app(PdfDocumentValidator::class);
        $file = UploadedFile::fake()->create('empty.pdf', 0);

        expect(fn () => $validator->validate($file))
            ->toThrow(Exception::class);
    });

    test('write and verify checksum', function () {
        $storage = app(DocumentFileStorage::class);

        $company = Company::factory()->create();
        $product = Product::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'name' => 'Test',
            'slug' => 'test-'.fake()->unique()->slug(1),
            'slug_normalized' => fake()->unique()->slug(1),
            'status' => ProductStatus::Active->value,
            'created_by' => User::factory()->create()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $key = $storage->buildStorageKey($company, $product, fake()->uuid(), fake()->uuid());

        $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";
        $tmpPath = sys_get_temp_dir().'/nordipass-test-'.fake()->uuid().'.pdf';
        file_put_contents($tmpPath, $pdfContent);

        $checksum = hash_file('sha256', $tmpPath);

        $validated = new ValidatedPdf(
            temporaryPath: $tmpPath,
            originalFilename: 'test.pdf',
            mimeType: 'application/pdf',
            extension: 'pdf',
            sizeBytes: strlen($pdfContent),
            checksum: $checksum,
        );

        $storage->put($key, $validated);
        $storage->disk()->assertExists($key);
        $storage->assertReadable($key);
        $storage->verifyChecksum($key, $checksum);

        // Clean up
        $storage->delete($key);
        @unlink($tmpPath);
    });
});
