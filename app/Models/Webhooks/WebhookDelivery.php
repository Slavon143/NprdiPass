<?php

namespace App\Models\Webhooks;

use App\Enums\Webhooks\WebhookDeliveryStatus;
use App\Enums\Webhooks\WebhookEventType;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $webhook_endpoint_id
 * @property WebhookEventType $event_type
 * @property string|null $aggregate_type
 * @property string|null $aggregate_uuid
 * @property array<string, mixed> $payload
 * @property WebhookDeliveryStatus $status
 * @property int $attempts
 * @property int|null $response_status
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $delivered_at
 * @property string $idempotency_key
 * @property-read WebhookEndpoint|null $endpoint
 * @property-read Collection<int, WebhookDeliveryAttempt> $attemptsLog
 */
class WebhookDelivery extends Model
{
    use HasUuid;

    protected $fillable = [
        'company_id',
        'webhook_endpoint_id',
        'event_type',
        'aggregate_type',
        'aggregate_uuid',
        'payload',
        'status',
        'attempts',
        'next_attempt_at',
        'last_attempt_at',
        'delivered_at',
        'response_status',
        'response_body_excerpt',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => WebhookEventType::class,
            'payload' => 'array',
            'status' => WebhookDeliveryStatus::class,
            'next_attempt_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public function scopeForCompany(Builder $query, Company|int $company): Builder
    {
        return $query->where(
            $query->qualifyColumn('company_id'),
            $company instanceof Company ? $company->id : $company,
        );
    }

    public function attemptsLog(): HasMany
    {
        return $this->hasMany(WebhookDeliveryAttempt::class);
    }
}
