<?php

use App\Actions\Catalog\Lifecycle\ArchiveProductAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Imports\ApplyImportJobAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Actions\Imports\PreviewImportJobAction;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\Imports\ImportMode;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function sourceMetadataContext(): array
{
    Storage::fake('production_imports');
    Http::preventStrayRequests();
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->create(['user_id' => $actor->id, 'company_id' => $company->id, 'role' => CompanyRole::Owner]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company];
}

test('source metadata remains evidence without creating lifecycle or remote document side effects', function (ImportMode $mode) {
    [$actor, $company] = sourceMetadataContext();
    test()->travelTo(now()->startOfSecond());
    $timestamp = now()->toDateTimeString();
    if ($mode === ImportMode::Update) {
        $existing = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Existing', 'slug' => 'one']);
        // Existing active catalogue state is a fixture, not an import transition.
        $existing->forceFill(['status' => ProductStatus::Active])->save();
    }
    $file = UploadedFile::fake()->createWithContent('source-metadata.csv',
        "name,slug,status,updated_at,document_ref\nChanged,one,archived,2000-01-01T00:00:00Z,http://169.254.169.254/latest/meta-data/");
    $job = app(CreateImportJobAction::class)->execute($actor, $company, $file, $mode);
    $job = app(PreviewImportJobAction::class)->execute($actor, $company, $job, ['name' => 'name', 'slug' => 'slug']);
    $record = $job->records()->sole();
    expect($record->source_data['status'])->toBe('archived')
        ->and($record->source_data['updated_at'])->toBe('2000-01-01T00:00:00Z')
        ->and($record->source_data['document_ref'])->toBe('http://169.254.169.254/latest/meta-data/')
        ->and($record->mapped_data)->not->toHaveKeys(['status', 'updated_at', 'document_ref']);
    $source = $record->source_data;
    $job = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    $product = $company->products()->sole();
    expect($job->skipped_rows)->toBe(0)->and($product->name)->toBe('Changed')
        ->and($product->status)->toBe($mode === ImportMode::Update ? ProductStatus::Active : ProductStatus::Draft)
        ->and($product->updated_at->toDateTimeString())->toBe($timestamp)
        ->and($record->fresh()->source_data)->toBe($source)
        ->and(ProductDocument::query()->forCompany($company)->count())->toBe(0)
        ->and(ProductDocumentVersion::query()->forCompany($company)->count())->toBe(0);
    Http::assertNothingSent();
})->with([ImportMode::Create, ImportMode::Update]);

test('source status cannot restore or modify an archived product through import', function () {
    [$actor, $company] = sourceMetadataContext();
    $product = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Archived original', 'slug' => 'one']);
    app(ArchiveProductAction::class)->execute($actor, $company, $product);
    $job = app(CreateImportJobAction::class)->execute($actor, $company,
        UploadedFile::fake()->createWithContent('restore.csv', "name,slug,status\nChanged,one,active"), ImportMode::Update);
    $job = app(PreviewImportJobAction::class)->execute($actor, $company, $job, ['name' => 'name', 'slug' => 'slug']);
    $job = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    expect($job->updated_rows)->toBe(0)->and($job->skipped_rows)->toBe(1)
        ->and($product->fresh()->status)->toBe(ProductStatus::Archived)->and($product->fresh()->name)->toBe('Archived original');
});

test('preview explicitly lists unconsumed columns and escapes their names', function () {
    [$actor, $company] = sourceMetadataContext();
    $job = app(CreateImportJobAction::class)->execute($actor, $company,
        UploadedFile::fake()->createWithContent('unmapped.csv', "name,slug,status,updated_at,document_ref,<script>alert(1)</script>\nOne,one,active,2000-01-01,file.pdf,extra"), ImportMode::Create);
    $job = app(PreviewImportJobAction::class)->execute($actor, $company, $job, ['name' => 'name', 'slug' => 'slug']);
    $response = test()->get(route('catalog.imports.show', $job))->assertOk()
        ->assertSee('Source columns not applied by this mapping')
        ->assertSee('Source updated_at does not replace')
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
    $html = $response->getContent();
    preg_match('/data-testid="import-unmapped-columns">(.*?)<\/div>/s', $html, $matches);
    expect($matches[1])->toContain('<li>status</li>', '<li>updated_at</li>', '<li>document_ref</li>')
        ->not->toContain('<li>name</li>', '<li>slug</li>');
});
