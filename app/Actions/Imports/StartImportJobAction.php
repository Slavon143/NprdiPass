<?php

namespace App\Actions\Imports;

use App\Enums\Imports\ImportJobStatus;
use App\Jobs\Imports\ApplyImportJob;
use App\Models\Company;
use App\Models\Imports\ImportJob;
use App\Models\Jobs\JobStatus;
use App\Models\User;
use App\Services\Jobs\JobStatusTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartImportJobAction
{
    public function __construct(
        private readonly ApplyImportJobAction $apply,
        private readonly JobStatusTracker $tracker,
    ) {}

    /** @return array{ImportJob, JobStatus, bool} */
    public function execute(User $actor, Company $company, ImportJob $job): array
    {
        return DB::transaction(function () use ($actor, $company, $job): array {
            $this->apply->assertCanManage($actor, $company, $job);
            $job = ImportJob::query()->forCompany($company)->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            $this->apply->assertCanApply($actor, $company, $job);
            if ($job->status === ImportJobStatus::Processing || $job->cancel_requested_at !== null) {
                throw ValidationException::withMessages(['job' => 'This import is already processing or cancellation was requested.']);
            }
            $status = $this->tracker->register(
                $company, 'imports.apply', 'Product import',
                'import:'.$company->getKey().':'.$job->uuid,
                $actor, 'imports', ['import_job_uuid' => $job->uuid], $job->total_rows,
            );
            $queued = $job->total_rows >= (int) config('imports.queue_threshold_rows');
            $job->forceFill(['status' => ImportJobStatus::Processing, 'started_at' => $job->started_at ?? now()])->save();
            if ($queued) {
                ApplyImportJob::dispatch($company->getKey(), $job->getKey(), $actor->getKey(), $status->getKey())->afterCommit();
            }

            return [$job, $status, $queued];
        });
    }

    public function cancel(User $actor, Company $company, ImportJob $job): void
    {
        DB::transaction(function () use ($actor, $company, $job): void {
            $this->apply->assertCanManage($actor, $company, $job);
            $job = ImportJob::query()->forCompany($company)->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            $this->apply->assertCanManage($actor, $company, $job);
            abort_unless($job->status === ImportJobStatus::Processing, 409);
            $job->forceFill(['cancel_requested_at' => now()])->save();
        });
    }
}
