<?php

use App\Actions\Imports\ApplyImportJobAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Actions\Imports\PreviewImportJobAction;
use App\Enums\Imports\ImportMode;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Imports\ImportRecord;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

function transientImportFixture(): array
{
    Storage::fake('production_imports');
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor->id, 'company_id' => $company->id]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $job = app(CreateImportJobAction::class)->execute($actor, $company,
        UploadedFile::fake()->createWithContent('products.csv', "name,slug\nOne,one\nTwo,two"), ImportMode::Create, chunkSize: 2);
    $job = app(PreviewImportJobAction::class)->execute($actor, $company, $job, ['name' => 'name', 'slug' => 'slug']);

    return [$actor, $company, $job];
}

function importDatabaseFault(int $driverCode): QueryException
{
    $driver = new PDOException('Injected database failure');
    $driver->errorInfo = ['HY000', $driverCode, 'Injected database failure'];

    return new QueryException('mysql', 'select import failure fixture', [], $driver);
}

test('transient database failures roll back the chunk and retain valid rows for retry', function (int $driverCode) {
    [$actor, $company, $job] = transientImportFixture();
    ImportRecord::saved(function (ImportRecord $record) use ($driverCode): void {
        if ($record->row_number === 3 && $record->status->value === 'applied') {
            throw importDatabaseFault($driverCode);
        }
    });
    try {
        // The test owns an enclosing transaction. The action must propagate
        // rather than retry only a savepoint with a stale transaction snapshot.
        expect(fn () => app(ApplyImportJobAction::class)->execute($actor, $company, $job))->toThrow(QueryException::class);
    } finally {
        Event::forget('eloquent.saved: '.ImportRecord::class);
    }
    expect($job->refresh()->status->value)->toBe('previewed')
        ->and($job->processed_rows)->toBe(0)->and($job->created_rows)->toBe(0)->and($job->skipped_rows)->toBe(0)
        ->and($job->records()->where('status', 'valid')->count())->toBe(2)
        ->and($job->errors()->count())->toBe(0)
        ->and(Product::query()->forCompany($company)->count())->toBe(0)
        ->and($company->productVariants()->count())->toBe(0);
    $result = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    $again = app(ApplyImportJobAction::class)->execute($actor, $company, $result);
    expect($again->status->value)->toBe('completed')->and($again->processed_rows)->toBe(2)
        ->and($again->created_rows)->toBe(2)->and($again->skipped_rows)->toBe(0)
        ->and(Product::query()->forCompany($company)->count())->toBe(2);
})->with([1412, 2006, 2013]);

test('a later transient chunk failure preserves previously committed checkpoint counters', function () {
    [$actor, $company, $job] = transientImportFixture();
    $job->forceFill(['chunk_size' => 1])->save();
    $first = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    $firstProduct = $first->records()->where('row_number', 2)->sole()->target_product_id;
    ImportRecord::saved(function (ImportRecord $record): void {
        if ($record->row_number === 3 && $record->status->value === 'applied') {
            throw importDatabaseFault(1412);
        }
    });
    try {
        expect(fn () => app(ApplyImportJobAction::class)->execute($actor, $company, $first))->toThrow(QueryException::class);
    } finally {
        Event::forget('eloquent.saved: '.ImportRecord::class);
    }
    expect($first->refresh()->status->value)->toBe('processing')->and($first->processed_rows)->toBe(1)
        ->and($first->created_rows)->toBe(1)->and($first->skipped_rows)->toBe(0)
        ->and($first->records()->where('row_number', 2)->sole()->target_product_id)->toBe($firstProduct)
        ->and($first->records()->where('row_number', 3)->sole()->status->value)->toBe('valid')
        ->and($first->errors()->count())->toBe(0);
    $result = app(ApplyImportJobAction::class)->execute($actor, $company, $first);
    expect($result->created_rows)->toBe(2)->and($result->skipped_rows)->toBe(0);
});

test('permanent database integrity errors retain the existing failed row behavior', function () {
    [$actor, $company, $job] = transientImportFixture();
    ImportRecord::saved(function (ImportRecord $record): void {
        if ($record->row_number === 3 && $record->status->value === 'applied') {
            throw importDatabaseFault(1452);
        }
    });
    try {
        $result = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    } finally {
        Event::forget('eloquent.saved: '.ImportRecord::class);
    }
    expect($result->status->value)->toBe('completed')->and($result->processed_rows)->toBe(2)
        ->and($result->created_rows)->toBe(1)->and($result->skipped_rows)->toBe(1)
        ->and($result->errors()->count())->toBe(1)
        ->and($result->records()->where('row_number', 3)->sole()->target_product_id)->toBeNull();
});
