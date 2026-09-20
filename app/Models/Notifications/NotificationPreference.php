<?php

namespace App\Models\Notifications;

use App\Enums\Notifications\NotificationType;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property NotificationType $type
 * @property bool $in_app_enabled
 * @property bool $email_enabled
 * @property CarbonImmutable|null $muted_until
 */
class NotificationPreference extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'type',
        'in_app_enabled',
        'email_enabled',
        'muted_until',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'in_app_enabled' => 'boolean',
            'email_enabled' => 'boolean',
            'muted_until' => 'immutable_datetime',
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
