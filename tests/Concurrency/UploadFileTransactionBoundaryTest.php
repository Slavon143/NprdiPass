<?php

use App\Actions\Catalog\Documents\AddProductDocumentVersionAction;
use App\Actions\Catalog\Documents\CreateProductDocumentAction;
use App\Actions\Catalog\Media\UploadProductMediaAction;
use App\Actions\Catalog\Media\UploadVariantMediaAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Companies\CreateCompanyForOwner;
use App\Actions\Imports\CreateImportJobAction;
use App\Enums\Imports\ImportMode;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Catalog\ProductMedia;
use App\Models\Imports\ImportJob;
use App\Models\User;
use App\Services\Catalog\Documents\DocumentFileStorage;
use App\Services\Catalog\Media\CatalogMediaStorage;
use App\Services\Storage\FileWriteCompensation;
use App\Support\Catalog\Media\MediaPathGuard;
use App\Support\Catalog\Media\ValidatedImage;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function uploadBoundaryFixture(): array
{
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toBeIn(['nordipass_testing', 'nordipass_commercial_20261004_testing']);
    test()->artisan('migrate:fresh')->assertSuccessful();
    Http::preventStrayRequests();
    Queue::fake();
    foreach (['catalog_media', 'product_documents', 'production_imports'] as $disk) {
        Storage::fake($disk);
    }
    $actor = User::factory()->create();
    $company = app(CreateCompanyForOwner::class)->execute($actor, 'Upload transaction boundary');
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $product = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'File owner']);

    return [$actor, $company, $product];
}

function uploadBoundaryPdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('boundary.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n%%EOF");
}

test('outer rollback cleans only the new upload and preserves previously committed files', function (string $mode) {
    [$actor, $company, $product] = uploadBoundaryFixture();
    $data = ['document_type' => 'general_document', 'title' => 'Boundary document', 'language' => 'en', 'visibility' => 'internal'];
    $document = $mode === 'document_version'
        ? app(CreateProductDocumentAction::class)->execute($actor, $company, $product, $data, uploadBoundaryPdf()) : null;
    $disk = str_starts_with($mode, 'document') ? 'product_documents' : ($mode === 'import' ? 'production_imports' : 'catalog_media');
    $existing = Storage::disk($disk)->allFiles();
    DB::beginTransaction();
    try {
        $record = match ($mode) {
            'product_media' => app(UploadProductMediaAction::class)->execute($actor, $company, $product, UploadedFile::fake()->image('boundary.png', 20, 20)),
            'variant_media' => app(UploadVariantMediaAction::class)->execute($actor, $company, $product, $product->defaultVariant, UploadedFile::fake()->image('boundary.png', 20, 20)),
            'document' => app(CreateProductDocumentAction::class)->execute($actor, $company, $product, $data, uploadBoundaryPdf())->currentVersion,
            'document_version' => app(AddProductDocumentVersionAction::class)->execute($actor, $company, $document, $data, uploadBoundaryPdf()),
            'import' => app(CreateImportJobAction::class)->execute($actor, $company, UploadedFile::fake()->createWithContent('boundary.csv', "name,slug\nImported,imported\n"), ImportMode::Create),
        };
        $path = $record instanceof ProductDocumentVersion ? $record->storage_key : ($record instanceof ImportJob ? $record->source_path : $record->storage_path);
        Storage::disk($disk)->assertExists($path);
    } finally {
        DB::rollBack();
    }
    Storage::disk($disk)->assertMissing($path);
    expect(Storage::disk($disk)->allFiles())->toBe($existing)
        ->and($record->newQuery()->whereKey($record->getKey())->exists())->toBeFalse();
})->with(['product_media', 'variant_media', 'document', 'document_version', 'import']);

test('failed media write and document checksum leave no unowned physical bytes', function (string $mode) {
    [$actor, $company, $product] = uploadBoundaryFixture();
    if ($mode === 'media') {
        app()->instance(CatalogMediaStorage::class, new class(app(MediaPathGuard::class)) extends CatalogMediaStorage
        {
            public function put(string $path, ValidatedImage $image): void
            {
                parent::put($path, $image);
                throw new RuntimeException('Injected write acknowledgement failure.');
            }
        });
        expect(fn () => app(UploadProductMediaAction::class)->execute($actor, $company, $product, UploadedFile::fake()->image('failed.png', 20, 20)))->toThrow(RuntimeException::class);
        expect(Storage::disk('catalog_media')->allFiles())->toBe([]);
    } else {
        app()->instance(DocumentFileStorage::class, new class extends DocumentFileStorage
        {
            public function verifyChecksum(string $storageKey, string $expectedChecksum): void
            {
                throw new RuntimeException('Injected checksum failure.');
            }
        });
        expect(fn () => app(CreateProductDocumentAction::class)->execute($actor, $company, $product,
            ['document_type' => 'general_document', 'title' => 'Failed PDF', 'language' => 'en', 'visibility' => 'internal'], uploadBoundaryPdf()))->toThrow(RuntimeException::class);
        expect(Storage::disk('product_documents')->allFiles())->toBe([]);
    }
})->with(['media', 'document']);

test('a postcommit exception cannot compensate a committed media record by deleting its file', function () {
    [$actor, $company, $product] = uploadBoundaryFixture();
    $injected = false;
    DB::listen(function ($query) use (&$injected): void {
        if (! $injected && str_starts_with($query->sql, 'insert into `product_media`')) {
            $injected = true;
            DB::afterCommit(fn () => throw new RuntimeException('Injected after-commit failure.'));
        }
    });
    expect(fn () => app(UploadProductMediaAction::class)->execute($actor, $company, $product, UploadedFile::fake()->image('committed.png', 20, 20)))
        ->toThrow(RuntimeException::class, 'Injected after-commit failure.');
    $media = ProductMedia::query()->where('company_id', $company->id)->sole();
    Storage::disk('catalog_media')->assertExists($media->storage_path);
    expect($injected)->toBeTrue()->and(DB::transactionLevel())->toBe(0);
});

test('unavailable database ownership state retains bytes for reconciliation', function () {
    $deleted = false;
    $cleanup = app(FileWriteCompensation::class)->track(
        fn (): bool => throw new RuntimeException('Unavailable ownership state.'),
        function () use (&$deleted): bool {
            $deleted = true;

            return true;
        },
        ['operation' => 'ownership_read_fixture'],
    );
    $cleanup();
    expect($deleted)->toBeFalse();
});

test('confirmed compensation is idempotent across ancestor callbacks', function () {
    $deleted = 0;
    $cleanup = app(FileWriteCompensation::class)->track(
        fn (): bool => false,
        function () use (&$deleted): bool {
            $deleted++;

            return true;
        },
        ['operation' => 'repeat_cleanup_fixture'],
    );
    $cleanup();
    $cleanup();
    expect($deleted)->toBe(1);
});
