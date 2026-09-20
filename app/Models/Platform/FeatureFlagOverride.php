<?php

namespace App\Models\Platform;

use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureFlagOverride extends Model
{
    use HasUuid;

    protected $table = 'platform_feature_flag_overrides';

    protected $fillable = [
        'company_id',
        'flag',
        'enabled',
        'reason',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForCompanyOrGlobal(Builder $query, Company $company): Builder
    {
        return $query->where(function (Builder $query) use ($company): void {
            $query->whereNull('company_id')->orWhere('company_id', $company->getKey());
        });
    }
}
