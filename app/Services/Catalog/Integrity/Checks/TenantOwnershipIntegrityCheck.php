<?php

namespace App\Services\Catalog\Integrity\Checks;

use App\Contracts\Catalog\Integrity\CatalogIntegrityCheck;
use App\Data\Catalog\Integrity\CatalogIntegrityIssue;
use App\Enums\Catalog\CatalogIntegritySeverity;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

class TenantOwnershipIntegrityCheck implements CatalogIntegrityCheck
{
    private const string CODE = 'tenant_ownership_integrity';

    public function code(): string
    {
        return self::CODE;
    }

    public function check(Company $company): array
    {
        $issues = [];
        $companyId = $company->getKey();

        $this->checkCategoriesViaParentChain($company, $companyId, $issues);
        $this->checkProductsViaCategories($company, $companyId, $issues);
        $this->checkVariantsViaProduct($company, $companyId, $issues);
        $this->checkAttributeDefinitions($company, $companyId, $issues);
        $this->checkProductAttributeValuesViaProduct($company, $companyId, $issues);
        $this->checkVariantAttributeValuesViaVariant($company, $companyId, $issues);
        $this->checkProductMediaViaOwner($company, $companyId, $issues);
        $this->checkCategoryProductPivot($company, $companyId, $issues);

        return $issues;
    }

    private function checkCategoriesViaParentChain(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('categories AS c')
            ->join('categories AS p', 'c.parent_id', '=', 'p.id')
            ->where('c.company_id', $companyId)
            ->where('p.company_id', '!=', $companyId)
            ->select('c.id', 'c.uuid', 'c.parent_id', 'p.uuid AS parent_uuid', 'p.company_id AS parent_company_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.tenant.product_company_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'category',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Category (ID %d, UUID %s) parent chain mismatch: parent (ID %d) belongs to company %d.',
                    $row->id,
                    $row->uuid,
                    $row->parent_id,
                    $row->parent_company_id,
                ),
                context: [
                    'category_id' => $row->id,
                    'parent_id' => $row->parent_id,
                    'parent_uuid' => $row->parent_uuid,
                    'parent_company_id' => $row->parent_company_id,
                    'expected_company_id' => $companyId,
                ],
                suggestedRemediation: 'Reassign the category parent to one owned by this company.',
            );
        }
    }

    private function checkProductsViaCategories(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('category_product AS cp')
            ->join('products AS p', 'cp.product_id', '=', 'p.id')
            ->join('categories AS c', 'cp.category_id', '=', 'c.id')
            ->where('p.company_id', $companyId)
            ->where('c.company_id', '!=', $companyId)
            ->select('p.id', 'p.uuid', 'c.id AS category_id', 'c.uuid AS category_uuid', 'c.company_id AS category_company_id')
            ->distinct()
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.tenant.product_company_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'product',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Product (ID %d, UUID %s) is linked to category (ID %d) from company %d via pivot.',
                    $row->id,
                    $row->uuid,
                    $row->category_id,
                    $row->category_company_id,
                ),
                context: [
                    'product_id' => $row->id,
                    'category_id' => $row->category_id,
                    'category_uuid' => $row->category_uuid,
                    'category_company_id' => $row->category_company_id,
                    'expected_company_id' => $companyId,
                ],
                suggestedRemediation: 'Remove the cross-tenant pivot link.',
            );
        }
    }

    private function checkVariantsViaProduct(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_variants AS v')
            ->join('products AS p', 'v.product_id', '=', 'p.id')
            ->where('v.company_id', $companyId)
            ->where('p.company_id', '!=', $companyId)
            ->select('v.id', 'v.uuid', 'v.product_id', 'p.uuid AS product_uuid', 'p.company_id AS product_company_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.tenant.product_company_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'variant',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Variant (ID %d, UUID %s) company_id %d does not match its product company_id %d.',
                    $row->id,
                    $row->uuid,
                    $companyId,
                    $row->product_company_id,
                ),
                context: [
                    'variant_id' => $row->id,
                    'product_id' => $row->product_id,
                    'product_uuid' => $row->product_uuid,
                    'product_company_id' => $row->product_company_id,
                    'expected_company_id' => $companyId,
                ],
                suggestedRemediation: 'Fix the variant company_id to match its product.',
            );
        }
    }

    private function checkAttributeDefinitions(Company $company, int $companyId, array &$issues): void
    {
        $pavRows = DB::table('product_attribute_values AS pav')
            ->join('attribute_definitions AS ad', 'pav.attribute_definition_id', '=', 'ad.id')
            ->where('pav.company_id', $companyId)
            ->where('ad.company_id', '!=', $companyId)
            ->select('ad.id', 'ad.uuid', 'ad.company_id', 'pav.id AS value_id')
            ->distinct()
            ->get();

        foreach ($pavRows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.tenant.product_company_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'attribute_definition',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'AttributeDefinition (ID %d, UUID %s) belongs to company %d, used by product attribute values in company %d.',
                    $row->id,
                    $row->uuid,
                    $row->company_id,
                    $companyId,
                ),
                context: [
                    'definition_id' => $row->id,
                    'definition_company_id' => $row->company_id,
                    'expected_company_id' => $companyId,
                ],
                suggestedRemediation: 'Remove or reassign the attribute definition.',
            );
        }

        $vavRows = DB::table('variant_attribute_values AS vav')
            ->join('attribute_definitions AS ad', 'vav.attribute_definition_id', '=', 'ad.id')
            ->where('vav.company_id', $companyId)
            ->where('ad.company_id', '!=', $companyId)
            ->select('ad.id', 'ad.uuid', 'ad.company_id', 'vav.id AS value_id')
            ->distinct()
            ->get();

        foreach ($vavRows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.tenant.product_company_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'attribute_definition',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'AttributeDefinition (ID %d, UUID %s) belongs to company %d, used by variant attribute values in company %d.',
                    $row->id,
                    $row->uuid,
                    $row->company_id,
                    $companyId,
                ),
                context: [
                    'definition_id' => $row->id,
                    'definition_company_id' => $row->company_id,
                    'expected_company_id' => $companyId,
                ],
                suggestedRemediation: 'Remove or reassign the attribute definition.',
            );
        }
    }

    private function checkProductAttributeValuesViaProduct(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_attribute_values AS pav')
            ->join('products AS p', 'pav.product_id', '=', 'p.id')
            ->where('pav.company_id', $companyId)
            ->where('p.company_id', '!=', $companyId)
            ->select('pav.id', 'pav.product_id', 'p.uuid AS product_uuid', 'p.company_id AS product_company_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.tenant.product_company_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'product_attribute_value',
                resourceUuid: (string) $row->id,
                message: sprintf(
                    'ProductAttributeValue (ID %d) company_id differs from its product company_id.',
                    $row->id,
                ),
                context: [
                    'value_id' => $row->id,
                    'product_id' => $row->product_id,
                    'product_uuid' => $row->product_uuid,
                    'product_company_id' => $row->product_company_id,
                    'expected_company_id' => $companyId,
                ],
                suggestedRemediation: 'Fix the attribute value company_id.',
            );
        }
    }

    private function checkVariantAttributeValuesViaVariant(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('variant_attribute_values AS vav')
            ->join('product_variants AS v', 'vav.product_variant_id', '=', 'v.id')
            ->where('vav.company_id', $companyId)
            ->where('v.company_id', '!=', $companyId)
            ->select('vav.id', 'vav.product_variant_id', 'v.uuid AS variant_uuid', 'v.company_id AS variant_company_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.tenant.product_company_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'variant_attribute_value',
                resourceUuid: (string) $row->id,
                message: sprintf(
                    'VariantAttributeValue (ID %d) company_id differs from its variant company_id.',
                    $row->id,
                ),
                context: [
                    'value_id' => $row->id,
                    'variant_id' => $row->product_variant_id,
                    'variant_uuid' => $row->variant_uuid,
                    'variant_company_id' => $row->variant_company_id,
                    'expected_company_id' => $companyId,
                ],
                suggestedRemediation: 'Fix the attribute value company_id.',
            );
        }
    }

    private function checkProductMediaViaOwner(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_media AS pm')
            ->join('products AS p', 'pm.product_id', '=', 'p.id')
            ->where('pm.company_id', $companyId)
            ->where('p.company_id', '!=', $companyId)
            ->whereNull('pm.deleted_at')
            ->select('pm.id', 'pm.uuid', 'pm.product_id', 'p.uuid AS product_uuid', 'p.company_id AS product_company_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.tenant.product_company_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'product_media',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'ProductMedia (ID %d) company_id differs from its product company_id.',
                    $row->id,
                ),
                context: [
                    'media_id' => $row->id,
                    'product_id' => $row->product_id,
                    'product_uuid' => $row->product_uuid,
                    'product_company_id' => $row->product_company_id,
                    'expected_company_id' => $companyId,
                ],
                suggestedRemediation: 'Fix the media company_id.',
            );
        }

        $variantMediaRows = DB::table('product_media AS pm')
            ->join('product_variants AS v', 'pm.product_variant_id', '=', 'v.id')
            ->where('pm.company_id', $companyId)
            ->where('v.company_id', '!=', $companyId)
            ->whereNull('pm.deleted_at')
            ->select('pm.id', 'pm.uuid', 'pm.product_variant_id', 'v.uuid AS variant_uuid', 'v.company_id AS variant_company_id')
            ->get();

        foreach ($variantMediaRows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.tenant.product_company_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'product_media',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'ProductMedia (ID %d) company_id differs from its variant company_id.',
                    $row->id,
                ),
                context: [
                    'media_id' => $row->id,
                    'product_variant_id' => $row->product_variant_id,
                    'variant_uuid' => $row->variant_uuid,
                    'variant_company_id' => $row->variant_company_id,
                    'expected_company_id' => $companyId,
                ],
                suggestedRemediation: 'Fix the media company_id.',
            );
        }
    }

    private function checkCategoryProductPivot(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('category_product AS cp')
            ->join('categories AS c', 'cp.category_id', '=', 'c.id')
            ->join('products AS p', 'cp.product_id', '=', 'p.id')
            ->where('cp.company_id', $companyId)
            ->where(function ($query) use ($companyId): void {
                $query->where('c.company_id', '!=', $companyId)
                    ->orWhere('p.company_id', '!=', $companyId);
            })
            ->select(
                'cp.id',
                'cp.category_id',
                'cp.product_id',
                'c.uuid AS category_uuid',
                'c.company_id AS category_company_id',
                'p.uuid AS product_uuid',
                'p.company_id AS product_company_id',
            )
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.tenant.product_company_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'category_product',
                resourceUuid: (string) $row->id,
                message: sprintf(
                    'CategoryProduct pivot (ID %d) has mismatched company_ids: category=%d, product=%d, pivot=%d.',
                    $row->id,
                    $row->category_company_id,
                    $row->product_company_id,
                    $companyId,
                ),
                context: [
                    'pivot_id' => $row->id,
                    'category_id' => $row->category_id,
                    'category_uuid' => $row->category_uuid,
                    'category_company_id' => $row->category_company_id,
                    'product_id' => $row->product_id,
                    'product_uuid' => $row->product_uuid,
                    'product_company_id' => $row->product_company_id,
                    'expected_company_id' => $companyId,
                ],
                suggestedRemediation: 'Remove the invalid pivot row.',
            );
        }
    }
}
