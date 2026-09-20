<?php

namespace App\Services\Catalog\Taxonomy;

use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryTemplateAttributeRequirement;
use App\Enums\Catalog\ProductVariantStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use Illuminate\Database\Eloquent\Collection;

class ProductTaxonomyDiagnosticsService
{
    /**
     * @return array{needs_review: bool, template_id: int|null, missing_required: list<AttributeDefinition>, missing_recommended: list<AttributeDefinition>}
     */
    public function diagnose(Product $product): array
    {
        $product->loadMissing([
            'primaryCategory.activeTemplate.attributes.definition',
            'attributeValues',
        ]);
        $variants = ProductVariant::query()
            ->where('product_id', $product->getKey())
            ->where('status', '!=', ProductVariantStatus::Archived->value)
            ->with('attributeValues')
            ->get();
        $template = $product->primaryCategory?->activeTemplate;

        if ($template === null) {
            return [
                'needs_review' => (bool) $product->taxonomy_needs_review,
                'template_id' => null,
                'missing_required' => [],
                'missing_recommended' => [],
            ];
        }

        $missingRequired = [];
        $missingRecommended = [];

        foreach ($template->attributes as $assignment) {
            if (! $this->hasValue($product, $assignment->definition, $variants)) {
                $target = $assignment->requirement === CategoryTemplateAttributeRequirement::Required
                    ? $missingRequired
                    : $missingRecommended;
                $target[] = $assignment->definition;

                if ($assignment->requirement === CategoryTemplateAttributeRequirement::Required) {
                    $missingRequired = $target;
                } else {
                    $missingRecommended = $target;
                }
            }
        }

        return [
            'needs_review' => (bool) $product->taxonomy_needs_review || $missingRequired !== [],
            'template_id' => $template->getKey(),
            'missing_required' => $missingRequired,
            'missing_recommended' => $missingRecommended,
        ];
    }

    /** @param Collection<int, ProductVariant> $variants */
    private function hasValue(Product $product, AttributeDefinition $definition, Collection $variants): bool
    {
        $productHasValue = $product->attributeValues->contains(
            fn ($value): bool => (int) $value->attribute_definition_id === (int) $definition->getKey(),
        );
        $variantsHaveValue = $variants->isNotEmpty() && $variants->every(
            fn ($variant): bool => $variant->attributeValues->contains(
                fn ($value): bool => (int) $value->attribute_definition_id === (int) $definition->getKey(),
            ),
        );

        return match ($definition->scope) {
            AttributeScope::Product => $productHasValue,
            AttributeScope::Variant => $variantsHaveValue,
            AttributeScope::Both => $productHasValue || $variantsHaveValue,
        };
    }
}
