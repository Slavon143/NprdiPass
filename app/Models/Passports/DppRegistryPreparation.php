<?php

namespace App\Models\Passports;

use App\Models\Company;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $uuid
 * @property int $company_id
 * @property int $passport_id
 * @property int $published_version_id
 * @property string $environment
 * @property string $status
 * @property string $snapshot_checksum
 * @property string|null $passport_uri
 * @property string|null $registration_uri
 * @property array<int, string> $blockers
 */
class DppRegistryPreparation extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'company_id', 'passport_id', 'published_version_id'];

    protected function casts(): array
    {
        return ['blockers' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @param Builder<static> $query @return Builder<static> */
    public function scopeForCompany(Builder $query, Company $company): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), $company->getKey());
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Registry preparations are immutable.'));
        static::deleting(fn () => throw new LogicException('Registry preparations are immutable.'));
    }
}
