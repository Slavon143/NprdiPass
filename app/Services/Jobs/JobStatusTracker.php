<?php

namespace App\Services\Jobs;

use App\Enums\Jobs\JobStatusState;
use App\Models\Company;
use App\Models\Jobs\JobStatus;
use App\Models\User;

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
            'failed_at' => null,
            'failure_code' => null,
            'failure_message' => null,
            'completed_at' => null,
            'result_summary' => null,
        ])->save();

        return $job;
    }

    public function markProgress(JobStatus $job, int $current, ?int $total = null): JobStatus
    {
        $job->forceFill([
            'progress_current' => max(0, $current),
            'progress_total' => $total ?? $job->progress_total,
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
            'failed_at' => null,
            'failure_code' => null,
            'failure_message' => null,
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
}
