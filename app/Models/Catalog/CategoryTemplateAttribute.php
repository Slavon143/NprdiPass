<?php

namespace App\Models\Catalog;

use App\Enums\Catalog\CategoryTemplateAttributeRequirement;
use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $category_template_id
 * @property int $attribute_definition_id
 * @property CategoryTemplateAttributeRequirement $requirement
 * @property int $sort_order
 * @property AttributeDefinition $definition
 */
class CategoryTemplateAttribute extends Model
{
    use HasCompanyScope;

    protected $fillable = ['requirement', 'sort_order'];

    protected function casts(): array
    {
        return [
            'requirement' => CategoryTemplateAttributeRequirement::class,
            'sort_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CategoryTemplate::class, 'category_template_id');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinition::class, 'attribute_definition_id');
    }
}
