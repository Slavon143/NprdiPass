<?php

use App\Actions\Catalog\Products\SetProductManagedAction;
use App\Actions\Companies\CreateCompanyForOwner;
use App\Actions\Imports\ApplyImportJobAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Actions\Imports\PreviewImportJobAction;
use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\RecordPassportValidationRun;
use App\Actions\Passports\UpdateProductPassportSettingsAction;
use App\Enums\Imports\ImportMode;
use App\Enums\Passports\Readiness\PassportReadinessStatus;
use App\Models\User;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Services\Passports\Readiness\StoredPassportReadiness;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('retained real supplier identities import and retain honest applicability and missing evidence', function () {
    // Three authentic identity records derived from unchanged manufacturer XLSX
    // files. Missing passport evidence is deliberately not fabricated.
    $samples = json_decode(file_get_contents(base_path('tests/Fixtures/Dpp/public-supplier-sample-20261008.json')), true, flags: JSON_THROW_ON_ERROR);
    config(['billing.commercial_enabled' => true, 'billing.plans' => config('billing.commercial_plans'), 'billing.provider' => 'fake']);
    Storage::fake('production_imports');
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = app(CreateCompanyForOwner::class)->execute($actor, 'Public supplier scope acceptance');
    app(CurrentCompany::class)->set($company);
    $stream = fopen('php://temp', 'w+');
    fputcsv($stream, ['name', 'slug', 'manufacturer', 'brand', 'mpn', 'gtin'], escape: '');
    foreach ($samples as $sample) {
        fputcsv($stream, [$sample['name'], strtolower($sample['manufacturer']).'-'.$sample['mpn'],
            $sample['manufacturer'], $sample['manufacturer'], $sample['mpn'], $sample['gtin']], escape: '');
    }
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);
    $job = app(CreateImportJobAction::class)->execute($actor, $company,
        UploadedFile::fake()->createWithContent('derived-public-supplier-sample.csv', $csv), ImportMode::Create);
    $job = app(PreviewImportJobAction::class)->execute($actor, $company, $job,
        array_combine(['name', 'slug', 'manufacturer', 'brand', 'mpn', 'gtin'], ['name', 'slug', 'manufacturer', 'brand', 'mpn', 'gtin']));
    $job = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    expect($job->created_rows)->toBe(3)->and($job->skipped_rows)->toBe(0);

    foreach ($samples as $sample) {
        $product = $company->products()->where('manufacturer', $sample['manufacturer'])->sole();
        expect($product->name)->toBe($sample['name'])->and($product->defaultVariant->mpn)->toBe($sample['mpn'])
            ->and($product->defaultVariant->gtin)->toBe($sample['gtin'])->and($product->managed)->toBeFalse();
        $product = app(SetProductManagedAction::class)->execute($actor, $company, $product, true);
        $passport = app(CreateProductPassportDraftAction::class)->handle($actor, $company, $product);
        $passport = app(UpdateProductPassportSettingsAction::class)->handle($actor, $company, $product, $passport,
            ['regulatory_context' => ['market' => 'EU', 'product_type' => $sample['product_type'], 'passport_level' => 'model',
                'model_identifier' => $sample['mpn'], 'assessment_source' => $sample['source_file'].' row 3 SHA-256 '.$sample['source_sha256']]], 1);
        $context = app(ReadinessContextBuilder::class)->build($company, $product->fresh());
        $readiness = app(PassportReadinessEvaluator::class)->evaluate($context);
        expect($readiness->profileDetails['regulatory_scope']['status'])->toBe('sector_assessment_required')
            ->and($readiness->profileDetails['regulatory_scope']['registry_required'])->toBeNull()
            ->and($readiness->status)->toBe(PassportReadinessStatus::NotReady);
        $run = app(RecordPassportValidationRun::class)->handle($company, $passport, $passport->currentDraftVersion, $readiness, $actor);
        expect(app(StoredPassportReadiness::class)->latest($company, $product)->profileDetails['regulatory_scope'])
            ->toEqual($run->profile_snapshot['profile_details']['regulatory_scope']);
        expect(fn () => app(PublishProductPassport::class)->handle($actor, $company, $product, $passport, 2, true))
            ->toThrow(ValidationException::class);
        expect($passport->fresh()->current_published_version_id)->toBeNull();
    }
});
