<?php

namespace App\Services\Jobs;

use App\Enums\Jobs\JobStatusState;
use App\Models\Company;
use App\Models\Jobs\JobStatus;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class JobStatusTracker
{
    /**
     * @param  array<string, mixed>  $payloadSummary
     */
    public function register(
        Company $company,
        string $type,
        string $name,
        string $idempotencyKey,
        ?User $user = null,
        string $queue = 'default',
        array $payloadSummary = [],
        ?int $progressTotal = null,
    ): JobStatus {
        return JobStatus::query()->firstOrCreate(
            [
                'company_id' => $company->getKey(),
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'user_id' => $user?->getKey(),
                'name' => $name,
                'type' => $type,
                'queue' => $queue,
                'status' => JobStatusState::Pending,
                'payload_summary' => $payloadSummary,
                'progress_total' => $progressTotal,
            ],
        );
    }

    public function markRunning(JobStatus $job): JobStatus
    {
        $job->forceFill([
            'status' => JobStatusState::Running,
            'attempts' => $job->attempts + 1,
            'started_at' => $job->started_at ?? now(),
        ])->save();

        return $job;
    }

    /**
     * @param  array<string, mixed>  $resultSummary
     */
    public function markSucceeded(JobStatus $job, array $resultSummary = []): JobStatus
    {
        $job->forceFill([
            'status' => JobStatusState::Succeeded,
            'result_summary' => $resultSummary,
            'completed_at' => now(),
        ])->save();

        return $job;
    }

    public function markFailed(JobStatus $job, string $code, string $message): JobStatus
    {
        $job->forceFill([
            'status' => JobStatusState::Failed,
            'failure_code' => $code,
            'failure_message' => $message,
            'failed_at' => now(),
        ])->save();

        return $job;
    }

    public function requestCancel(JobStatus $job): JobStatus
    {
        if (in_array($job->status, [JobStatusState::Succeeded, JobStatusState::Failed, JobStatusState::Cancelled], true)) {
            throw new ConflictHttpException('Completed jobs cannot be cancelled.');
        }

        $job->forceFill([
            'status' => JobStatusState::CancelRequested,
            'cancel_requested_at' => $job->cancel_requested_at ?? now(),
        ])->save();

        return $job;
    }

    public function markCancelled(JobStatus $job): JobStatus
    {
        $job->forceFill([
            'status' => JobStatusState::Cancelled,
            'completed_at' => now(),
        ])->save();

        return $job;
    }

    public function retry(JobStatus $job, ?User $user = null): JobStatus
    {
        if (! in_array($job->status, [JobStatusState::Failed, JobStatusState::Cancelled, JobStatusState::Skipped], true)) {
            throw new ConflictHttpException('Only failed, cancelled, or skipped jobs can be retried.');
        }

        return JobStatus::query()->create([
            'company_id' => $job->company_id,
            'user_id' => $user?->getKey() ?? $job->user_id,
            'retry_of_id' => $job->getKey(),
            'name' => $job->name,
            'type' => $job->type,
            'queue' => $job->queue,
            'status' => JobStatusState::Pending,
            'progress_total' => $job->progress_total,
            'payload_summary' => $job->payload_summary,
            'idempotency_key' => $job->idempotency_key.':retry:'.Str::uuid(),
        ]);
    }
}
