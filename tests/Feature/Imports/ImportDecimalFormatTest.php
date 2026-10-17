<?php

use App\Actions\Imports\ApplyImportJobAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Actions\Imports\PreviewImportJobAction;
use App\Enums\Imports\ImportMode;
use App\Enums\Imports\ImportNumberFormat;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Imports\ImportMappingTemplate;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function decimalImportFixture(): array
{
    config()->set('features.production_imports', true);
    Storage::fake('production_imports');
    $company = Company::factory()->create();
    $actor = User::factory()->create(['email_verified_at' => now()]);
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $company]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $attribute = AttributeDefinition::query()->forceCreate(['company_id' => $company->getKey(), 'code' => 'weight', 'name' => 'Weight', 'type' => 'decimal', 'scope' => 'product', 'status' => 'active', 'created_by' => $actor->getKey()]);

    return [$actor, $company, $attribute];
}

test('explicit decimal comma preview retains source and applies the same normalized value', function () {
    [$actor, $company, $attribute] = decimalImportFixture();
    $file = UploadedFile::fake()->createWithContent('abb.csv', "name;weight\nABB S201;0,0040\n");
    $job = app(CreateImportJobAction::class)->execute($actor, $company, $file, ImportMode::Create);
    $mapping = ['name' => 'name', 'attribute:weight' => 'weight'];
    $preview = app(PreviewImportJobAction::class)->execute($actor, $company, $job, $mapping);
    expect($preview->invalid_rows)->toBe(1);
    $dotFingerprint = $preview->plan_fingerprint;
    app()->setLocale('sv');
    $preview = app(PreviewImportJobAction::class)->execute($actor, $company, $job, $mapping, ImportNumberFormat::Comma);
    expect($preview->valid_rows)->toBe(1)->and($preview->plan_fingerprint)->not->toBe($dotFingerprint)
        ->and($preview->records->sole()->source_data['weight'])->toBe('0,0040')
        ->and($preview->records->sole()->mapped_data['attributes'][$attribute->uuid])->toBe('0.0040')
        ->and($preview->records->sole()->normalizations)->toBe([['field' => 'attribute:weight', 'source' => '0,0040', 'normalized' => '0.0040']]);
    test()->get(route('catalog.imports.show', $job))->assertOk()->assertSee('0,0040')->assertSee('0.0040')->assertSee('name="number_format"', false);
    $completed = app(ApplyImportJobAction::class)->execute($actor, $company, $preview);
    expect($completed->created_rows)->toBe(1)
        ->and(Product::query()->forCompany($company)->sole()->attributeValues()->where('attribute_definition_id', $attribute->getKey())->sole()->value_decimal)->toBe('0.0040');
});

test('decimal parsing rejects grouped mixed scientific and excess precision input', function (string $value, ImportNumberFormat $format) {
    [$actor, $company] = decimalImportFixture();
    $job = app(CreateImportJobAction::class)->execute($actor, $company, UploadedFile::fake()->createWithContent('numbers.csv', "name;weight\nTest;{$value}\n"), ImportMode::Create);
    $preview = app(PreviewImportJobAction::class)->execute($actor, $company, $job, ['name' => 'name', 'attribute:weight' => 'weight'], $format);
    expect($preview->invalid_rows)->toBe(1)->and($preview->records->sole()->errors->sole()->code)->toBe('invalid_attribute_value');
})->with([
    ['1.234,56', ImportNumberFormat::Comma], ['1,234.56', ImportNumberFormat::Dot],
    ['1 234,56', ImportNumberFormat::Comma], ['1e-3', ImportNumberFormat::Dot],
    ['0,00001', ImportNumberFormat::Comma], ['0.0040', ImportNumberFormat::Comma],
]);

test('mapping templates preserve decimal format and loading clears stale targets', function () {
    [$actor, $company] = decimalImportFixture();
    test()->post(route('catalog.imports.templates.store'), ['name' => 'ABB', 'mapping' => ['name' => 'Name', 'attribute:weight' => 'Weight'], 'number_format' => 'comma'])->assertRedirect();
    $template = ImportMappingTemplate::query()->forCompany($company)->sole();
    expect($template->defaults)->toBe(['number_format' => 'comma']);
    test()->post(route('catalog.imports.templates.store'), ['name' => 'ABB', 'mapping' => ['name' => 'New name'], 'number_format' => 'dot'])->assertRedirect();
    expect($template->fresh()->defaults)->toBe(['number_format' => 'dot']);
    test()->post(route('catalog.imports.templates.store'), ['name' => 'Bad', 'mapping' => ['name' => 'Name'], 'number_format' => 'auto'])->assertSessionHasErrors('number_format');
});
