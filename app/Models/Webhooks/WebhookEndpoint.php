<?php

namespace App\Models\Webhooks;

use App\Enums\Webhooks\WebhookEndpointStatus;
use App\Enums\Webhooks\WebhookEventType;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $url
 * @property string $secret
 * @property list<string> $events
 * @property WebhookEndpointStatus $status
 */
class WebhookEndpoint extends Model
{
    use HasUuid;

    protected $fillable = [
        'company_id',
        'created_by',
        'url',
        'secret',
        'events',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'status' => WebhookEndpointStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function scopeForCompany(Builder $query, Company|int $company): Builder
    {
        return $query->where(
            $query->qualifyColumn('company_id'),
            $company instanceof Company ? $company->id : $company,
        );
    }

    public function accepts(WebhookEventType $eventType): bool
    {
        return $this->status === WebhookEndpointStatus::Active
            && in_array($eventType->value, $this->events, true);
    }
}
