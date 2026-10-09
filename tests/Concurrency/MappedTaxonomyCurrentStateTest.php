<?php

use App\Actions\Catalog\Categories\CreateCategoryAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Products\UpdateProductAction;
use App\Actions\Imports\ApplyImportJobAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Actions\Imports\PreviewImportJobAction;
use App\Enums\AuditEvent;
use App\Enums\Imports\ImportMode;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('mapped updates preserve freshly committed additional categories and current primary provenance', function (string $mapping) {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    Http::preventStrayRequests();
    Queue::fake();
    Storage::fake('production_imports');
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $actor]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $creator = app(CreateCategoryAction::class);
    $old = $creator->execute($actor, $company, ['name' => 'Old primary']);
    $oldExtra = $creator->execute($actor, $company, ['name' => 'Old extra']);
    $mapped = $creator->execute($actor, $company, ['name' => 'Mapped', 'slug' => 'mapped']);
    $product = app(CreateProductAction::class)->execute($actor, $company,
        ['name' => 'Original', 'slug' => 'taxonomy-target'], $old->uuid, [$oldExtra->uuid]);
    $file = UploadedFile::fake()->createWithContent('update.csv', "name,slug,category\nImported,taxonomy-target,".($mapping === 'replace' ? 'mapped' : ''));
    $job = app(CreateImportJobAction::class)->execute($actor, $company, $file, ImportMode::Update);
    $columns = ['name' => 'name', 'slug' => 'slug'];
    if ($mapping !== 'omit') {
        $columns['primary_category'] = 'category';
    }
    $job = app(PreviewImportJobAction::class)->execute($actor, $company, $job, $columns);
    expect($job->valid_rows)->toBe(1);
    config(['database.connections.mysql_taxonomy_competitor' => config('database.connections.mysql')]);
    DB::purge('mysql_taxonomy_competitor');
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        expect(DB::table('products')->where('id', $product->id)->value('primary_category_id'))->toBe($old->id);
        config(['database.default' => 'mysql_taxonomy_competitor']);
        try {
            $tenant = Company::findOrFail($company->id);
            app(CurrentCompany::class)->set($tenant);
            $editor = User::findOrFail($actor->id);
            $fresh = $creator->execute($editor, $tenant, ['name' => 'Fresh primary']);
            $extra = $creator->execute($editor, $tenant, ['name' => 'Fresh extra']);
            app(UpdateProductAction::class)->execute($editor, $tenant, $tenant->products()->findOrFail($product->id),
                ['brand' => 'Competing edit'], $fresh->uuid, [$extra->uuid], syncCategories: true);
        } finally {
            config(['database.default' => 'mysql']);
            app(CurrentCompany::class)->set($company);
        }
        expect(DB::table('products')->where('id', $product->id)->value('primary_category_id'))->toBe($old->id);
        $completed = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
        expect($completed->updated_rows)->toBe(1)->and($completed->skipped_rows)->toBe(0);
        $primary->commit();
        $expectedPrimary = match ($mapping) {
            'omit' => $fresh, 'replace' => $mapped, 'clear' => null
        };
        $expectedIds = array_filter([$expectedPrimary?->id, $extra->id]);
        sort($expectedIds);
        expect($product->fresh()->primary_category_id)->toBe($expectedPrimary?->id)
            ->and($product->fresh()->brand)->toBe('Competing edit')
            ->and($product->categories()->orderBy('categories.id')->pluck('categories.id')->all())->toBe($expectedIds);
        $audit = AuditLog::query()->where('company_id', $company->id)->where('event', AuditEvent::CatalogProductUpdated->value)->latest('id')->firstOrFail();
        expect($audit->getProperty('old_primary_category_uuid'))->toBe($fresh->uuid)
            ->and($audit->getProperty('new_primary_category_uuid'))->toBe($expectedPrimary?->uuid)
            ->and($audit->getProperty('old_category_count'))->toBe(2);
        $count = AuditLog::query()->where('company_id', $company->id)->count();
        app(ApplyImportJobAction::class)->execute($actor, $company, $completed);
        expect(AuditLog::query()->where('company_id', $company->id)->count())->toBe($count);
    } finally {
        config(['database.default' => 'mysql']);
        app(CurrentCompany::class)->set($company);
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_taxonomy_competitor');
    }
})->with(['omit', 'replace', 'clear']);
