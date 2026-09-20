<?php

namespace App\Models\Fortnox;

use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $provider
 * @property FortnoxConnectionStatus $status
 * @property string|null $external_tenant_id
 * @property array<int, string>|null $scopes
 * @property array<string, string>|null $token_payload
 * @property CarbonImmutable|null $token_expires_at
 * @property int|null $connected_by
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $failure_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read User|null $connectedBy
 */
class FortnoxConnection extends Model
{
    use HasCompanyScope, HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => FortnoxConnectionStatus::class,
            'scopes' => 'array',
            'token_payload' => 'encrypted:json',
            'token_expires_at' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
