<?php

use App\Actions\Catalog\Attributes\CreateAttributeDefinitionAction;
use App\Actions\Catalog\Attributes\CreateAttributeOptionAction;
use App\Actions\Imports\ApplyImportJobAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Actions\Imports\PreviewImportJobAction;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Imports\ImportMode;
use App\Enums\Imports\ImportRecordStatus;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Models\AuditLog;
use App\Models\Catalog\AttributeOption;
use App\Models\Imports\ImportRecord;
use App\Services\Imports\ProductImportRowPlanner;
use App\Support\Catalog\AttributeValueValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('preview validates selectable options once per batch and refreshes them between batches', function () {
    [$actor, $company] = r36ImportContext();
    $definitions = app(CreateAttributeDefinitionAction::class);
    $options = app(CreateAttributeOptionAction::class);
    $color = $definitions->execute($actor, $company, ['name' => 'Color', 'code' => 'color',
        'type' => 'select', 'scope' => 'product', 'required' => false, 'filterable' => false,
        'searchable' => false, 'vocabulary_controlled' => false, 'sort_order' => 1]);
    $tags = $definitions->execute($actor, $company, ['name' => 'Tags', 'code' => 'tags',
        'type' => 'multiselect', 'scope' => 'product', 'required' => false, 'filterable' => false,
        'searchable' => false, 'vocabulary_controlled' => false, 'sort_order' => 2]);
    $blue = $options->execute($actor, $company, $color, ['label' => 'Blue', 'code' => 'blue', 'sort_order' => 1]);
    $tag = $options->execute($actor, $company, $tags, ['label' => 'Recycled', 'code' => 'recycled', 'sort_order' => 1]);
    $sources = [];
    for ($row = 0; $row < 500; $row++) {
        $sources[$row + 2] = ['name' => "Product {$row}", 'slug' => "options-{$row}", 'color' => 'blue', 'tags' => 'recycled'];
    }
    $mapping = ['name' => 'name', 'slug' => 'slug', 'attribute:color' => 'color', 'attribute:tags' => 'tags'];
    $planner = app(ProductImportRowPlanner::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $plans = $planner->planBatch($company, $sources, $mapping, ImportMode::Create);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count($queries))->toBeLessThan(8)
        ->and(array_filter($plans, fn ($plan) => $plan->errors !== []))->toBeEmpty()
        ->and($plans[2]->mapped['attributes'][$color->uuid])->toBe($blue->id);

    $blue->forceFill(['status' => 'archived'])->save();
    $next = $planner->planBatch($company, [502 => $sources[2]], $mapping, ImportMode::Create);
    expect($next[502]->errors)->toHaveCount(1);
    $wrongDefinition = $sources[2];
    $wrongDefinition['color'] = (string) $tag->id;
    expect($planner->planBatch($company, [503 => $wrongDefinition], $mapping, ImportMode::Create)[503]->errors)->toHaveCount(1);
    expect(fn () => app(AttributeValueValidator::class)->normalize(
        $company, $color, AttributeScope::Product, $blue->id,
    ))->toThrow(AttributeOperationException::class);

    $foreign = new AttributeOption;
    $foreign->forceFill(['id' => $blue->id, 'company_id' => $company->id + 1,
        'attribute_definition_id' => $color->id, 'status' => 'active', 'approval_status' => 'approved']);
    expect(fn () => app(AttributeValueValidator::class)->normalize(
        $company, $color, AttributeScope::Product, $blue->id, [$blue->id => $foreign],
    ))->toThrow(AttributeOperationException::class);
});

test('preview batches lookup and persistence while retaining cross batch duplicate errors', function () {
    [$actor, $company] = r36ImportContext();
    $rows = [];
    for ($index = 0; $index < 1010; $index++) {
        $rows[] = "Product {$index},product-{$index},Brand,Maker";
    }
    $rows[] = 'Duplicate,product-0,Brand,Maker';
    $job = app(CreateImportJobAction::class)->execute($actor, $company, r36Csv(implode("\n", $rows)), ImportMode::Create);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $preview = app(PreviewImportJobAction::class)->execute($actor, $company, $job, r36Mapping());
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count($queries))->toBeLessThan(80)
        ->and($preview->valid_rows)->toBe(1010)->and($preview->invalid_rows)->toBe(1);
    $duplicate = ImportRecord::query()->forCompany($company)->where('import_job_id', $job->id)->where('row_number', 1012)->sole();
    expect($duplicate->errors->sole()->code)->toBe('duplicate_row')
        ->and($duplicate->errors->sole()->message)->toContain('row 2');
});

test('a failed later preview batch retains the complete previous plan and fingerprint', function () {
    [$actor, $company] = r36ImportContext();
    $rows = [];
    for ($index = 0; $index < 501; $index++) {
        $rows[] = "Product {$index},product-{$index},Brand,Maker";
    }
    $job = app(CreateImportJobAction::class)->execute($actor, $company, r36Csv(implode("\n", $rows)), ImportMode::Create);
    $job = app(PreviewImportJobAction::class)->execute($actor, $company, $job, r36Mapping());
    $ids = $job->records()->orderBy('row_number')->pluck('id')->all();
    $fingerprint = $job->plan_fingerprint;
    config(['imports.max_rows' => 500]);
    expect(fn () => app(PreviewImportJobAction::class)->execute($actor, $company, $job, r36Mapping()))->toThrow(ValidationException::class);
    expect($job->fresh()->plan_fingerprint)->toBe($fingerprint)
        ->and($job->fresh()->valid_rows)->toBe(501)
        ->and($job->records()->orderBy('row_number')->pluck('id')->all())->toBe($ids);
});

test('chunk counters advance atomically without scanning the complete import on every continuation', function () {
    [$actor, $company, $job] = boundaryImport();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $first = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    $second = app(ApplyImportJobAction::class)->execute($actor, $company, $first);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $aggregates = array_filter($queries, fn ($query) => str_contains(strtolower($query['query']), 'sum(status'));
    expect($aggregates)->toBeEmpty()
        ->and($second->processed_rows)->toBe(2)->and($second->created_rows)->toBe(2)
        ->and($second->updated_rows)->toBe(0)->and($second->skipped_rows)->toBe(0);
    $repeat = app(ApplyImportJobAction::class)->execute($actor, $company, $second);
    expect($repeat->processed_rows)->toBe(2)->and($repeat->created_rows)->toBe(2);
});

test('bad identifier lengths are reported per row without losing other import rows', function () {
    [$actor, $company] = r36ImportContext();
    $csv = "name,slug,category\nLong slug,".str_repeat('s', 300).",\n".str_repeat('n', 300)
        .",short-name-slug,\nLong category,long-category,".str_repeat('c', 300)."\nValid product,valid-product,";
    $job = app(CreateImportJobAction::class)->execute($actor, $company,
        UploadedFile::fake()->createWithContent('products.csv', $csv), ImportMode::Create);
    $preview = app(PreviewImportJobAction::class)->execute($actor, $company, $job,
        ['name' => 'name', 'slug' => 'slug', 'primary_category' => 'category']);
    expect($preview->total_rows)->toBe(4)->and($preview->invalid_rows)->toBe(3)->and($preview->valid_rows)->toBe(1);
    expect($preview->records->where('status', ImportRecordStatus::Invalid))->toHaveCount(3);
});

test('mapped apply builds attribute payload from locked state without preliminary duplicate reads', function () {
    [$actor, $company] = r36ImportContext();
    app(CreateAttributeDefinitionAction::class)->execute($actor, $company, ['name' => 'Material', 'code' => 'material',
        'type' => 'text', 'scope' => 'product', 'required' => false, 'filterable' => false,
        'searchable' => false, 'vocabulary_controlled' => false, 'sort_order' => 1]);
    $source = UploadedFile::fake()->createWithContent('mapped.csv', "name,slug,material\nOne,one,Steel\nTwo,two,Wood\nThree,three,Glass");
    $job = app(CreateImportJobAction::class)->execute($actor, $company, $source, ImportMode::Create);
    $job = app(PreviewImportJobAction::class)->execute($actor, $company, $job,
        ['name' => 'name', 'slug' => 'slug', 'attribute:material' => 'material']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $completed = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }
    foreach (['attribute_definitions', 'product_attribute_values'] as $table) {
        $reads = array_filter($queries, fn ($query) => str_starts_with(strtolower($query['query']), 'select')
            && str_contains($query['query'], 'from `'.$table.'`') && str_contains($query['query'], '`company_id`'));
        // Three current mutation reads; imports do not hydrate unused response relations.
        expect(array_filter($reads, fn ($query) => ! str_contains($query['query'], 'for update')
            && ! str_contains($query['query'], 'lock in share mode')))->toBeEmpty()
            ->and(array_filter($reads, fn ($query) => str_contains($query['query'], 'for update')))->toHaveCount($table === 'product_attribute_values' ? 3 : 0)
            ->and(array_filter($reads, fn ($query) => str_contains($query['query'], 'lock in share mode')))->toHaveCount($table === 'attribute_definitions' ? 3 : 0);
    }
    expect($completed->created_rows)->toBe(3)->and($completed->skipped_rows)->toBe(0)
        ->and(AuditLog::query()->where('company_id', $company->id)->where('event', 'catalog.product.attributes.updated')->count())->toBe(3);
});
