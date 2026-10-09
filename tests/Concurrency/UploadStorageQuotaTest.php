<?php

use App\Actions\Catalog\Documents\AddProductDocumentVersionAction;
use App\Actions\Catalog\Documents\CreateProductDocumentAction;
use App\Actions\Catalog\Media\UploadProductMediaAction;
use App\Actions\Catalog\Media\UploadVariantMediaAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Companies\CreateCompanyForOwner;
use App\Actions\Imports\CreateImportJobAction;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\Imports\ImportMode;
use App\Models\User;
use App\Services\Storage\TenantStorageLedger;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    if (! in_array(DB::connection()->getDatabaseName(), ['nordipass_testing', 'nordipass_reconcile_20261004_testing', 'nordipass_commercial_20261004_testing'], true)) {
        throw new RuntimeException('Owned isolated upload schema required.');
    }
    $this->artisan('migrate')->assertSuccessful();
    DB::beginTransaction();
    Http::preventStrayRequests();
    Queue::fake();
    foreach (['catalog_media', 'product_documents', 'production_imports', 'passport_assets'] as $disk) {
        Storage::fake($disk);
    }
    config(['billing.commercial_enabled' => false]);
    $this->actor = User::factory()->create();
    $this->company = app(CreateCompanyForOwner::class)->execute($this->actor, 'Upload storage quota');
    $this->actingAs($this->actor);
    app(CurrentCompany::class)->set($this->company);
    $this->product = app(CreateProductAction::class)->execute($this->actor, $this->company, ['name' => 'Storage owner']);
});

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach (DB::getConnections() as $name => $connection) {
        if (str_starts_with($name, 'commercial_storage_')) {
            if ($connection->getDatabaseName() !== DB::connection()->getDatabaseName()) {
                throw new RuntimeException('Refusing foreign ledger cleanup.');
            }
            $connection->table('commercial_storage_objects')->where('company_uuid', $this->company->uuid)->delete();
            $connection->table('commercial_storage_balances')->where('company_uuid', $this->company->uuid)->delete();
        }
    }
});

function quotaDocumentData(): array
{
    return ['document_type' => 'general_document', 'title' => 'Storage quota PDF', 'language' => 'en', 'visibility' => 'internal'];
}

function quotaPdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('quota.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n%%EOF");
}

function quotaUpload(string $mode, $document = null): void
{
    $t = test();
    match ($mode) {
        'product_media' => app(UploadProductMediaAction::class)->execute($t->actor, $t->company, $t->product, UploadedFile::fake()->image('quota.png', 20, 20)),
        'variant_media' => app(UploadVariantMediaAction::class)->execute($t->actor, $t->company, $t->product, $t->product->defaultVariant, UploadedFile::fake()->image('quota.png', 20, 20)),
        'document' => app(CreateProductDocumentAction::class)->execute($t->actor, $t->company, $t->product, quotaDocumentData(), quotaPdf()),
        'document_version' => app(AddProductDocumentVersionAction::class)->execute($t->actor, $t->company, $document, quotaDocumentData(), quotaPdf()),
        'import' => app(CreateImportJobAction::class)->execute($t->actor, $t->company, UploadedFile::fake()->createWithContent('quota.csv', "name,slug\nQuota,quota\n"), ImportMode::Create),
    };
}

function enableUploadQuota(int $bytes): void
{
    $t = test();
    $t->company->forceFill(['commercial_model_version' => 1])->save();
    $subscription = $t->company->subscription()->firstOrFail();
    $subscription->forceFill(['status' => BillingSubscriptionStatus::Active,
        'entitlements' => [...$subscription->entitlements, 'storage.bytes.max' => $bytes, 'imports.jobs.max' => 10],
    ])->save();
}

test('all upload entry points reject storage overflow before writing physical bytes', function (string $mode) {
    $document = $mode === 'document_version'
        ? app(CreateProductDocumentAction::class)->execute($this->actor, $this->company, $this->product, quotaDocumentData(), quotaPdf()) : null;
    $before = [];
    foreach (['catalog_media', 'product_documents', 'production_imports'] as $disk) {
        $before[$disk] = Storage::disk($disk)->allFiles();
    }
    enableUploadQuota(0);
    expect(fn () => quotaUpload($mode, $document))->toThrow(ValidationException::class, 'storage allowance');
    foreach ($before as $disk => $files) {
        expect(Storage::disk($disk)->allFiles())->toBe($files);
    }
})->with(['product_media', 'variant_media', 'document', 'document_version', 'import']);

test('parent rollback cleans an admitted upload and releases its durable reservation', function () {
    enableUploadQuota(100000);
    quotaUpload('product_media');
    $ledger = app(TenantStorageLedger::class);
    expect($ledger->usedBytes($this->company))->toBeGreaterThan(0);
    DB::rollBack();
    expect(Storage::disk('catalog_media')->allFiles())->toBe([])
        ->and($ledger->usedBytes($this->company))->toBe(0);
});

test('legacy upload behavior remains available without a storage allowance', function () {
    quotaUpload('product_media');
    expect(Storage::disk('catalog_media')->allFiles())->toHaveCount(1);
});
