<?php

namespace App\Models\Platform;

use App\Enums\Platform\PlatformIncidentSeverity;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property PlatformIncidentSeverity $severity
 * @property CarbonImmutable $occurred_at
 */
class AbuseEvent extends Model
{
    use HasUuid;

    protected $table = 'platform_abuse_events';

    protected $fillable = [
        'company_id',
        'user_id',
        'type',
        'source',
        'severity',
        'context',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'severity' => PlatformIncidentSeverity::class,
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
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
}
