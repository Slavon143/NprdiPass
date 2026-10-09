<?php

use App\Jobs\Imports\ApplyImportJob;
use App\Models\Imports\ImportJob;
use App\Models\Imports\ImportRecord;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('testing') || ! str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
    throw new RuntimeException('Import crash drill requires an isolated testing schema.');
}
$mode = $argv[1] ?? '';
$companyId = (int) ($argv[2] ?? 0);
$jobId = (int) ($argv[3] ?? 0);
if (! in_array($mode, ['normal', 'before-commit', 'after-commit', 'after-continuation'], true)) {
    throw new RuntimeException('Unknown import drill mode.');
}
ImportJob::query()->where('company_id', $companyId)->findOrFail($jobId);
Http::preventStrayRequests();
if ($mode === 'before-commit') {
    ImportRecord::saved(function (ImportRecord $record) use ($companyId, $jobId): void {
        if ($record->company_id === $companyId && $record->import_job_id === $jobId
            && $record->row_number === 3 && $record->status->value === 'applied') {
            exit(81); // Two row writes exist, but their root transaction has not committed.
        }
    });
} elseif ($mode === 'after-commit') {
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use ($companyId, $jobId): void {
        if ($event->connection->transactionLevel() === 0
            && ImportJob::query()->where('company_id', $companyId)->whereKey($jobId)->value('processed_rows') > 0) {
            exit(82); // Checkpoint committed; no continuation or queue acknowledgement yet.
        }
    });
} elseif ($mode === 'after-continuation') {
    Event::listen(JobQueued::class, function (JobQueued $event) use ($companyId, $jobId): void {
        if ($event->job instanceof ApplyImportJob && $event->job->companyId === $companyId
            && $event->job->importJobId === $jobId) {
            exit(83); // Continuation persisted; original reserved queue row remains.
        }
    });
}
$kernel->call('queue:work', ['--once' => true, '--queue' => 'imports', '--tries' => 1, '--timeout' => 30, '--sleep' => 0]);
if ($mode !== 'normal') {
    throw new RuntimeException('The import crash injection point was not reached.');
}
