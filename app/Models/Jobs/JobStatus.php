<?php

namespace App\Models\Jobs;

use App\Enums\Jobs\JobStatusState;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int|null $user_id
 * @property int|null $retry_of_id
 * @property string $name
 * @property string $type
 * @property string $queue
 * @property JobStatusState $status
 * @property int $progress_current
 * @property int|null $progress_total
 * @property array<string, mixed>|null $payload_summary
 * @property array<string, mixed>|null $result_summary
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property int $attempts
 * @property string $idempotency_key
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $cancel_requested_at
 */
class JobStatus extends Model
{
    use HasUuid;

    protected $fillable = [
        'company_id',
        'user_id',
        'retry_of_id',
        'name',
        'type',
        'queue',
        'status',
        'progress_current',
        'progress_total',
        'payload_summary',
        'result_summary',
        'failure_code',
        'failure_message',
        'attempts',
        'idempotency_key',
        'started_at',
        'completed_at',
        'failed_at',
        'cancel_requested_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => JobStatusState::class,
            'payload_summary' => 'array',
            'result_summary' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    public function scopeForCompany(Builder $query, Company|int $company): Builder
    {
        return $query->where(
            $query->qualifyColumn('company_id'),
            $company instanceof Company ? $company->id : $company,
        );
    }
}
