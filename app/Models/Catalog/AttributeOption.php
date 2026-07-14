<?php

namespace App\Models\Catalog;

use App\Enums\Catalog\AttributeOptionStatus;
use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $attribute_definition_id
 * @property string $label
 * @property string $code
 * @property int $sort_order
 * @property AttributeOptionStatus $status
 */
class AttributeOption extends Model
{
    use HasCompanyScope;

    protected $fillable = ['label', 'code', 'sort_order'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'status' => AttributeOptionStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinition::class, 'attribute_definition_id');
    }

    public function productSelectValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class, 'value_option_id');
    }

    public function variantSelectValues(): HasMany
    {
        return $this->hasMany(VariantAttributeValue::class, 'value_option_id');
    }

    public function productMultiselectAssignments(): HasMany
    {
        return $this->hasMany(ProductAttributeValueOption::class);
    }

    public function variantMultiselectAssignments(): HasMany
    {
        return $this->hasMany(VariantAttributeValueOption::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), AttributeOptionStatus::Active->value);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), AttributeOptionStatus::Archived->value);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('label'));
    }
}
