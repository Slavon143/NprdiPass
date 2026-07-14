<?php

namespace App\Models\Catalog;

use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VariantAttributeValue extends Model
{
    use HasCompanyScope;

    protected $fillable = [
        'value_text',
        'value_integer',
        'value_decimal',
        'value_boolean',
        'value_date',
        'value_option_id',
    ];

    protected function casts(): array
    {
        return [
            'value_integer' => 'integer',
            'value_decimal' => 'decimal:4',
            'value_boolean' => 'boolean',
            'value_date' => 'immutable_date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinition::class, 'attribute_definition_id');
    }

    public function selectedOption(): BelongsTo
    {
        return $this->belongsTo(AttributeOption::class, 'value_option_id');
    }

    public function multiselectAssignments(): HasMany
    {
        return $this->hasMany(VariantAttributeValueOption::class);
    }

    public function selectedOptions(): BelongsToMany
    {
        return $this->belongsToMany(
            AttributeOption::class,
            'variant_attribute_value_options',
            'variant_attribute_value_id',
            'attribute_option_id',
        )->withPivot(['id', 'company_id', 'attribute_definition_id', 'created_at']);
    }
}
