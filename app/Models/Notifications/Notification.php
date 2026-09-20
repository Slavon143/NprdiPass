<?php

namespace App\Models\Notifications;

use App\Enums\Notifications\NotificationSeverity;
use App\Enums\Notifications\NotificationType;
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
 * @property NotificationType $type
 * @property string $preference_key
 * @property NotificationSeverity $severity
 * @property string $title
 * @property string|null $body
 * @property string|null $action_url
 * @property array<string, mixed>|null $payload
 * @property string $idempotency_key
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $dismissed_at
 */
class Notification extends Model
{
    use HasUuid;

    protected $fillable = [
        'company_id',
        'user_id',
        'type',
        'preference_key',
        'severity',
        'title',
        'body',
        'action_url',
        'payload',
        'idempotency_key',
        'read_at',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'severity' => NotificationSeverity::class,
            'payload' => 'array',
            'read_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
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

    public function scopeForCompany(Builder $query, Company|int $company): Builder
    {
        return $query->where(
            $query->qualifyColumn('company_id'),
            $company instanceof Company ? $company->id : $company,
        );
    }
}
