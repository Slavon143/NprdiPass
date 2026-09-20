<?php

namespace App\Services\Imports;

use App\Data\Imports\PlannedImportRow;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Imports\ImportMode;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Support\Catalog\AttributeValueValidator;
use App\Support\Catalog\CatalogIdentifierNormalizer;
use Illuminate\Support\Collection;

class ProductImportRowPlanner
{
    public const FIELDS = ['name', 'slug', 'short_description', 'description', 'brand', 'manufacturer'];

    /** @var array<int, Collection<string, AttributeDefinition>> */
    private array $definitions = [];

    public function __construct(
        private readonly CatalogIdentifierNormalizer $normalizer,
        private readonly AttributeValueValidator $attributeValidator,
    ) {}

    /** @param array<string, string|null> $source @param array<string, string> $mapping */
    public function plan(Company $company, array $source, array $mapping, ImportMode $mode): PlannedImportRow
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

        $slug = $this->normalizer->normalizeProductSlug(($mapped['slug'] ?? '') !== '' ? (string) $mapped['slug'] : $name);
        $mapped['slug'] = $slug;
        if ($slug === '') {
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
                $categorySlug = $this->normalizer->normalizeCategorySlug($rawCategory);
                $category = Category::query()->forCompany($company)
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
            $definitions = $this->definitionsFor($company);
            foreach ($attributeMappings as $field => $header) {
                $code = substr($field, strlen('attribute:'));
                $definition = $definitions->get($code);
                if (! $definition instanceof AttributeDefinition) {
                    $errors[] = $this->error($field, 'unavailable', "Attribute {$code} is not active for products.");

                    continue;
                }
                try {
                    $raw = $this->attributeRawValue($definition, $source[$header] ?? null);
                    $this->attributeValidator->normalize($company, $definition, AttributeScope::Product, $raw);
                    $mapped['attributes'][$definition->uuid] = $raw;
                } catch (AttributeOperationException $exception) {
                    $errors[] = $this->error($field, 'invalid_attribute_value', $exception->getMessage());
                }
            }
        }

        $product = $slug === '' ? null : Product::query()->forCompany($company)->where('slug_normalized', $slug)->first();
        $operation = $product === null ? 'create' : 'update';
        if ($mode === ImportMode::Create && $product !== null) {
            $errors[] = $this->error('slug', 'already_exists', 'A product with this slug already exists.');
        }
        if ($mode === ImportMode::Update && $product === null) {
            $errors[] = $this->error('slug', 'not_found', 'No product with this slug exists for update.');
        }

        return new PlannedImportRow($mapped, $operation, $product?->getKey(), $errors);
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
        return $this->definitions[$company->getKey()] ??= AttributeDefinition::query()->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->whereIn('scope', [AttributeScope::Product->value, AttributeScope::Both->value])
            ->with(['options' => fn ($query) => $query->selectable()->ordered()])
            ->get()->keyBy('code');
    }

    /** @return array{field: string|null, code: string, message: string} */
    private function error(?string $field, string $code, string $message): array
    {
        return compact('field', 'code', 'message');
    }
}
