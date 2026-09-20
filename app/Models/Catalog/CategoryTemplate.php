<?php

namespace App\Models\Catalog;

use App\Enums\Catalog\CategoryTemplateStatus;
use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $category_id
 * @property string $uuid
 * @property int $version
 * @property string $name
 * @property CategoryTemplateStatus $status
 * @property string $readiness_profile
 * @property int $readiness_profile_version
 * @property string $readiness_rule_set_fingerprint
 * @property CarbonImmutable|null $activated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category $category
 * @property-read Collection<int, CategoryTemplateAttribute> $attributes
 * @property-read Collection<int, CategoryTemplateDppDefault> $dppDefaults
 */
class CategoryTemplate extends Model
{
    use HasCompanyScope, HasUuid;

    protected $fillable = ['name'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => CategoryTemplateStatus::class,
            'active_slot' => 'boolean',
            'readiness_profile_version' => 'integer',
            'activated_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(CategoryTemplateAttribute::class);
    }

    public function dppDefaults(): HasMany
    {
        return $this->hasMany(CategoryTemplateDppDefault::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }
}
