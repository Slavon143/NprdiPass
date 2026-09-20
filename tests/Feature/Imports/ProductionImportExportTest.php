<?php

use App\Actions\Catalog\Attributes\CreateAttributeDefinitionAction;
use App\Actions\Catalog\Attributes\CreateAttributeOptionAction;
use App\Actions\Catalog\Categories\CreateCategoryAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Imports\ApplyImportJobAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Actions\Imports\PreviewImportJobAction;
use App\Enums\CompanyRole;
use App\Enums\Imports\ImportJobStatus;
use App\Enums\Imports\ImportMode;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Imports\ImportJob;
use App\Models\Imports\ImportMappingTemplate;
use App\Models\Imports\ImportRecord;
use App\Models\User;
use App\Services\Imports\ImportFileReader;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @return array{User, Company} */
function r36ImportContext(CompanyRole $role = CompanyRole::Owner): array
{
    Storage::fake('production_imports');
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->create(['user_id' => $actor, 'company_id' => $company, 'role' => $role]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company];
}

function r36Csv(string $rows, string $name = 'products.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "name,slug,brand,manufacturer\n{$rows}");
}

/** @return array{name: string, slug: string, brand: string, manufacturer: string} */
function r36Mapping(): array
{
    return ['name' => 'name', 'slug' => 'slug', 'brand' => 'brand', 'manufacturer' => 'manufacturer'];
}

test('preview and write share one persisted plan with row level errors', function () {
    [$actor, $company] = r36ImportContext();
    $job = app(CreateImportJobAction::class)->execute(
        $actor,
        $company,
        r36Csv("Nordic Lamp,nordic-lamp,North,Maker AB\n,missing-name,North,Maker AB"),
        ImportMode::Upsert,
        'feed-001',
        1,
    );

    expect($job->headers)->toBe(['name', 'slug', 'brand', 'manufacturer'])
        ->and($job->source_path)->toStartWith("companies/{$company->uuid}/jobs/")
        ->and(Storage::disk('production_imports')->exists($job->source_path))->toBeTrue();

    $preview = app(PreviewImportJobAction::class)->execute($actor, $company, $job, r36Mapping());
    expect($preview->status)->toBe(ImportJobStatus::Previewed)
        ->and($preview->total_rows)->toBe(2)
        ->and($preview->valid_rows)->toBe(1)
        ->and($preview->invalid_rows)->toBe(1)
        ->and($preview->records->first()?->mapped_data['slug'])->toBe('nordic-lamp')
        ->and($preview->records->last()?->errors->pluck('code')->all())->toContain('required');

    $completed = app(ApplyImportJobAction::class)->execute($actor, $company, $preview, 1);
    $product = Product::query()->forCompany($company)->sole();
    $record = ImportRecord::query()->forCompany($company)->where('status', 'applied')->sole();
    expect($completed->status)->toBe(ImportJobStatus::Completed)
        ->and($completed->created_rows)->toBe(1)
        ->and($product->name)->toBe($record->mapped_data['name'])
        ->and($product->slug)->toBe($record->mapped_data['slug'])
        ->and($product->brand)->toBe($record->mapped_data['brand'])
        ->and($product->manufacturer)->toBe($record->mapped_data['manufacturer'])
        ->and($product->defaultVariant)->not->toBeNull();
});

test('chunk cursor resumes idempotently without reapplying completed rows', function () {
    [$actor, $company] = r36ImportContext();
    $file = r36Csv("One,one,A,M\nTwo,two,A,M\nThree,three,A,M");
    $job = app(CreateImportJobAction::class)->execute($actor, $company, $file, ImportMode::Upsert, 'daily-feed', 1);
    $job = app(PreviewImportJobAction::class)->execute($actor, $company, $job, r36Mapping());

    $first = app(ApplyImportJobAction::class)->execute($actor, $company, $job, 1);
    expect($first->status)->toBe(ImportJobStatus::Processing)->and($first->processed_rows)->toBe(1)->and(Product::query()->forCompany($company)->count())->toBe(1);
    $second = app(ApplyImportJobAction::class)->execute($actor, $company, $first, 1);
    expect($second->processed_rows)->toBe(2)->and(Product::query()->forCompany($company)->count())->toBe(2);
    $third = app(ApplyImportJobAction::class)->execute($actor, $company, $second, 1);
    $retry = app(ApplyImportJobAction::class)->execute($actor, $company, $third, 10);
    expect($third->status)->toBe(ImportJobStatus::Completed)
        ->and($retry->status)->toBe(ImportJobStatus::Completed)
        ->and(Product::query()->forCompany($company)->count())->toBe(3)
        ->and(ImportRecord::query()->forCompany($company)->where('status', 'applied')->count())->toBe(3);
});

test('upload idempotency key is content bound and tenant scoped', function () {
    [$actor, $company] = r36ImportContext();
    $action = app(CreateImportJobAction::class);
    $first = $action->execute($actor, $company, r36Csv('One,one,A,M'), ImportMode::Upsert, 'same-key');
    $same = $action->execute($actor, $company, r36Csv('One,one,A,M'), ImportMode::Upsert, 'same-key');
    expect($same->is($first))->toBeTrue()->and(ImportJob::query()->forCompany($company)->count())->toBe(1);
    expect(fn () => $action->execute($actor, $company, r36Csv('Two,two,A,M'), ImportMode::Upsert, 'same-key'))->toThrow(ValidationException::class);
});

test('duplicate slugs inside one file are rejected deterministically', function () {
    [$actor, $company] = r36ImportContext();
    $job = app(CreateImportJobAction::class)->execute($actor, $company, r36Csv("First,same,A,M\nSecond,SAME,A,M"), ImportMode::Upsert);
    $preview = app(PreviewImportJobAction::class)->execute($actor, $company, $job, r36Mapping());
    expect($preview->valid_rows)->toBe(1)->and($preview->invalid_rows)->toBe(1)
        ->and($preview->records->last()?->errors->pluck('code')->all())->toContain('duplicate_row');
});

test('foreign tenant cannot preview another company import', function () {
    [$actor, $company] = r36ImportContext();
    $job = app(CreateImportJobAction::class)->execute($actor, $company, r36Csv('One,one,A,M'), ImportMode::Upsert);
    $other = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $other]);
    app(CurrentCompany::class)->set($other);
    expect(fn () => app(PreviewImportJobAction::class)->execute($actor, $other, $job, r36Mapping()))->toThrow(NotFoundHttpException::class);
});

test('xlsx reader returns the same canonical rows as csv', function () {
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray([
        ['name', 'slug', 'brand', 'manufacturer'],
        ['Nordic Chair', 'nordic-chair', 'North', 'Maker AB'],
    ]);
    $temporary = tempnam(sys_get_temp_dir(), 'r36-xlsx-');
    unlink($temporary);
    $path = $temporary.'.xlsx';
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();
    $rows = iterator_to_array(app(ImportFileReader::class)->rows($path, 'xlsx'));
    unlink($path);

    expect($rows)->toHaveCount(1)
        ->and(array_values($rows)[0])->toBe(['name' => 'Nordic Chair', 'slug' => 'nordic-chair', 'brand' => 'North', 'manufacturer' => 'Maker AB']);
});

test('xlsx formulas are rejected before preview to prevent active spreadsheet content', function () {
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray([['name', 'slug'], ['Safe name', '=HYPERLINK("https://example.test","open")']]);
    $temporary = tempnam(sys_get_temp_dir(), 'r36-formula-');
    unlink($temporary);
    $path = $temporary.'.xlsx';
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();
    expect(fn () => iterator_to_array(app(ImportFileReader::class)->rows($path, 'xlsx')))->toThrow(ValidationException::class);
    unlink($path);
});

test('import workspace is visible and exports remain tenant scoped', function () {
    [$actor, $company] = r36ImportContext();
    app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Own', 'slug' => 'own']);
    $other = Company::factory()->create();
    $foreignActor = User::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $foreignActor, 'company_id' => $other]);
    test()->actingAs($foreignActor);
    app(CurrentCompany::class)->set($other);
    app(CreateProductAction::class)->execute($foreignActor, $other, ['name' => 'Foreign', 'slug' => 'foreign']);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    test()->get(route('catalog.imports.index'))->assertOk()->assertSee('Production import &amp; export', false);
    $response = test()->get(route('catalog.imports.export', 'csv'));
    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    expect($response->streamedContent())->toContain('Own')->not->toContain('Foreign');
});

test('import job page renders with saved mapping templates', function () {
    [$actor, $company] = r36ImportContext();
    $job = app(CreateImportJobAction::class)->execute($actor, $company, r36Csv('One,one,A,M'), ImportMode::Create);

    ImportMappingTemplate::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Default product mapping',
        'name_normalized' => 'default product mapping',
        'entity_type' => 'product',
        'mapping' => r36Mapping(),
        'defaults' => [],
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ]);

    test()->get(route('catalog.imports.show', $job))
        ->assertOk()
        ->assertSee('Load saved mapping');
});

test('large preview persists every row and preserves configured chunk boundaries', function () {
    [$actor, $company] = r36ImportContext();
    $rows = [];
    for ($index = 1; $index <= 1500; $index++) {
        $rows[] = "Product {$index},product-{$index},North,Maker";
    }
    $job = app(CreateImportJobAction::class)->execute($actor, $company, r36Csv(implode("\n", $rows)), ImportMode::Create, null, 333);
    $preview = app(PreviewImportJobAction::class)->execute($actor, $company, $job, r36Mapping());
    expect($preview->total_rows)->toBe(1500)->and($preview->valid_rows)->toBe(1500)->and($preview->chunk_size)->toBe(333)
        ->and(ImportRecord::query()->forCompany($company)->where('import_job_id', $job->id)->count())->toBe(1500);
});

test('production import routes disappear when rollout flag is disabled', function () {
    [$actor, $company] = r36ImportContext();
    config()->set('features.production_imports', false);
    test()->get(route('catalog.imports.index'))->assertNotFound();
    expect($actor->companies()->whereKey($company->id)->exists())->toBeTrue();
});

test('update mode performs bulk updates without replacing product aggregates', function () {
    [$actor, $company] = r36ImportContext();
    $product = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Old name', 'slug' => 'stable-product', 'brand' => 'Old']);
    $variantId = $product->default_variant_id;
    $job = app(CreateImportJobAction::class)->execute($actor, $company, r36Csv('New name,stable-product,New Brand,New Maker'), ImportMode::Update);
    $preview = app(PreviewImportJobAction::class)->execute($actor, $company, $job, ['name' => 'name', 'slug' => 'slug']);
    expect($preview->records->sole()->operation)->toBe('update');
    $completed = app(ApplyImportJobAction::class)->execute($actor, $company, $preview);
    $product->refresh();
    expect($completed->updated_rows)->toBe(1)->and($completed->created_rows)->toBe(0)
        ->and($product->name)->toBe('New name')->and($product->brand)->toBe('Old')
        ->and($product->default_variant_id)->toBe($variantId);
});

test('xlsx export opens as a valid workbook and contains tenant product rows', function () {
    [$actor, $company] = r36ImportContext();
    app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Workbook Product', 'slug' => 'workbook-product']);
    $response = test()->get(route('catalog.imports.export', 'xlsx'));
    $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $temporary = tempnam(sys_get_temp_dir(), 'r36-export-');
    file_put_contents($temporary, $response->streamedContent());
    $sheet = IOFactory::load($temporary)->getActiveSheet();
    unlink($temporary);
    expect($sheet->getCell('A1')->getValue())->toBe('name')->and($sheet->getCell('A2')->getValue())->toBe('Workbook Product');
});

test('imports resolve active categories and approved attribute vocabulary without inventing values', function () {
    [$actor, $company] = r36ImportContext();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, ['name' => 'Lighting', 'slug' => 'lighting']);
    $definitions = app(CreateAttributeDefinitionAction::class);
    $color = $definitions->execute($actor, $company, [
        'name' => 'Color', 'code' => 'color', 'type' => 'select', 'scope' => 'product',
        'required' => false, 'filterable' => true, 'searchable' => false, 'vocabulary_controlled' => false, 'sort_order' => 1,
    ]);
    $note = $definitions->execute($actor, $company, [
        'name' => 'Material note', 'code' => 'material_note', 'type' => 'text', 'scope' => 'product',
        'required' => false, 'filterable' => false, 'searchable' => true, 'vocabulary_controlled' => false, 'sort_order' => 2,
    ]);
    $blue = app(CreateAttributeOptionAction::class)->execute($actor, $company, $color, ['label' => 'Blue', 'code' => 'blue', 'sort_order' => 1]);
    $file = UploadedFile::fake()->createWithContent('taxonomy.csv', "name,slug,category,color,note\nBlue Lamp,blue-lamp,lighting,blue,Recycled steel\nBad Lamp,bad-lamp,lighting,unknown,Steel");
    $job = app(CreateImportJobAction::class)->execute($actor, $company, $file, ImportMode::Create);
    $preview = app(PreviewImportJobAction::class)->execute($actor, $company, $job, [
        'name' => 'name', 'slug' => 'slug', 'primary_category' => 'category',
        'attribute:color' => 'color', 'attribute:material_note' => 'note',
    ]);
    expect($preview->valid_rows)->toBe(1)->and($preview->invalid_rows)->toBe(1)
        ->and($preview->records->last()?->errors->pluck('code')->all())->toContain('invalid_attribute_value');
    app(ApplyImportJobAction::class)->execute($actor, $company, $preview);
    $product = Product::query()->forCompany($company)->sole();
    $values = ProductAttributeValue::query()->forCompany($company)->where('product_id', $product->id)->get()->keyBy('attribute_definition_id');
    expect($product->primary_category_id)->toBe($category->id)
        ->and($values->get($color->id)?->value_option_id)->toBe($blue->id)
        ->and($values->get($note->id)?->value_text)->toBe('Recycled steel');
    $export = test()->get(route('catalog.imports.export', 'csv'))->streamedContent();
    expect($export)->toContain('primary_category')->toContain('attribute:color')->toContain('lighting')->toContain('blue')->toContain('Recycled steel');
});
