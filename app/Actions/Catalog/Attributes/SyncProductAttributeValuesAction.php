<?php

namespace App\Actions\Catalog\Attributes;

use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\CompanyPermission;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\CatalogWriteTransaction;
use App\Support\Catalog\NormalizedAttributeValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SyncProductAttributeValuesAction extends AttributeAction
{
    /** @param array<string, mixed> $payload */
    public function execute(User $actor, Company $company, Product $product, array $payload): Product
    {
        return $this->sync($actor, $company, $product, $payload, mergeExisting: false);
    }

    /**
     * Preserve omitted active values, then validate the complete resulting payload.
     * Archived values remain untouched, as in the full-replacement contract.
     *
     * @param  array<string, mixed>  $payload
     */
    public function merge(User $actor, Company $company, Product $product, array $payload): Product
    {
        return $this->sync($actor, $company, $product, $payload, mergeExisting: true);
    }

    /**
     * Import consumers use the product identity, not hydrated response relations.
     * Validation, merge, writes and audit use the same locked execution path.
     *
     * @param  array<string, mixed>  $payload
     */
    public function mergeForImport(User $actor, Company $company, Product $product, array $payload): void
    {
        $this->sync($actor, $company, $product, $payload, mergeExisting: true, reload: false);
    }

    /** @param array<string, mixed> $payload */
    private function sync(User $actor, Company $company, Product $product, array $payload, bool $mergeExisting, bool $reload = true): Product
    {
        $company = $this->authorize($actor, $company, CompanyPermission::CatalogUpdate, catalogWrite: true);

        if ((int) $product->company_id !== (int) $company->getKey()) {
            throw AttributeOperationException::tenantMismatch();
        }
        $this->assertProductEditable($product);

        return app(CatalogWriteTransaction::class)->run(function () use ($actor, $company, $product, $payload, $mergeExisting, $reload): Product {
            $company = $this->authorize($actor, $company, CompanyPermission::CatalogUpdate, catalogWrite: true);
            $product = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $this->assertProductEditable($product);
            $definitions = AttributeDefinition::query()
                ->forCompany($company)
                ->where('status', AttributeDefinitionStatus::Active->value)
                ->whereIn('scope', [AttributeScope::Product->value, AttributeScope::Both->value])
                ->with(['options' => fn ($query) => $query->forCompany($company)->ordered()->sharedLock()])
                ->orderBy('id')
                ->sharedLock()
                ->get();
            $existing = ProductAttributeValue::query()->forceIndex('product_attr_values_company_product_index')
                ->forCompany($company)
                ->where('product_id', $product->getKey())
                ->with(['selectedOptions' => fn ($query) => $query->forCompany($company)->orderBy('attribute_options.id')->sharedLock()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('attribute_definition_id');
            if ($mergeExisting) {
                $payload = $this->mergedPayload($definitions, $existing, $payload);
            }
            $normalized = $this->normalizePayload($company, $definitions, $payload);
            [$changedCodes, $clearedCodes] = $this->persist($company, $product, $existing, $normalized);

            if ($changedCodes !== [] || $clearedCodes !== []) {
                $this->auditLogger->logTenant($company, AuditEvent::CatalogProductAttributesUpdated, $actor, $product, [
                    'product_uuid' => $product->uuid,
                    'changed_attribute_codes' => $changedCodes,
                    'cleared_attribute_codes' => $clearedCodes,
                ]);
            }

            if (! $reload) {
                return $product;
            }

            // A no-op must also return current state rather than the outer transaction's old snapshot.
            return Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail()
                ->load([
                    'attributeValues' => fn ($query) => $query->forceIndex('product_attr_values_company_product_index')->forCompany($company)->orderBy('id')->sharedLock(),
                    'attributeValues.definition' => fn ($query) => $query->forCompany($company)->sharedLock(),
                    'attributeValues.selectedOption' => fn ($query) => $query->forCompany($company)->sharedLock(),
                    'attributeValues.selectedOptions' => fn ($query) => $query->forCompany($company)->orderBy('attribute_options.id')->sharedLock(),
                ]);
        });
    }

    /**
     * Build from the same locked state used by validation and persistence.
     * Omitted existing values are revalidated, including selectable options.
     *
     * @param  Collection<int, AttributeDefinition>  $definitions
     * @param  Collection<int|string, ProductAttributeValue>  $existing
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergedPayload(Collection $definitions, Collection $existing, array $payload): array
    {
        $retained = [];
        foreach ($definitions as $definition) {
            $value = $existing->get($definition->getKey());
            if ($value instanceof ProductAttributeValue) {
                $retained[$definition->uuid] = match ($definition->type) {
                    AttributeDataType::Text => $value->value_text,
                    AttributeDataType::Integer => $value->value_integer,
                    AttributeDataType::Decimal => $value->value_decimal,
                    AttributeDataType::Boolean => $value->value_boolean ? '1' : '0',
                    AttributeDataType::Date => $value->value_date?->format('Y-m-d'),
                    AttributeDataType::Select => $value->value_option_id,
                    AttributeDataType::Multiselect => $value->selectedOptions->pluck('id')->all(),
                };
            }
        }

        return array_replace($retained, $payload);
    }

    /**
     * Full-replacement contract: every active product/both Definition shown by the form is normalized;
     * a missing key is an explicit clear. Archived Definition values remain untouched and read-only.
     *
     * @param  Collection<int, AttributeDefinition>  $definitions
     * @param  array<string, mixed>  $payload
     * @return list<NormalizedAttributeValue>
     */
    private function normalizePayload(Company $company, Collection $definitions, array $payload): array
    {
        $known = $definitions->pluck('uuid')->all();

        if (array_diff(array_keys($payload), $known) !== []) {
            throw AttributeOperationException::invalid('attributes', 'The payload contains unavailable attributes.');
        }

        return $definitions->map(fn (AttributeDefinition $definition): NormalizedAttributeValue => $this->validator->normalize(
            $company,
            $definition,
            AttributeScope::Product,
            $payload[$definition->uuid] ?? null,
        ))->all();
    }

    /**
     * @param  Collection<int|string, ProductAttributeValue>  $existing
     * @param  list<NormalizedAttributeValue>  $normalized
     * @return array{list<string>, list<string>}
     */
    private function persist(Company $company, Product $product, Collection $existing, array $normalized): array
    {
        $changed = [];
        $cleared = [];

        foreach ($normalized as $item) {
            $value = $existing->get($item->definition->getKey());

            if ($item->clear) {
                if ($value instanceof ProductAttributeValue) {
                    $value->delete();
                    $cleared[] = $item->definition->code;
                }

                continue;
            }

            if (! $value instanceof ProductAttributeValue) {
                $value = new ProductAttributeValue;
                $value->forceFill([
                    'company_id' => $company->getKey(),
                    'product_id' => $product->getKey(),
                    'attribute_definition_id' => $item->definition->getKey(),
                    ...$item->columns,
                ])->save();
                $this->replaceOptions($value, $item->optionIds, $company);
                $changed[] = $item->definition->code;

                continue;
            }

            $currentOptions = $value->selectedOptions->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

            if ($this->sameColumns($value, $item->columns) && $currentOptions === $item->optionIds) {
                continue;
            }

            $value->forceFill($item->columns)->save();
            $this->replaceOptions($value, $item->optionIds, $company);
            $changed[] = $item->definition->code;
        }

        return [$changed, $cleared];
    }

    /** @param array<string, mixed> $columns */
    private function sameColumns(ProductAttributeValue $value, array $columns): bool
    {
        foreach ($columns as $column => $expected) {
            $expected = is_bool($expected) ? (int) $expected : $expected;
            $actual = $value->getRawOriginal($column);

            if (($actual === null) !== ($expected === null) || ($actual !== null && (string) $actual !== (string) $expected)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<int> $optionIds */
    private function replaceOptions(ProductAttributeValue $value, array $optionIds, Company $company): void
    {
        DB::table('product_attribute_value_options')->where('product_attribute_value_id', $value->getKey())->delete();

        if ($optionIds !== []) {
            DB::table('product_attribute_value_options')->insert(array_map(fn (int $optionId): array => [
                'company_id' => $company->getKey(),
                'attribute_definition_id' => $value->attribute_definition_id,
                'product_attribute_value_id' => $value->getKey(),
                'attribute_option_id' => $optionId,
                'created_at' => now(),
            ], $optionIds));
        }
    }
}
