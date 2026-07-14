<?php

namespace App\Services\Catalog;

use App\Data\Catalog\Lifecycle\ProductActivationReadiness;
use App\Data\Catalog\Lifecycle\ReadinessItem;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Exceptions\Catalog\LifecycleOperationException;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Catalog\VariantAttributeValue;
use App\Models\Company;
use App\Services\Catalog\Media\CatalogMediaStorage;
use App\Support\Catalog\CatalogIdentifierNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

class ProductActivationReadinessService
{
    public function __construct(
        private readonly CatalogIdentifierNormalizer $normalizer,
        private readonly CatalogMediaStorage $mediaStorage,
    ) {}

    public function evaluate(Company $company, Product $product): ProductActivationReadiness
    {
        if ((int) $product->company_id !== (int) $company->getKey()) {
            throw LifecycleOperationException::unavailable();
        }

        $product = Product::query()->forCompany($company)
            ->whereKey($product->getKey())
            ->with([
                'primaryCategory',
                'categories',
                'defaultVariant.attributeValues.definition',
                'defaultVariant.attributeValues.selectedOption',
                'defaultVariant.attributeValues.selectedOptions',
                'variants',
                'primaryMedia',
                'attributeValues.definition',
                'attributeValues.selectedOption',
                'attributeValues.selectedOptions',
            ])->first();

        if (! $product instanceof Product) {
            throw LifecycleOperationException::unavailable();
        }

        /** @var Collection<int, AttributeDefinition> $definitions */
        $definitions = AttributeDefinition::query()->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->where('required', true)
            ->orderBy('id')
            ->get();
        $productDefinitions = $definitions->filter(fn (AttributeDefinition $definition): bool => in_array(
            $definition->scope,
            [AttributeScope::Product, AttributeScope::Both],
            true,
        ));
        $variantDefinitions = $definitions->filter(fn (AttributeDefinition $definition): bool => in_array(
            $definition->scope,
            [AttributeScope::Variant, AttributeScope::Both],
            true,
        ));
        $blockers = [];
        $warnings = [];
        $addBlocker = static function (string $code, string $message, string $section, Model $entity) use (&$blockers): void {
            $blockers[] = new ReadinessItem($code, $message, $section, class_basename($entity), $entity->getAttribute('uuid'));
        };
        $addWarning = static function (string $code, string $message, string $section, Model $entity) use (&$warnings): void {
            $warnings[] = new ReadinessItem($code, $message, $section, class_basename($entity), $entity->getAttribute('uuid'));
        };

        if ($product->status !== ProductStatus::Draft) {
            $addBlocker('invalid_product_status', 'Only a draft product can be activated.', 'lifecycle', $product);
        }

        if (trim($product->name) === '') {
            $addBlocker('missing_product_name', 'Product name is required.', 'details', $product);
        }

        $slug = trim($product->slug);
        if ($slug === '' || $this->normalizer->normalizeProductSlug($slug) !== $slug || $product->slug_normalized !== $slug) {
            $addBlocker('missing_product_slug', 'Product slug must be present and normalized.', 'details', $product);
        }

        $primaryCategory = $product->primaryCategory;
        if ($primaryCategory === null) {
            $addBlocker('missing_primary_category', 'A primary Category is required.', 'categories', $product);
        } elseif ((int) $primaryCategory->company_id !== (int) $company->getKey()
            || ! $product->categories->contains(fn ($category): bool => $category->is($primaryCategory))) {
            $addBlocker('invalid_primary_category', 'The primary Category must belong to the Product and Company.', 'categories', $product);
        } elseif ($primaryCategory->status !== CategoryStatus::Active) {
            $addBlocker('archived_primary_category', 'The primary Category is archived.', 'categories', $primaryCategory);
        }

        foreach ($product->categories as $category) {
            if ($primaryCategory?->is($category) !== true && $category->status === CategoryStatus::Archived) {
                $addWarning('archived_secondary_category', "Secondary Category {$category->name} is archived.", 'categories', $category);
            }
        }

        $availableVariants = $product->variants->filter(
            fn (ProductVariant $variant): bool => $variant->status !== ProductVariantStatus::Archived,
        );
        if ($availableVariants->isEmpty()) {
            $addBlocker('no_available_variants', 'The Product needs at least one non-archived Variant.', 'variants', $product);
        }

        $defaultVariant = $product->defaultVariant;
        $validDefault = $defaultVariant instanceof ProductVariant
            && (int) $defaultVariant->company_id === (int) $company->getKey()
            && (int) $defaultVariant->product_id === (int) $product->getKey();
        if ($defaultVariant === null) {
            $addBlocker('missing_default_variant', 'The Product does not have a default Variant.', 'variants', $product);
        } elseif (! $validDefault) {
            $addBlocker('invalid_default_variant', 'The default Variant does not belong to this Product and Company.', 'variants', $product);
        } elseif ($defaultVariant->status === ProductVariantStatus::Archived) {
            $addBlocker('invalid_default_variant', 'The default Variant is archived.', 'variants', $defaultVariant);
        }

        $productValues = $product->attributeValues->keyBy('attribute_definition_id');
        foreach ($productDefinitions as $definition) {
            $value = $productValues->get($definition->getKey());
            $this->checkRequiredValue($company, $definition, $value, $product, 'product', $blockers);
        }

        if ($validDefault && $defaultVariant->status !== ProductVariantStatus::Archived) {
            $variantValues = $defaultVariant->attributeValues->keyBy('attribute_definition_id');
            foreach ($variantDefinitions as $definition) {
                $value = $variantValues->get($definition->getKey());
                $this->checkRequiredValue($company, $definition, $value, $defaultVariant, 'variant', $blockers);
            }

            if (trim((string) $defaultVariant->sku) === '') {
                $addWarning('missing_variant_sku', 'The default Variant has no SKU.', 'variants', $defaultVariant);
            }

            if (trim((string) $defaultVariant->gtin) === '') {
                $addWarning('missing_variant_gtin', 'The default Variant has no GTIN.', 'variants', $defaultVariant);
            }
        }

        if ($product->primary_media_id === null) {
            $addWarning('missing_primary_media', 'The Product has no primary image.', 'media', $product);
        } else {
            $media = $product->primaryMedia;
            if (! $media instanceof ProductMedia
                || (int) $media->company_id !== (int) $company->getKey()
                || (int) $media->product_id !== (int) $product->getKey()
                || $media->product_variant_id !== null
                || ! $this->primaryMediaFileExists($media->storage_path)) {
                $addBlocker('missing_primary_media_file', 'The primary Product image is unavailable.', 'media', $product);
            }
        }

        if (trim((string) $product->brand) === '') {
            $addWarning('missing_product_brand', 'The Product has no brand.', 'details', $product);
        }

        if (trim((string) $product->manufacturer) === '') {
            $addWarning('missing_product_manufacturer', 'The Product has no manufacturer.', 'details', $product);
        }

        return new ProductActivationReadiness(
            $blockers === [],
            $blockers,
            $warnings,
            CarbonImmutable::now(),
            $productDefinitions->count(),
            $variantDefinitions->count(),
            $product->variants->count(),
        );
    }

    private function primaryMediaFileExists(string $path): bool
    {
        try {
            return $path !== '' && $this->mediaStorage->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<ReadinessItem>  $blockers
     */
    private function checkRequiredValue(
        Company $company,
        AttributeDefinition $definition,
        ProductAttributeValue|VariantAttributeValue|null $value,
        Model $entity,
        string $scope,
        array &$blockers,
    ): void {
        $missingCode = $scope === 'product' ? 'missing_required_product_attribute' : 'missing_required_variant_attribute';
        if ($value === null) {
            $blockers[] = new ReadinessItem($missingCode, "Missing required {$scope} attribute: {$definition->name}.", 'attributes', class_basename($entity), $entity->getAttribute('uuid'));

            return;
        }

        $invalidCode = $this->storedValueError($company, $definition, $value);
        if ($invalidCode !== null) {
            $message = $invalidCode === 'archived_attribute_option'
                ? "Required attribute {$definition->name} uses an archived option."
                : "Required attribute {$definition->name} has an invalid value.";
            $blockers[] = new ReadinessItem($invalidCode, $message, 'attributes', class_basename($entity), $entity->getAttribute('uuid'));
        }
    }

    private function storedValueError(
        Company $company,
        AttributeDefinition $definition,
        ProductAttributeValue|VariantAttributeValue $value,
    ): ?string {
        if ((int) $value->company_id !== (int) $company->getKey()
            || (int) $value->attribute_definition_id !== (int) $definition->getKey()) {
            return 'invalid_attribute_value';
        }

        $rules = $definition->validation_rules ?? [];

        return match ($definition->type) {
            AttributeDataType::Text => $this->textError($value->value_text, $rules),
            AttributeDataType::Integer => $this->numberError($value->value_integer, $rules),
            AttributeDataType::Decimal => $this->numberError($value->value_decimal, $rules),
            AttributeDataType::Boolean => $value->value_boolean === null ? 'invalid_attribute_value' : null,
            AttributeDataType::Date => $this->dateError($value->value_date?->format('Y-m-d'), $rules),
            AttributeDataType::Select => $this->selectError($company, $definition, $value),
            AttributeDataType::Multiselect => $this->multiselectError($company, $definition, $value, $rules),
        };
    }

    /** @param array<string, int|float|string> $rules */
    private function textError(?string $value, array $rules): ?string
    {
        $length = mb_strlen((string) $value);

        return $value === null || trim($value) === '' || $length < (int) ($rules['min_length'] ?? 0) || $length > (int) ($rules['max_length'] ?? 1000)
            ? 'invalid_attribute_value'
            : null;
    }

    /** @param array<string, int|float|string> $rules */
    private function numberError(int|string|null $value, array $rules): ?string
    {
        if ($value === null || ! is_numeric($value)) {
            return 'invalid_attribute_value';
        }

        $number = (float) $value;

        return (isset($rules['min']) && $number < (float) $rules['min'])
            || (isset($rules['max']) && $number > (float) $rules['max'])
            ? 'invalid_attribute_value'
            : null;
    }

    /** @param array<string, int|float|string> $rules */
    private function dateError(?string $value, array $rules): ?string
    {
        return $value === null
            || (isset($rules['min_date']) && $value < (string) $rules['min_date'])
            || (isset($rules['max_date']) && $value > (string) $rules['max_date'])
            ? 'invalid_attribute_value'
            : null;
    }

    private function selectError(Company $company, AttributeDefinition $definition, ProductAttributeValue|VariantAttributeValue $value): ?string
    {
        $option = $value->selectedOption;
        if ($option === null || (int) $option->company_id !== (int) $company->getKey()
            || (int) $option->attribute_definition_id !== (int) $definition->getKey()) {
            return 'invalid_attribute_value';
        }

        return $option->status === AttributeOptionStatus::Archived ? 'archived_attribute_option' : null;
    }

    /** @param array<string, int|float|string> $rules */
    private function multiselectError(Company $company, AttributeDefinition $definition, ProductAttributeValue|VariantAttributeValue $value, array $rules): ?string
    {
        $options = $value->selectedOptions;
        $count = $options->count();
        if ($count < max(1, (int) ($rules['min_selections'] ?? 0)) || $count > (int) ($rules['max_selections'] ?? 200)) {
            return 'invalid_attribute_value';
        }

        foreach ($options as $option) {
            if ((int) $option->company_id !== (int) $company->getKey()
                || (int) $option->attribute_definition_id !== (int) $definition->getKey()) {
                return 'invalid_attribute_value';
            }

            if ($option->status === AttributeOptionStatus::Archived) {
                return 'archived_attribute_option';
            }
        }

        return null;
    }
}
