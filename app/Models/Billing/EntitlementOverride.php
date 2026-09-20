<?php

namespace App\Models\Billing;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property mixed $value
 */
class EntitlementOverride extends Model
{
    protected $fillable = [
        'company_id',
        'key',
        'value',
        'reason',
        'created_by',
        'expires_at',
        'revoked_at',
        'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<EntitlementOverride> $query */
    public function scopeForCompany(Builder $query, Company $company): void
    {
        $query->where('company_id', $company->getKey());
    }

    /** @param Builder<EntitlementOverride> $query */
    public function scopeActive(Builder $query): void
    {
        $query
            ->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
