<?php

use App\Enums\ApiTokenAbility;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Imports\ImportJob;
use App\Models\Jobs\JobStatus;
use App\Models\User;
use App\Services\Catalog\CatalogCapacityReconciler;
use App\Services\Imports\StreamingXlsxWriter;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

function runImportDrillWorker(ImportJob $job, string $mode): Process
{
    $connection = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
        'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
        'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'null', 'BILLING_PROVIDER' => 'fake',
        'FORTNOX_PROVIDER' => 'fake', 'LARAVEL_STORAGE_PATH' => storage_path(),
        'IMPORTS_ROOT' => config('filesystems.disks.production_imports.root'),
        'APP_CONFIG_CACHE' => env('APP_CONFIG_CACHE', storage_path('framework/import-drill-config.php'))];
    $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/import-crash-worker.php'), $mode,
        (string) $job->company_id, (string) $job->id], base_path(), $environment);
    $worker->setTimeout(40)->run();

    return $worker;
}

test('XLSX API and a real import worker recover checkpoints after abrupt process exits', function (string $mode, int $exitCode, int $committed) {
    expect(DB::connection()->getDriverName())->toBe('mysql')->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    config(['queue.default' => 'database', 'imports.queue_threshold_rows' => 1]);
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    app(CatalogCapacityReconciler::class)->reconcile($company->id, true);
    CompanyMembership::factory()->owner()->create(['user_id' => $actor->id, 'company_id' => $company->id]);
    $token = issueCompanyApiToken($actor, $company, [ApiTokenAbility::ImportsWrite->value, ApiTokenAbility::ImportsRead->value])->plainTextToken;
    $source = tempnam(sys_get_temp_dir(), 'np-import-drill-');
    try {
        app(StreamingXlsxWriter::class)->write($source, [
            ['name', 'slug'], ['One', 'one'], ['Two', 'two'], ['Three', 'three'], ['Four', 'four'], ['Five', 'five'],
        ]);
        $upload = $this->withToken($token)->post('/api/v1/catalog/imports', [
            'file' => new UploadedFile($source, 'supplier.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'mode' => 'create', 'chunk_size' => 2,
        ])->assertCreated()->assertHeader('X-Request-ID');
        $job = ImportJob::query()->forCompany($company)->where('uuid', $upload->json('data.uuid'))->sole();
        $url = '/api/v1/catalog/imports/'.$job->uuid;
        $this->withToken($token)->postJson($url.'/preview', ['mapping' => ['name' => 'name', 'slug' => 'slug']])
            ->assertOk()->assertJsonPath('data.valid_rows', 5)->assertJsonPath('data.invalid_rows', 0);
        $this->withToken($token)->postJson($url.'/apply')->assertStatus(202)->assertJsonPath('meta.queued', true);
        $originalQueueId = DB::table('jobs')->where('queue', 'imports')->sole()->id;
        $worker = runImportDrillWorker($job, $mode);
        expect($worker->getExitCode())->toBe($exitCode, $worker->getErrorOutput())
            ->and($job->refresh()->processed_rows)->toBe($committed)->and($job->created_rows)->toBe($committed)
            ->and($job->skipped_rows)->toBe(0)->and($company->products()->count())->toBe($committed)
            ->and($company->catalogCapacity()->value('products_count'))->toBe($committed)
            ->and($job->records()->where('status', 'applied')->count())->toBe($committed)
            ->and($job->records()->where('status', 'valid')->count())->toBe(5 - $committed)
            ->and($job->errors()->count())->toBe(0)
            ->and(DB::table('jobs')->where('id', $originalQueueId)->whereNotNull('reserved_at')->count())->toBe(1);

        // Expire the abandoned reservation without waiting six minutes. The real
        // worker's attempt limit then invokes failed(), preserving the checkpoint.
        DB::table('jobs')->where('id', $originalQueueId)->update(['reserved_at' => now()->timestamp - 1000]);
        $recovery = runImportDrillWorker($job, 'normal');
        expect($recovery->getExitCode())->toBe(0, $recovery->getErrorOutput())
            ->and($job->refresh()->status->value)->toBe('failed')
            ->and($job->processed_rows)->toBe($committed)->and($job->skipped_rows)->toBe(0);
        $this->withToken($token)->postJson($url.'/apply')->assertStatus(202);
        for ($attempt = 0; $attempt < 6 && DB::table('jobs')->where('queue', 'imports')->exists(); $attempt++) {
            $next = runImportDrillWorker($job, 'normal');
            expect($next->getExitCode())->toBe(0, $next->getErrorOutput());
        }
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.processed_rows', 5)->assertJsonPath('data.created_rows', 5)->assertJsonPath('data.skipped_rows', 0);
        expect($company->products()->orderBy('slug')->pluck('slug')->all())->toBe(['five', 'four', 'one', 'three', 'two'])
            ->and($company->catalogCapacity()->value('products_count'))->toBe(5)
            ->and($company->productVariants()->count())->toBe(5)
            ->and($job->records()->where('status', 'applied')->count())->toBe(5)
            ->and($job->records()->where('status', 'failed')->count())->toBe(0)
            ->and(AuditLog::query()->where('company_id', $company->id)->where('event', 'catalog.product.created')->count())->toBe(5)
            ->and(DB::table('jobs')->where('queue', 'imports')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(1);
        $status = JobStatus::query()->forCompany($company)->where('idempotency_key', 'import:'.$company->id.':'.$job->uuid)->sole();
        expect($status->status->value)->toBe('succeeded')->and($status->progress_current)->toBe(5)
            ->and($status->failure_code)->toBeNull()->and($status->failure_message)->toBeNull()->and($status->failed_at)->toBeNull();
        $this->actingAs($actor);
        app(CurrentCompany::class)->set($company);
        $this->get(route('job-center.show', $status))->assertOk()->assertDontSee('import_failed');
    } finally {
        unlink($source);
        Storage::disk('production_imports')->deleteDirectory('companies/'.$company->uuid);
    }
})->with([['before-commit', 81, 0], ['after-commit', 82, 2], ['after-continuation', 83, 2]]);
