<?php

namespace App\Services\Catalog\Integrity\Checks;

use App\Contracts\Catalog\Integrity\CatalogIntegrityCheck;
use App\Data\Catalog\Integrity\CatalogIntegrityIssue;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CatalogIntegritySeverity;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

class AttributeIntegrityCheck implements CatalogIntegrityCheck
{
    private const string CODE = 'attribute_integrity';

    /** @var string[] */
    private const array SELECT_TYPES = ['select', 'multiselect'];

    public function code(): string
    {
        return self::CODE;
    }

    public function check(Company $company): array
    {
        $issues = [];
        $companyId = $company->getKey();

        $this->checkDefinitionTenantMismatch($company, $companyId, $issues);
        $this->checkOptionWrongDefinition($company, $companyId, $issues);
        $this->checkOptionExistsForNonSelect($company, $companyId, $issues);
        $this->checkProductValueTenantMismatch($company, $companyId, $issues);
        $this->checkVariantValueTenantMismatch($company, $companyId, $issues);
        $this->checkScopeExcludesOwner($company, $companyId, $issues);
        $this->checkMultipleTypedValues($company, $companyId, $issues);
        $this->checkSelectOptionWrongDefinition($company, $companyId, $issues);

        return $issues;
    }

    private function checkDefinitionTenantMismatch(Company $company, int $companyId, array &$issues): void
    {
        $pavRows = DB::table('product_attribute_values AS pav')
            ->join('attribute_definitions AS ad', 'pav.attribute_definition_id', '=', 'ad.id')
            ->where('pav.company_id', $companyId)
            ->where('ad.company_id', '!=', $companyId)
            ->select('pav.id', 'ad.id AS definition_id', 'ad.uuid AS definition_uuid', 'ad.company_id AS definition_company_id')
            ->get();

        foreach ($pavRows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.attribute.definition_tenant_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'attribute_definition',
                resourceUuid: $row->definition_uuid,
                message: sprintf(
                    'AttributeDefinition (ID %d, UUID %s) referenced by product attribute value (ID %d) belongs to company %d, not %d.',
                    $row->definition_id,
                    $row->definition_uuid,
                    $row->id,
                    $row->definition_company_id,
                    $companyId,
                ),
                context: [
                    'definition_id' => $row->definition_id,
                    'definition_company_id' => $row->definition_company_id,
                    'product_attribute_value_id' => $row->id,
                ],
                suggestedRemediation: 'Reassign the attribute value to a definition owned by this company.',
            );
        }

        $vavRows = DB::table('variant_attribute_values AS vav')
            ->join('attribute_definitions AS ad', 'vav.attribute_definition_id', '=', 'ad.id')
            ->where('vav.company_id', $companyId)
            ->where('ad.company_id', '!=', $companyId)
            ->select('vav.id', 'ad.id AS definition_id', 'ad.uuid AS definition_uuid', 'ad.company_id AS definition_company_id')
            ->get();

        foreach ($vavRows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.attribute.definition_tenant_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'attribute_definition',
                resourceUuid: $row->definition_uuid,
                message: sprintf(
                    'AttributeDefinition (ID %d, UUID %s) referenced by variant attribute value (ID %d) belongs to company %d, not %d.',
                    $row->definition_id,
                    $row->definition_uuid,
                    $row->id,
                    $row->definition_company_id,
                    $companyId,
                ),
                context: [
                    'definition_id' => $row->definition_id,
                    'definition_company_id' => $row->definition_company_id,
                    'variant_attribute_value_id' => $row->id,
                ],
                suggestedRemediation: 'Reassign the attribute value to a definition owned by this company.',
            );
        }
    }

    private function checkOptionWrongDefinition(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('attribute_options AS ao')
            ->leftJoin('attribute_definitions AS ad', function ($join): void {
                $join->on('ao.attribute_definition_id', '=', 'ad.id')
                    ->on('ao.company_id', '=', 'ad.company_id');
            })
            ->where('ao.company_id', $companyId)
            ->whereNull('ad.id')
            ->select('ao.id', 'ao.attribute_definition_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.attribute.option_wrong_definition',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'attribute_option',
                resourceUuid: (string) $row->id,
                message: sprintf(
                    'AttributeOption (ID %d) references definition %d which does not exist or belongs to a different company.',
                    $row->id,
                    $row->attribute_definition_id,
                ),
                context: [
                    'option_id' => $row->id,
                    'attribute_definition_id' => $row->attribute_definition_id,
                ],
                suggestedRemediation: 'Remove the orphaned option or correct its attribute_definition_id.',
            );
        }
    }

    private function checkOptionExistsForNonSelect(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('attribute_definitions AS ad')
            ->join('attribute_options AS ao', 'ad.id', '=', 'ao.attribute_definition_id')
            ->where('ad.company_id', $companyId)
            ->whereNotIn('ad.type', self::SELECT_TYPES)
            ->select('ad.id', 'ad.uuid', 'ad.type', DB::raw('COUNT(ao.id) AS option_count'))
            ->groupBy('ad.id', 'ad.uuid', 'ad.type')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.attribute.option_exists_for_non_select',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'attribute_definition',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'AttributeDefinition (ID %d, type "%s") has %d options but is not a select/multiselect type.',
                    $row->id,
                    $row->type,
                    $row->option_count,
                ),
                context: [
                    'definition_id' => $row->id,
                    'type' => $row->type,
                    'option_count' => $row->option_count,
                ],
                suggestedRemediation: 'Remove the orphaned options from the non-select attribute definition.',
            );
        }
    }

    private function checkProductValueTenantMismatch(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_attribute_values AS pav')
            ->join('products AS p', 'pav.product_id', '=', 'p.id')
            ->where('pav.company_id', $companyId)
            ->where('p.company_id', '!=', $companyId)
            ->select('pav.id', 'pav.product_id', 'p.uuid AS product_uuid', 'p.company_id AS product_company_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.attribute.product_value_tenant_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'product_attribute_value',
                resourceUuid: (string) $row->id,
                message: sprintf(
                    'ProductAttributeValue (ID %d) company_id %d differs from Product company_id %d.',
                    $row->id,
                    $companyId,
                    $row->product_company_id,
                ),
                context: [
                    'value_id' => $row->id,
                    'product_id' => $row->product_id,
                    'product_uuid' => $row->product_uuid,
                    'product_company_id' => $row->product_company_id,
                ],
                suggestedRemediation: 'Fix the attribute value company_id to match the product.',
            );
        }
    }

    private function checkVariantValueTenantMismatch(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('variant_attribute_values AS vav')
            ->join('product_variants AS v', 'vav.product_variant_id', '=', 'v.id')
            ->where('vav.company_id', $companyId)
            ->where('v.company_id', '!=', $companyId)
            ->select('vav.id', 'vav.product_variant_id', 'v.uuid AS variant_uuid', 'v.company_id AS variant_company_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.attribute.variant_value_tenant_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'variant_attribute_value',
                resourceUuid: (string) $row->id,
                message: sprintf(
                    'VariantAttributeValue (ID %d) company_id %d differs from Variant company_id %d.',
                    $row->id,
                    $companyId,
                    $row->variant_company_id,
                ),
                context: [
                    'value_id' => $row->id,
                    'variant_id' => $row->product_variant_id,
                    'variant_uuid' => $row->variant_uuid,
                    'variant_company_id' => $row->variant_company_id,
                ],
                suggestedRemediation: 'Fix the attribute value company_id to match the variant.',
            );
        }
    }

    private function checkScopeExcludesOwner(Company $company, int $companyId, array &$issues): void
    {
        $productScopeRows = DB::table('product_attribute_values AS pav')
            ->join('attribute_definitions AS ad', 'pav.attribute_definition_id', '=', 'ad.id')
            ->where('pav.company_id', $companyId)
            ->where('ad.scope', AttributeScope::Variant->value)
            ->select('pav.id', 'ad.uuid AS definition_uuid', 'ad.id AS definition_id', 'ad.scope')
            ->get();

        foreach ($productScopeRows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.attribute.scope_excludes_owner',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'product_attribute_value',
                resourceUuid: (string) $row->id,
                message: sprintf(
                    'ProductAttributeValue (ID %d) references definition (ID %d) with scope "%s", expected product or both.',
                    $row->id,
                    $row->definition_id,
                    $row->scope,
                ),
                context: [
                    'value_id' => $row->id,
                    'definition_id' => $row->definition_id,
                    'definition_uuid' => $row->definition_uuid,
                    'scope' => $row->scope,
                ],
                suggestedRemediation: 'Change the attribute definition scope or move the value to a variant.',
            );
        }

        $variantScopeRows = DB::table('variant_attribute_values AS vav')
            ->join('attribute_definitions AS ad', 'vav.attribute_definition_id', '=', 'ad.id')
            ->where('vav.company_id', $companyId)
            ->where('ad.scope', AttributeScope::Product->value)
            ->select('vav.id', 'ad.uuid AS definition_uuid', 'ad.id AS definition_id', 'ad.scope')
            ->get();

        foreach ($variantScopeRows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.attribute.scope_excludes_owner',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'variant_attribute_value',
                resourceUuid: (string) $row->id,
                message: sprintf(
                    'VariantAttributeValue (ID %d) references definition (ID %d) with scope "%s", expected variant or both.',
                    $row->id,
                    $row->definition_id,
                    $row->scope,
                ),
                context: [
                    'value_id' => $row->id,
                    'definition_id' => $row->definition_id,
                    'definition_uuid' => $row->definition_uuid,
                    'scope' => $row->scope,
                ],
                suggestedRemediation: 'Change the attribute definition scope or move the value to a product.',
            );
        }
    }

    private function checkMultipleTypedValues(Company $company, int $companyId, array &$issues): void
    {
        $pavRows = DB::table('product_attribute_values')
            ->where('company_id', $companyId)
            ->select('id', 'value_text', 'value_integer', 'value_decimal', 'value_boolean', 'value_date', 'value_option_id')
            ->get();

        foreach ($pavRows as $row) {
            $filled = 0;
            if ($row->value_text !== null) {
                $filled++;
            }
            if ($row->value_integer !== null) {
                $filled++;
            }
            if ($row->value_decimal !== null) {
                $filled++;
            }
            if ($row->value_boolean !== null) {
                $filled++;
            }
            if ($row->value_date !== null) {
                $filled++;
            }
            if ($row->value_option_id !== null) {
                $filled++;
            }

            if ($filled > 1) {
                $issues[] = new CatalogIntegrityIssue(
                    code: 'catalog.attribute.multiple_typed_values',
                    severity: CatalogIntegritySeverity::Error,
                    companyUuid: $company->uuid,
                    resourceType: 'product_attribute_value',
                    resourceUuid: (string) $row->id,
                    message: sprintf(
                        'ProductAttributeValue (ID %d) has %d typed value fields filled.',
                        $row->id,
                        $filled,
                    ),
                    context: [
                        'value_id' => $row->id,
                        'filled_count' => $filled,
                    ],
                    suggestedRemediation: 'Set only the appropriate typed value field for the definition type.',
                );
            }
        }

        $vavRows = DB::table('variant_attribute_values')
            ->where('company_id', $companyId)
            ->select('id', 'value_text', 'value_integer', 'value_decimal', 'value_boolean', 'value_date', 'value_option_id')
            ->get();

        foreach ($vavRows as $row) {
            $filled = 0;
            if ($row->value_text !== null) {
                $filled++;
            }
            if ($row->value_integer !== null) {
                $filled++;
            }
            if ($row->value_decimal !== null) {
                $filled++;
            }
            if ($row->value_boolean !== null) {
                $filled++;
            }
            if ($row->value_date !== null) {
                $filled++;
            }
            if ($row->value_option_id !== null) {
                $filled++;
            }

            if ($filled > 1) {
                $issues[] = new CatalogIntegrityIssue(
                    code: 'catalog.attribute.multiple_typed_values',
                    severity: CatalogIntegritySeverity::Error,
                    companyUuid: $company->uuid,
                    resourceType: 'variant_attribute_value',
                    resourceUuid: (string) $row->id,
                    message: sprintf(
                        'VariantAttributeValue (ID %d) has %d typed value fields filled.',
                        $row->id,
                        $filled,
                    ),
                    context: [
                        'value_id' => $row->id,
                        'filled_count' => $filled,
                    ],
                    suggestedRemediation: 'Set only the appropriate typed value field for the definition type.',
                );
            }
        }
    }

    private function checkSelectOptionWrongDefinition(Company $company, int $companyId, array &$issues): void
    {
        $pavRows = DB::table('product_attribute_values AS pav')
            ->join('attribute_options AS ao', 'pav.value_option_id', '=', 'ao.id')
            ->where('pav.company_id', $companyId)
            ->whereColumn('ao.attribute_definition_id', '!=', 'pav.attribute_definition_id')
            ->whereNotNull('pav.value_option_id')
            ->select('pav.id', 'pav.attribute_definition_id', 'pav.value_option_id', 'ao.attribute_definition_id AS option_definition_id')
            ->get();

        foreach ($pavRows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.attribute.select_option_wrong_definition',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'product_attribute_value',
                resourceUuid: (string) $row->id,
                message: sprintf(
                    'ProductAttributeValue (ID %d) value_option_id %d refers to option from definition %d, not %d.',
                    $row->id,
                    $row->value_option_id,
                    $row->option_definition_id,
                    $row->attribute_definition_id,
                ),
                context: [
                    'value_id' => $row->id,
                    'definition_id' => $row->attribute_definition_id,
                    'value_option_id' => $row->value_option_id,
                    'option_definition_id' => $row->option_definition_id,
                ],
                suggestedRemediation: 'Update the value_option_id to an option belonging to the correct definition.',
            );
        }

        $vavRows = DB::table('variant_attribute_values AS vav')
            ->join('attribute_options AS ao', 'vav.value_option_id', '=', 'ao.id')
            ->where('vav.company_id', $companyId)
            ->whereColumn('ao.attribute_definition_id', '!=', 'vav.attribute_definition_id')
            ->whereNotNull('vav.value_option_id')
            ->select('vav.id', 'vav.attribute_definition_id', 'vav.value_option_id', 'ao.attribute_definition_id AS option_definition_id')
            ->get();

        foreach ($vavRows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.attribute.select_option_wrong_definition',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'variant_attribute_value',
                resourceUuid: (string) $row->id,
                message: sprintf(
                    'VariantAttributeValue (ID %d) value_option_id %d refers to option from definition %d, not %d.',
                    $row->id,
                    $row->value_option_id,
                    $row->option_definition_id,
                    $row->attribute_definition_id,
                ),
                context: [
                    'value_id' => $row->id,
                    'definition_id' => $row->attribute_definition_id,
                    'value_option_id' => $row->value_option_id,
                    'option_definition_id' => $row->option_definition_id,
                ],
                suggestedRemediation: 'Update the value_option_id to an option belonging to the correct definition.',
            );
        }
    }
}
