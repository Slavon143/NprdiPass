<?php

namespace App\Actions\Catalog\Attributes;

use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\CompanyPermission;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Catalog\VariantAttributeValue;
use App\Models\Company;
use App\Models\User;
use App\Support\Catalog\NormalizedAttributeValue;
use Illuminate\Support\Facades\DB;

class SyncVariantAttributeValuesAction extends AttributeAction
{
    /** @param array<string, mixed> $payload */
    public function execute(User $actor, Company $company, Product $product, ProductVariant $variant, array $payload): ProductVariant
    {
        $company = $this->authorize($actor, $company, CompanyPermission::CatalogUpdate);
        $this->assertOwners($company, $product, $variant);

        return DB::transaction(function () use ($actor, $company, $product, $variant, $payload): ProductVariant {
            $company = $this->authorize($actor, $company, CompanyPermission::CatalogUpdate);
            $product = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $variant = ProductVariant::query()->forCompany($company)
                ->where('product_id', $product->getKey())
                ->whereKey($variant->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $definitions = AttributeDefinition::query()
                ->forCompany($company)
                ->where('status', AttributeDefinitionStatus::Active->value)
                ->whereIn('scope', [AttributeScope::Variant->value, AttributeScope::Both->value])
                ->with(['options' => fn ($query) => $query->ordered()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $existing = VariantAttributeValue::query()
                ->forCompany($company)
                ->where('product_variant_id', $variant->getKey())
                ->with('selectedOptions')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('attribute_definition_id');
            $known = $definitions->pluck('uuid')->all();

            if (array_diff(array_keys($payload), $known) !== []) {
                throw AttributeOperationException::invalid('attributes', 'The payload contains unavailable attributes.');
            }

            $normalized = $definitions->map(fn (AttributeDefinition $definition): NormalizedAttributeValue => $this->validator->normalize(
                $company,
                $definition,
                AttributeScope::Variant,
                $payload[$definition->uuid] ?? null,
            ));
            $changed = [];
            $cleared = [];

            foreach ($normalized as $item) {
                $value = $existing->get($item->definition->getKey());

                if ($item->clear) {
                    if ($value instanceof VariantAttributeValue) {
                        $value->delete();
                        $cleared[] = $item->definition->code;
                    }

                    continue;
                }

                if (! $value instanceof VariantAttributeValue) {
                    $value = new VariantAttributeValue;
                    $value->forceFill([
                        'company_id' => $company->getKey(),
                        'product_variant_id' => $variant->getKey(),
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

            if ($changed !== [] || $cleared !== []) {
                $this->auditLogger->logTenant($company, AuditEvent::CatalogVariantAttributesUpdated, $actor, $variant, [
                    'product_uuid' => $product->uuid,
                    'variant_uuid' => $variant->uuid,
                    'changed_attribute_codes' => $changed,
                    'cleared_attribute_codes' => $cleared,
                ]);
            }

            return $variant->refresh()->load(['attributeValues.definition', 'attributeValues.selectedOption', 'attributeValues.selectedOptions']);
        });
    }

    private function assertOwners(Company $company, Product $product, ProductVariant $variant): void
    {
        if ((int) $product->company_id !== (int) $company->getKey()
            || (int) $variant->company_id !== (int) $company->getKey()
            || (int) $variant->product_id !== (int) $product->getKey()) {
            throw AttributeOperationException::tenantMismatch();
        }
    }

    /** @param array<string, mixed> $columns */
    private function sameColumns(VariantAttributeValue $value, array $columns): bool
    {
        foreach ($columns as $column => $expected) {
            $actual = $value->getRawOriginal($column);

            if (($actual === null) !== ($expected === null) || ($actual !== null && (string) $actual !== (string) $expected)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<int> $optionIds */
    private function replaceOptions(VariantAttributeValue $value, array $optionIds, Company $company): void
    {
        DB::table('variant_attribute_value_options')->where('variant_attribute_value_id', $value->getKey())->delete();

        if ($optionIds !== []) {
            DB::table('variant_attribute_value_options')->insert(array_map(fn (int $optionId): array => [
                'company_id' => $company->getKey(),
                'attribute_definition_id' => $value->attribute_definition_id,
                'variant_attribute_value_id' => $value->getKey(),
                'attribute_option_id' => $optionId,
                'created_at' => now(),
            ], $optionIds));
        }
    }
}
