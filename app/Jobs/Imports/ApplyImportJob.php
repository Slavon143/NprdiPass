<?php

namespace App\Jobs\Imports;

use App\Actions\Imports\ApplyImportJobAction;
use App\Enums\Imports\ImportJobStatus;
use App\Enums\Jobs\JobStatusState;
use App\Models\Company;
use App\Models\Imports\ImportJob;
use App\Models\Jobs\JobStatus;
use App\Models\User;
use App\Services\Jobs\JobStatusTracker;
use App\Tenancy\Contracts\CurrentCompany;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ApplyImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly int $companyId,
        public readonly int $importJobId,
        public readonly int $actorId,
        public readonly ?int $jobStatusId = null,
    ) {
        $this->onQueue('imports');
    }

    public function handle(ApplyImportJobAction $action, JobStatusTracker $tracker): void
    {
        $context = app(TokenCurrentCompany::class);
        $previous = $context->get();
        $sessionContext = app(CurrentCompany::class);
        $previousSessionCompany = $sessionContext->get();
        try {
            $sessionContext->clear();
            $context->set(Company::query()->findOrFail($this->companyId));
            $this->apply($action, $tracker);
        } finally {
            $sessionContext->clear();
            if ($previousSessionCompany !== null) {
                $sessionContext->set($previousSessionCompany);
            }
            $context->clear();
            if ($previous !== null) {
                $context->set($previous);
            }
        }
    }

    private function apply(ApplyImportJobAction $action, JobStatusTracker $tracker): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        $actor = User::query()->findOrFail($this->actorId);
        $job = ImportJob::query()->forCompany($company)->findOrFail($this->importJobId);
        $jobStatus = $this->jobStatusId !== null
            ? JobStatus::query()->forCompany($company)->find($this->jobStatusId)
            : null;
        if ($jobStatus instanceof JobStatus && ! in_array($job->status, [ImportJobStatus::Completed, ImportJobStatus::Cancelled], true)) {
            $tracker->markRunning($jobStatus);
        }

        while (true) {
            $job->refresh();
            if ($job->status === ImportJobStatus::Cancelled || $job->status === ImportJobStatus::Completed) {
                if ($jobStatus instanceof JobStatus) {
                    if ($job->status === ImportJobStatus::Cancelled) {
                        $jobStatus->forceFill(['status' => JobStatusState::Cancelled, 'completed_at' => now()])->save();
                    } else {
                        $tracker->markSucceeded($jobStatus, ['import_job_uuid' => $job->uuid, 'status' => $job->status->value]);
                    }
                }

                return;
            }

            $processedBefore = $job->processed_rows;
            $job = $action->execute($actor, $company, $job);
            if ($jobStatus instanceof JobStatus) {
                $tracker->markProgress($jobStatus, $job->processed_rows, $job->total_rows);
            }
            if ($job->status !== ImportJobStatus::Processing) {
                if ($jobStatus instanceof JobStatus) {
                    if ($job->status === ImportJobStatus::Cancelled) {
                        $jobStatus->forceFill(['status' => JobStatusState::Cancelled, 'completed_at' => now()])->save();
                    } else {
                        $tracker->markSucceeded($jobStatus, ['import_job_uuid' => $job->uuid, 'status' => $job->status->value]);
                    }
                }

                return;
            }
            if ($job->processed_rows <= $processedBefore) {
                throw new \RuntimeException('Import chunk made no progress.');
            }
            if (config('queue.connections.'.config('queue.default').'.driver') !== 'sync') {
                self::dispatch($this->companyId, $this->importJobId, $this->actorId, $this->jobStatusId)->afterCommit();

                return;
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $changed = ImportJob::query()->whereKey($this->importJobId)->where('company_id', $this->companyId)
            ->whereNotIn('status', [ImportJobStatus::Completed->value, ImportJobStatus::Cancelled->value])->update([
                'status' => ImportJobStatus::Failed->value,
                'failure_message' => mb_substr($exception?->getMessage() ?? 'Queued import failed.', 0, 1000),
            ]);
        if ($changed > 0 && $this->jobStatusId !== null) {
            JobStatus::query()->whereKey($this->jobStatusId)->where('company_id', $this->companyId)->update([
                'status' => 'failed',
                'failure_code' => 'import_failed',
                'failure_message' => mb_substr($exception?->getMessage() ?? 'Queued import failed.', 0, 1000),
                'failed_at' => now(),
            ]);
        }
    }
}
