<?php

namespace App\Models\Catalog;

use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Models\Catalog\Concerns\HasCompanyScope;
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
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property AttributeDataType $type
 * @property AttributeScope $scope
 * @property string|null $unit
 * @property bool $required
 * @property bool $filterable
 * @property bool $searchable
 * @property array<string, int|float|string>|null $validation_rules
 * @property int $sort_order
 * @property AttributeDefinitionStatus $status
 */
class AttributeDefinition extends Model
{
    use HasCompanyScope, HasUuid;

    protected $fillable = [
        'name',
        'code',
        'description',
        'type',
        'scope',
        'unit',
        'required',
        'filterable',
        'searchable',
        'validation_rules',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => AttributeDataType::class,
            'scope' => AttributeScope::class,
            'required' => 'boolean',
            'filterable' => 'boolean',
            'searchable' => 'boolean',
            'validation_rules' => 'array',
            'sort_order' => 'integer',
            'status' => AttributeDefinitionStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(AttributeOption::class);
    }

    public function productValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function variantValues(): HasMany
    {
        return $this->hasMany(VariantAttributeValue::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), AttributeDefinitionStatus::Active->value);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), AttributeDefinitionStatus::Archived->value);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('name'));
    }

    public function usesOptions(): bool
    {
        return in_array($this->type, [AttributeDataType::Select, AttributeDataType::Multiselect], true);
    }
}
