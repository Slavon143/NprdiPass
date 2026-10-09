<?php

namespace App\Services\Imports;

use App\Data\Imports\PlannedImportRow;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Imports\ImportMode;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Exceptions\Catalog\VariantOperationException;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Support\Catalog\AttributeValueValidator;
use App\Support\Catalog\CatalogIdentifierNormalizer;
use App\Support\Catalog\VariantIdentifierNormalizer;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ProductImportRowPlanner
{
    public const FIELDS = ['name', 'slug', 'short_description', 'description', 'brand', 'manufacturer', ...VariantIdentifierNormalizer::FIELDS];

    public function __construct(
        private readonly CatalogIdentifierNormalizer $normalizer,
        private readonly AttributeValueValidator $attributeValidator,
    ) {}

    /** @param array<string, string|null> $source @param array<string, string> $mapping */
    public function plan(Company $company, array $source, array $mapping, ImportMode $mode): PlannedImportRow
    {
        return $this->planWithLookups($company, $source, $mapping, $mode);
    }

    /** @param array<int, array<string, string|null>> $sources @param array<string, string> $mapping @return array<int, PlannedImportRow> */
    public function planBatch(Company $company, array $sources, array $mapping, ImportMode $mode): array
    {
        $slugs = [];
        $categoryValues = [];
        $categorySlugs = [];
        foreach ($sources as $source) {
            $name = $this->clean($source[$mapping['name']] ?? null) ?? '';
            $slug = isset($mapping['slug']) ? $this->clean($source[$mapping['slug']] ?? null) : null;
            $slugs[] = $this->productSlug($slug ?? $name) ?? '';
            if (isset($mapping['primary_category'])) {
                $raw = $this->clean($source[$mapping['primary_category']] ?? null);
                if ($raw !== null) {
                    $categoryValues[] = $raw;
                    $categorySlugs[] = $this->categorySlug($raw) ?? '';
                }
            }
        }
        $hasIdentifiers = array_intersect(array_keys($mapping), VariantIdentifierNormalizer::FIELDS) !== [];
        $productQuery = Product::query()->forCompany($company)->whereIn('slug_normalized', array_unique($slugs));
        $owners = null;
        if ($hasIdentifiers) {
            $productQuery->with(['defaultVariant' => fn ($query) => $query->where('company_id', $company->getKey())]);
            $skus = $gtins = [];
            foreach ($sources as $source) {
                $input = [];
                foreach (VariantIdentifierNormalizer::FIELDS as $field) {
                    if (isset($mapping[$field])) {
                        $input[$field] = $source[$mapping[$field]] ?? null;
                    }
                }
                try {
                    $ids = app(VariantIdentifierNormalizer::class)->normalize($input);
                    if ($ids['sku_normalized'] !== null) {
                        $skus[] = $ids['sku_normalized'];
                    }
                    if ($ids['gtin'] !== null) {
                        $gtins[] = $ids['gtin'];
                    }
                } catch (VariantOperationException) {
                    // The row planner reports the invalid identifier below.
                }
            }
            $owners = ProductVariant::query()->forCompany($company)
                ->where(fn ($query) => $query->whereIn('sku_normalized', array_unique($skus))->orWhereIn('gtin', array_unique($gtins)))->get();
        }
        $products = $productQuery->get()->keyBy('slug_normalized');
        $categories = Category::query()->forCompany($company)->where('status', CategoryStatus::Active->value)
            ->where(fn ($query) => $query->whereIn('uuid', array_unique($categoryValues))
                ->orWhereIn('slug_normalized', array_unique($categorySlugs)))
            ->orderBy('id')->get();
        $definitions = array_any(array_keys($mapping), fn ($field) => str_starts_with($field, 'attribute:'))
            ? $this->definitionsFor($company) : collect();
        $plans = [];
        foreach ($sources as $number => $source) {
            $plans[$number] = $this->planWithLookups($company, $source, $mapping, $mode, $products, $categories, $definitions, $owners);
        }

        return $plans;
    }

    /** @param array<string, string|null> $source @param array<string, string> $mapping
     * @param  Collection<string, Product>|null  $products  @param Collection<int, Category>|null $categories
     * @param  Collection<string, AttributeDefinition>|null  $definitions
     */
    private function planWithLookups(Company $company, array $source, array $mapping, ImportMode $mode,
        ?Collection $products = null, ?Collection $categories = null, ?Collection $definitions = null, ?Collection $owners = null): PlannedImportRow
    {
        $mapped = [];
        foreach (self::FIELDS as $field) {
            $header = $mapping[$field] ?? null;
            if (is_string($header) && array_key_exists($header, $source)) {
                $mapped[$field] = $this->clean($source[$header]);
            }
        }

        $errors = [];
        $name = $mapped['name'] ?? '';
        if ($name === '') {
            $errors[] = $this->error('name', 'required', 'Product name is required.');
        } elseif (mb_strlen($name) > 255) {
            $errors[] = $this->error('name', 'max', 'Product name may not exceed 255 characters.');
        }

        $normalizedSlug = $this->productSlug(($mapped['slug'] ?? '') !== '' ? (string) $mapped['slug'] : $name);
        $slug = $normalizedSlug ?? '';
        $mapped['slug'] = $slug;
        if ($normalizedSlug === null) {
            $errors[] = $this->error('slug', 'max', 'Product slug may not exceed 255 characters.');
        } elseif ($slug === '') {
            $errors[] = $this->error('slug', 'invalid', 'Product slug could not be generated.');
        } elseif (mb_strlen($slug) > 255) {
            $errors[] = $this->error('slug', 'max', 'Product slug may not exceed 255 characters.');
        }

        foreach (['short_description' => 500, 'description' => 10000, 'brand' => 255, 'manufacturer' => 255] as $field => $maximum) {
            if (mb_strlen((string) ($mapped[$field] ?? '')) > $maximum) {
                $errors[] = $this->error($field, 'max', "The {$field} field may not exceed {$maximum} characters.");
            }
        }

        if (isset($mapping['primary_category'])) {
            $rawCategory = $this->clean($source[$mapping['primary_category']] ?? null);
            $mapped['primary_category_uuid'] = null;
            if ($rawCategory !== null) {
                $categorySlug = $this->categorySlug($rawCategory);
                $category = $categories !== null
                    ? $categories->first(fn ($candidate) => strcasecmp($candidate->uuid, $rawCategory) === 0 || $candidate->slug_normalized === $categorySlug)
                    : Category::query()->forCompany($company)
                        ->where('status', CategoryStatus::Active->value)
                        ->where(fn ($query) => $query->where('uuid', $rawCategory)->orWhere('slug_normalized', $categorySlug))
                        ->first();
                if ($category === null) {
                    $errors[] = $this->error('primary_category', 'not_found', 'The mapped primary category is not active or does not exist.');
                } else {
                    $mapped['primary_category_uuid'] = $category->uuid;
                }
            }
        }

        $attributeMappings = array_filter(
            $mapping,
            fn (string $header, string $field): bool => str_starts_with($field, 'attribute:'),
            ARRAY_FILTER_USE_BOTH,
        );
        if ($attributeMappings !== []) {
            $definitions ??= $this->definitionsFor($company);
            foreach ($attributeMappings as $field => $header) {
                $code = substr($field, strlen('attribute:'));
                $definition = $definitions->get($code);
                if (! $definition instanceof AttributeDefinition) {
                    $errors[] = $this->error($field, 'unavailable', "Attribute {$code} is not active for products.");

                    continue;
                }
                try {
                    $raw = $this->attributeRawValue($definition, $source[$header] ?? null);
                    $this->attributeValidator->normalize($company, $definition, AttributeScope::Product, $raw, $definition->options->all());
                    $mapped['attributes'][$definition->uuid] = $raw;
                } catch (AttributeOperationException $exception) {
                    $errors[] = $this->error($field, 'invalid_attribute_value', $exception->getMessage());
                }
            }
        }

        $product = $slug === '' ? null : ($products !== null ? $products->get($slug)
            : Product::query()->forCompany($company)->where('slug_normalized', $slug)->first());
        $operation = $product === null ? 'create' : 'update';
        $identifierInput = array_intersect_key($mapped, array_flip(VariantIdentifierNormalizer::FIELDS));
        if ($identifierInput !== []) {
            if ($product !== null) {
                $product->loadMissing(['defaultVariant' => fn ($query) => $query->where('company_id', $company->getKey())]);
                if ($product->defaultVariant === null || (int) $product->defaultVariant->product_id !== (int) $product->getKey()) {
                    $errors[] = $this->error('variant', 'unavailable', 'The default variant is unavailable.');
                }
            }
            try {
                $ids = app(VariantIdentifierNormalizer::class)->normalize($identifierInput);
                $mapped = array_replace($mapped, array_intersect_key($ids, $identifierInput));
                foreach (['sku' => 'sku_normalized', 'gtin' => 'gtin'] as $field => $column) {
                    $value = $ids[$column];
                    if ($value === null) {
                        continue;
                    }
                    $owner = $owners === null
                        ? ProductVariant::query()->forCompany($company)->where($column, $value)->first()
                        : $owners->first(fn (ProductVariant $variant) => $variant->getAttribute($column) === $value);
                    $targetVariantId = $product === null ? 0 : (int) $product->default_variant_id;
                    if ($owner !== null && (int) $owner->getKey() !== $targetVariantId) {
                        $errors[] = $this->error($field, 'already_exists', 'The identifier is already assigned to a variant in this company.');
                    }
                }
            } catch (VariantOperationException $exception) {
                $errors[] = $this->error($exception->field, 'invalid_identifier', $exception->getMessage());
            }
        }
        if ($mode === ImportMode::Create && $product !== null) {
            $errors[] = $this->error('slug', 'already_exists', 'A product with this slug already exists.');
        }
        if ($mode === ImportMode::Update && $product === null) {
            $errors[] = $this->error('slug', 'not_found', 'No product with this slug exists for update.');
        }

        return new PlannedImportRow($mapped, $operation, $product?->getKey(), $errors);
    }

    private function productSlug(string $value): ?string
    {
        try {
            return $this->normalizer->normalizeProductSlug($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function categorySlug(string $value): ?string
    {
        try {
            return $this->normalizer->normalizeCategorySlug($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function attributeRawValue(AttributeDefinition $definition, ?string $value): mixed
    {
        $value = $this->clean($value);
        if ($value === null) {
            return null;
        }

        if ($definition->type === AttributeDataType::Boolean) {
            return match (mb_strtolower($value)) {
                '1', 'yes', 'true' => '1',
                '0', 'no', 'false' => '0',
                default => $value,
            };
        }
        if ($definition->type === AttributeDataType::Select) {
            return $this->optionId($definition, $value);
        }
        if ($definition->type === AttributeDataType::Multiselect) {
            $values = array_values(array_filter(array_map('trim', explode('|', $value)), fn (string $item): bool => $item !== ''));

            return array_map(fn (string $item): int|string => $this->optionId($definition, $item), $values);
        }

        return $value;
    }

    private function optionId(AttributeDefinition $definition, string $value): int|string
    {
        $option = $definition->options->first(fn ($candidate): bool => mb_strtolower((string) $candidate->getAttribute('code')) === mb_strtolower($value)
            || mb_strtolower((string) $candidate->getAttribute('label')) === mb_strtolower($value));

        return $option?->getKey() ?? $value;
    }

    /** @return Collection<string, AttributeDefinition> */
    private function definitionsFor(Company $company): Collection
    {
        return AttributeDefinition::query()->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->whereIn('scope', [AttributeScope::Product->value, AttributeScope::Both->value])
            ->with(['options' => fn ($query) => $query->selectable()->ordered()])
            ->get()->each(fn (AttributeDefinition $definition) => $definition->setRelation('options', $definition->options->keyBy('id')))->keyBy('code');
    }

    /** @return array{field: string|null, code: string, message: string} */
    private function error(?string $field, string $code, string $message): array
    {
        return compact('field', 'code', 'message');
    }
}
