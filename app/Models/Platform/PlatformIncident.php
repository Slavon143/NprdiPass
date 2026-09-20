<?php

namespace App\Models\Platform;

use App\Enums\Platform\PlatformIncidentSeverity;
use App\Enums\Platform\PlatformIncidentStatus;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property PlatformIncidentSeverity $severity
 * @property PlatformIncidentStatus $status
 * @property CarbonImmutable|null $resolved_at
 */
class PlatformIncident extends Model
{
    use HasUuid;

    protected $fillable = [
        'company_id',
        'type',
        'severity',
        'status',
        'title',
        'description',
        'context',
        'reported_by',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'severity' => PlatformIncidentSeverity::class,
            'status' => PlatformIncidentStatus::class,
            'context' => 'array',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
