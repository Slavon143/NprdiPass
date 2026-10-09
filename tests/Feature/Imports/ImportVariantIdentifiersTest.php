<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Variants\CreateProductVariantAction;
use App\Actions\Imports\ApplyImportJobAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Actions\Imports\PreviewImportJobAction;
use App\Enums\CompanyRole;
use App\Enums\Imports\ImportMode;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Imports\ImportFileReader;
use App\Services\Imports\ProductExportService;
use App\Support\Catalog\GtinValidator;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

function identifierImportContext(): array
{
    Storage::fake('production_imports');
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->create(['user_id' => $actor->id, 'company_id' => $company->id, 'role' => CompanyRole::Owner]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company];
}

function identifierGtin(string $body = '0735100000000'): string
{
    return $body.app(GtinValidator::class)->calculateCheckDigit($body);
}

function identifierPreview(User $actor, Company $company, string $csv, array $mapping, ImportMode $mode = ImportMode::Create)
{
    $job = app(CreateImportJobAction::class)->execute($actor, $company, UploadedFile::fake()->createWithContent('identifiers.csv', $csv), $mode, chunkSize: 1);

    return app(PreviewImportJobAction::class)->execute($actor, $company, $job, $mapping);
}

test('native identifiers are preserved on default variants and survive CSV and XLSX export', function (string $format) {
    [$actor, $company] = identifierImportContext();
    $gtin = identifierGtin();
    $mapping = ['name' => 'name', 'slug' => 'slug', 'sku' => 'sku', 'gtin' => 'gtin', 'mpn' => 'mpn'];
    $job = identifierPreview($actor, $company, "name,slug,sku,gtin,mpn\nOne,one, 001-A ,{$gtin}, MP-01 ", $mapping);
    expect($job->valid_rows)->toBe(1);
    $job = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    $product = Product::query()->forCompany($company)->sole();
    $variant = $product->defaultVariant;
    expect($variant->sku)->toBe('001-A')->and($variant->sku_normalized)->toBe('001-A')
        ->and($variant->gtin)->toBe($gtin)->and($variant->mpn)->toBe('MP-01');
    app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    expect(ProductVariant::query()->forCompany($company)->count())->toBe(1);

    $response = app(ProductExportService::class)->download($actor, $company, $format);
    ob_start();
    $response->sendContent();
    $bytes = ob_get_clean();
    $path = tempnam(sys_get_temp_dir(), 'np-id-export-');
    try {
        file_put_contents($path, $bytes);
        $rows = iterator_to_array(app(ImportFileReader::class)->rows($path, $format));
        $row = array_values($rows)[0];
        expect($row['sku'])->toBe('001-A')->and($row['gtin'])->toBe($gtin)->and($row['mpn'])->toBe('MP-01');
    } finally {
        unlink($path);
    }
})->with(['csv', 'xlsx']);

test('XLSX identifier cells remain literal while CSV identifiers keep formula protection', function () {
    [$actor, $company] = identifierImportContext();
    app(CreateProductAction::class)->execute($actor, $company, ['name' => 'One', 'slug' => 'one'],
        defaultVariantIdentifiers: ['sku' => '-001', 'mpn' => '=1+1']);
    foreach (['csv', 'xlsx'] as $format) {
        ob_start();
        app(ProductExportService::class)->download($actor, $company, $format)->sendContent();
        $bytes = ob_get_clean();
        $path = tempnam(sys_get_temp_dir(), 'np-literal-id-');
        try {
            file_put_contents($path, $bytes);
            $row = array_values(iterator_to_array(app(ImportFileReader::class)->rows($path, $format)))[0];
            expect($row['sku'])->toBe($format === 'csv' ? "'-001" : '-001')
                ->and($row['mpn'])->toBe($format === 'csv' ? "'=1+1" : '=1+1');
        } finally {
            unlink($path);
        }
    }
});

test('unmapped identifiers survive update while mapped blank explicitly clears them', function () {
    [$actor, $company] = identifierImportContext();
    $product = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'One', 'slug' => 'one'],
        defaultVariantIdentifiers: ['sku' => 'ORIGINAL', 'gtin' => identifierGtin(), 'mpn' => 'MP']);
    $variantId = $product->default_variant_id;
    $job = identifierPreview($actor, $company, "name,slug,mpn\nChanged,one,NEW", ['name' => 'name', 'slug' => 'slug', 'mpn' => 'mpn'], ImportMode::Update);
    app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    $variant = $product->fresh()->defaultVariant;
    expect($variant->id)->toBe($variantId)->and($variant->sku)->toBe('ORIGINAL')
        ->and($variant->gtin)->toBe(identifierGtin())->and($variant->mpn)->toBe('NEW');
    $job = identifierPreview($actor, $company, "name,slug,sku,gtin\nChanged,one,,", ['name' => 'name', 'slug' => 'slug', 'sku' => 'sku', 'gtin' => 'gtin'], ImportMode::Update);
    app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    $variant = $product->fresh()->defaultVariant;
    expect($variant->sku)->toBeNull()->and($variant->sku_normalized)->toBeNull()
        ->and($variant->gtin)->toBeNull()->and($variant->mpn)->toBe('NEW');
});

test('identifier uniqueness includes secondary variants but remains tenant scoped', function () {
    [$actor, $company] = identifierImportContext();
    $owner = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Owner', 'slug' => 'owner']);
    app(CreateProductVariantAction::class)->execute($actor, $company, $owner, ['name' => 'Secondary', 'sku' => 'TAKEN', 'gtin' => identifierGtin()]);
    $mapping = ['name' => 'name', 'slug' => 'slug', 'sku' => 'sku', 'gtin' => 'gtin'];
    $job = identifierPreview($actor, $company, "name,slug,sku,gtin\nBlocked,blocked,taken,".identifierGtin(), $mapping);
    expect($job->invalid_rows)->toBe(1)->and($job->errors()->pluck('field')->all())->toContain('sku', 'gtin');

    $other = Company::factory()->create();
    CompanyMembership::factory()->create(['company_id' => $other->id, 'user_id' => $actor->id, 'role' => CompanyRole::Owner]);
    app(CurrentCompany::class)->set($other);
    $job = identifierPreview($actor, $other, "name,slug,sku,gtin\nAllowed,allowed,taken,".identifierGtin(), $mapping);
    expect($job->valid_rows)->toBe(1);
    $job = app(ApplyImportJobAction::class)->execute($actor, $other, $job);
    expect($job->created_rows)->toBe(1)->and(ProductVariant::query()->forCompany($other)->sole()->gtin)->toBe(identifierGtin());
});

test('a damaged cross-tenant default pointer cannot expose or import identifiers', function () {
    [$actor, $company] = identifierImportContext();
    $product = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Own', 'slug' => 'own']);
    $other = Company::factory()->create();
    CompanyMembership::factory()->create(['company_id' => $other->id, 'user_id' => $actor->id, 'role' => CompanyRole::Owner]);
    app(CurrentCompany::class)->set($other);
    $foreignProduct = app(CreateProductAction::class)->execute($actor, $other, ['name' => 'Foreign', 'slug' => 'foreign'],
        defaultVariantIdentifiers: ['sku' => 'PRIVATE-SKU', 'mpn' => 'PRIVATE-MPN']);
    $foreign = $foreignProduct->defaultVariant;
    app(CurrentCompany::class)->set($company);
    // Simulate legacy corruption; current composite foreign keys prevent this write.
    Schema::withoutForeignKeyConstraints(fn () => $product->forceFill(['default_variant_id' => $foreign->id])->save());
    ob_start();
    app(ProductExportService::class)->download($actor, $company, 'csv')->sendContent();
    $export = ob_get_clean();
    expect($export)->not->toContain('PRIVATE-SKU', 'PRIVATE-MPN');
    $job = identifierPreview($actor, $company, "name,slug,mpn\nOwn,own,NEW", ['name' => 'name', 'slug' => 'slug', 'mpn' => 'mpn'], ImportMode::Update);
    expect($job->invalid_rows)->toBe(1)->and($job->errors()->where('field', 'variant')->value('code'))->toBe('unavailable')
        ->and($foreign->fresh()->mpn)->toBe('PRIVATE-MPN');
});

test('preview reports invalid checksums and normalized duplicate identifiers across batches', function () {
    [$actor, $company] = identifierImportContext();
    $rows = ['name,slug,sku,gtin', 'First,first, A-1 ,'.identifierGtin()];
    for ($i = 1; $i < 500; $i++) {
        $rows[] = "Product {$i},product-{$i},SKU-{$i},";
    }
    $rows[] = 'Duplicate,duplicate,a-1,'.identifierGtin();
    $rows[] = 'Bad,bad,BAD,07351000000001';
    $job = identifierPreview($actor, $company, implode("\n", $rows), ['name' => 'name', 'slug' => 'slug', 'sku' => 'sku', 'gtin' => 'gtin']);
    expect($job->valid_rows)->toBe(500)->and($job->invalid_rows)->toBe(2)
        ->and($job->errors()->where('row_number', 502)->pluck('field')->all())->toContain('sku', 'gtin')
        ->and($job->errors()->where('row_number', 503)->where('field', 'gtin')->value('code'))->toBe('invalid_identifier');
});

test('a post-preview identifier conflict rolls back the entire create row', function (string $field) {
    [$actor, $company] = identifierImportContext();
    $value = $field === 'sku' ? 'RACE' : identifierGtin();
    $job = identifierPreview($actor, $company, "name,slug,id\nImported,imported,{$value}", ['name' => 'name', 'slug' => 'slug', $field => 'id']);
    expect($job->valid_rows)->toBe(1);
    app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Winner', 'slug' => 'winner'], defaultVariantIdentifiers: [$field => $value]);
    $job = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    expect($job->created_rows)->toBe(0)->and($job->skipped_rows)->toBe(1)
        ->and(Product::query()->forCompany($company)->count())->toBe(1)
        ->and(ProductVariant::query()->forCompany($company)->count())->toBe(1)
        ->and(Product::query()->forCompany($company)->where('slug_normalized', 'imported')->exists())->toBeFalse();
})->with(['sku', 'gtin']);

test('a post-preview update conflict preserves product and default variant state', function () {
    [$actor, $company] = identifierImportContext();
    $product = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Original', 'slug' => 'original'],
        defaultVariantIdentifiers: ['sku' => 'ORIGINAL', 'mpn' => 'MP']);
    $job = identifierPreview($actor, $company, "name,slug,sku,mpn\nChanged,original,RACE,CHANGED",
        ['name' => 'name', 'slug' => 'slug', 'sku' => 'sku', 'mpn' => 'mpn'], ImportMode::Update);
    expect($job->valid_rows)->toBe(1);
    app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Winner', 'slug' => 'winner'], defaultVariantIdentifiers: ['sku' => 'RACE']);
    $job = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    expect($job->updated_rows)->toBe(0)->and($job->skipped_rows)->toBe(1)
        ->and($product->fresh()->name)->toBe('Original')
        ->and($product->fresh()->defaultVariant->sku)->toBe('ORIGINAL')
        ->and($product->fresh()->defaultVariant->mpn)->toBe('MP');
});
