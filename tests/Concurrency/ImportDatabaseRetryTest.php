<?php

use App\Actions\Imports\ApplyImportJobAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Actions\Imports\PreviewImportJobAction;
use App\Enums\Imports\ImportMode;
use App\Models\AuditLog;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Imports\ImportJob;
use App\Models\Imports\ImportRecord;
use App\Models\User;
use App\Services\Catalog\CatalogCapacityReconciler;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

function rootDatabaseImportFixture(): array
{
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    test()->artisan('migrate:fresh')->assertSuccessful();
    Storage::fake('production_imports');
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    app(CatalogCapacityReconciler::class)->reconcile($company->id, true);
    CompanyMembership::factory()->owner()->create(['user_id' => $actor->id, 'company_id' => $company->id]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $job = app(CreateImportJobAction::class)->execute($actor, $company,
        UploadedFile::fake()->createWithContent('products.csv', "name,slug\nOne,one\nTwo,two"), ImportMode::Create, chunkSize: 2);
    $job = app(PreviewImportJobAction::class)->execute($actor, $company, $job, ['name' => 'name', 'slug' => 'slug']);

    return [$actor, $company, $job];
}

test('a root import transaction retries a schema change without duplicating prior row writes or audits', function () {
    [$actor, $company, $job] = rootDatabaseImportFixture();
    $attempts = 0;
    ImportRecord::saved(function (ImportRecord $record) use (&$attempts): void {
        if ($record->row_number === 3 && $record->status->value === 'applied' && ++$attempts === 1) {
            $driver = new PDOException('Injected table definition change');
            $driver->errorInfo = ['HY000', 1412, 'Injected table definition change'];
            throw new QueryException('mysql', 'select import retry fixture', [], $driver);
        }
    });
    try {
        $result = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    } finally {
        Event::forget('eloquent.saved: '.ImportRecord::class);
    }
    expect($attempts)->toBe(2)->and($result->status->value)->toBe('completed')
        ->and($result->created_rows)->toBe(2)->and($result->processed_rows)->toBe(2)->and($result->skipped_rows)->toBe(0)
        ->and($result->errors()->count())->toBe(0)->and(Product::query()->forCompany($company)->count())->toBe(2)
        ->and($company->productVariants()->count())->toBe(2)
        ->and($company->catalogCapacity()->value('products_count'))->toBe(2)
        ->and(AuditLog::query()->where('company_id', $company->id)->where('event', 'catalog.product.created')->count())->toBe(2);
});

test('real MySQL lock waits exhaust bounded retries without failing rows and remain recoverable', function () {
    [$actor, $company, $job] = rootDatabaseImportFixture();
    config(['database.connections.mysql_import_retry' => config('database.connections.mysql')]);
    DB::purge('mysql_import_retry');
    $holder = DB::connection('mysql');
    $holder->beginTransaction();
    $holder->table('import_records')->where('company_id', $company->id)->where('import_job_id', $job->id)
        ->orderBy('row_number')->lockForUpdate()->first();
    config(['database.default' => 'mysql_import_retry']);
    $competitor = DB::connection('mysql_import_retry');
    $competitor->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $attempts = 0;
    $competitor->beforeExecuting(function (string $sql) use (&$attempts): void {
        if (str_contains($sql, 'import_records') && str_contains(strtolower($sql), 'for update')) {
            $attempts++;
        }
    });
    try {
        $freshActor = User::query()->findOrFail($actor->id);
        $freshCompany = Company::query()->findOrFail($company->id);
        $freshJob = ImportJob::query()->forCompany($freshCompany)->findOrFail($job->id);
        test()->actingAs($freshActor);
        app(CurrentCompany::class)->set($freshCompany);
        expect(fn () => app(ApplyImportJobAction::class)->execute($freshActor, $freshCompany, $freshJob))
            ->toThrow(DeadlockException::class);
        expect($attempts)->toBe(3)->and($competitor->transactionLevel())->toBe(0)
            ->and($freshJob->refresh()->status->value)->toBe('previewed')
            ->and($freshJob->processed_rows)->toBe(0)->and($freshJob->skipped_rows)->toBe(0)
            ->and($freshJob->records()->where('status', 'valid')->count())->toBe(2)
            ->and($freshJob->errors()->count())->toBe(0);
        $holder->rollBack();
        $result = app(ApplyImportJobAction::class)->execute($freshActor, $freshCompany, $freshJob);
        expect($result->status->value)->toBe('completed')->and($result->created_rows)->toBe(2)
            ->and($freshCompany->catalogCapacity()->value('products_count'))->toBe(2)
            ->and($result->skipped_rows)->toBe(0);
    } finally {
        config(['database.default' => 'mysql']);
        app(CurrentCompany::class)->clear();
        if ($holder->transactionLevel() > 0) {
            $holder->rollBack();
        }
        DB::purge('mysql_import_retry');
    }
});

test('connection loss aborts the chunk without automatically replaying an uncertain commit', function (int $driverCode) {
    [$actor, $company, $job] = rootDatabaseImportFixture();
    $attempts = 0;
    ImportRecord::saved(function (ImportRecord $record) use (&$attempts, $driverCode): void {
        if ($record->row_number === 3 && $record->status->value === 'applied') {
            $attempts++;
            $driver = new PDOException('Injected connection loss');
            $driver->errorInfo = ['HY000', $driverCode, 'Injected connection loss'];
            throw new QueryException('mysql', 'select import communication fixture', [], $driver);
        }
    });
    try {
        expect(fn () => app(ApplyImportJobAction::class)->execute($actor, $company, $job))->toThrow(QueryException::class);
    } finally {
        Event::forget('eloquent.saved: '.ImportRecord::class);
    }
    expect($attempts)->toBe(1)->and($job->refresh()->status->value)->toBe('previewed')
        ->and($job->processed_rows)->toBe(0)->and($job->skipped_rows)->toBe(0)
        ->and($job->records()->where('status', 'valid')->count())->toBe(2)
        ->and($company->catalogCapacity()->value('products_count'))->toBe(0)
        ->and($job->errors()->count())->toBe(0)->and(Product::query()->forCompany($company)->count())->toBe(0);
})->with([2006, 2013]);

test('a lost acknowledgement after actual commit leaves the completed checkpoint without automatic replay', function () {
    [$actor, $company, $job] = rootDatabaseImportFixture();
    $commits = 0;
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$commits): void {
        if ($event->connection->transactionLevel() === 0) {
            $commits++;
            $driver = new PDOException('Injected loss after commit');
            $driver->errorInfo = ['HY000', 2013, 'Injected loss after commit'];
            throw new QueryException('mysql', 'commit acknowledgement fixture', [], $driver);
        }
    });
    try {
        expect(fn () => app(ApplyImportJobAction::class)->execute($actor, $company, $job))->toThrow(QueryException::class);
    } finally {
        Event::forget(TransactionCommitted::class);
    }
    expect($commits)->toBe(1)->and(DB::transactionLevel())->toBe(0)
        ->and($job->refresh()->status->value)->toBe('completed')
        ->and($job->created_rows)->toBe(2)->and($job->processed_rows)->toBe(2)->and($job->skipped_rows)->toBe(0);
    $result = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    expect($result->created_rows)->toBe(2)->and(Product::query()->forCompany($company)->count())->toBe(2)
        ->and($company->catalogCapacity()->value('products_count'))->toBe(2)
        ->and(AuditLog::query()->where('company_id', $company->id)->where('event', 'catalog.product.created')->count())->toBe(2);
});
