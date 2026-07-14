<?php

namespace App\Models\Catalog;

use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $product_id
 * @property int $attribute_definition_id
 * @property string|null $value_text
 * @property int|null $value_integer
 * @property string|null $value_decimal
 * @property bool|null $value_boolean
 * @property CarbonImmutable|null $value_date
 * @property int|null $value_option_id
 * @property AttributeDefinition $definition
 * @property AttributeOption|null $selectedOption
 * @property-read Collection<int, AttributeOption> $selectedOptions
 */
class ProductAttributeValue extends Model
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
        return $this->hasMany(ProductAttributeValueOption::class);
    }

    public function selectedOptions(): BelongsToMany
    {
        return $this->belongsToMany(
            AttributeOption::class,
            'product_attribute_value_options',
            'product_attribute_value_id',
            'attribute_option_id',
        )->withPivot(['id', 'company_id', 'attribute_definition_id', 'created_at']);
    }
}
