<?php

namespace App\Services\Catalog\Integrity\Checks;

use App\Contracts\Catalog\Integrity\CatalogIntegrityCheck;
use App\Data\Catalog\Integrity\CatalogIntegrityIssue;
use App\Enums\Catalog\CatalogIntegritySeverity;
use App\Models\Company;
use App\Services\Catalog\CategoryHierarchyService;
use Illuminate\Support\Facades\DB;

class CategoryIntegrityCheck implements CatalogIntegrityCheck
{
    private const string CODE = 'category_integrity';

    public function code(): string
    {
        return self::CODE;
    }

    public function check(Company $company): array
    {
        $issues = [];
        $companyId = $company->getKey();

        $this->checkParentTenantMismatch($company, $companyId, $issues);
        $this->checkSelfParent($company, $companyId, $issues);
        $this->checkDepthExceeded($company, $companyId, $issues);
        $this->checkProductPrimaryCategoryTenantMismatch($company, $companyId, $issues);
        $this->checkPivotTenantMismatch($company, $companyId, $issues);
        $this->checkProductPrimaryMissingFromPivot($company, $companyId, $issues);

        return $issues;
    }

    private function checkParentTenantMismatch(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('categories AS c')
            ->join('categories AS p', 'c.parent_id', '=', 'p.id')
            ->where('c.company_id', $companyId)
            ->where('p.company_id', '!=', $companyId)
            ->select('c.id', 'c.uuid', 'c.parent_id', 'p.uuid AS parent_uuid', 'p.company_id AS parent_company_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.category.parent_tenant_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'category',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Category parent (ID %d, UUID %s) belongs to company %d, not company %d.',
                    $row->parent_id,
                    $row->parent_uuid,
                    $row->parent_company_id,
                    $companyId,
                ),
                context: [
                    'category_id' => $row->id,
                    'parent_id' => $row->parent_id,
                    'parent_uuid' => $row->parent_uuid,
                    'parent_company_id' => $row->parent_company_id,
                ],
                suggestedRemediation: 'Reassign the category parent to one owned by this company.',
            );
        }
    }

    private function checkSelfParent(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('categories')
            ->where('company_id', $companyId)
            ->whereColumn('parent_id', 'id')
            ->whereNotNull('parent_id')
            ->select('id', 'uuid', 'parent_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.category.self_parent',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'category',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Category (ID %d, UUID %s) is its own parent.',
                    $row->id,
                    $row->uuid,
                ),
                context: [
                    'category_id' => $row->id,
                    'parent_id' => $row->parent_id,
                ],
                suggestedRemediation: 'Set parent_id to NULL or to a valid different category.',
            );
        }
    }

    private function checkDepthExceeded(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('categories')
            ->where('company_id', $companyId)
            ->where('depth', '>', CategoryHierarchyService::MAX_DEPTH)
            ->select('id', 'uuid', 'depth')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.category.depth_exceeded',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'category',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Category (ID %d, UUID %s) depth %d exceeds maximum %d.',
                    $row->id,
                    $row->uuid,
                    $row->depth,
                    CategoryHierarchyService::MAX_DEPTH,
                ),
                context: [
                    'category_id' => $row->id,
                    'depth' => $row->depth,
                    'max_depth' => CategoryHierarchyService::MAX_DEPTH,
                ],
                suggestedRemediation: 'Move the category higher in the hierarchy or adjust its parent.',
            );
        }
    }

    private function checkProductPrimaryCategoryTenantMismatch(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('products AS p')
            ->join('categories AS c', 'p.primary_category_id', '=', 'c.id')
            ->where('p.company_id', $companyId)
            ->where('c.company_id', '!=', $companyId)
            ->whereNotNull('p.primary_category_id')
            ->select('p.id', 'p.uuid', 'p.primary_category_id', 'c.uuid AS category_uuid', 'c.company_id AS category_company_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.category.product_primary_category_tenant_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'product',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Product primary_category_id %d (Category UUID %s) belongs to company %d, not company %d.',
                    $row->primary_category_id,
                    $row->category_uuid,
                    $row->category_company_id,
                    $companyId,
                ),
                context: [
                    'product_id' => $row->id,
                    'primary_category_id' => $row->primary_category_id,
                    'category_uuid' => $row->category_uuid,
                    'category_company_id' => $row->category_company_id,
                ],
                suggestedRemediation: 'Reassign the primary category to one owned by this company.',
            );
        }
    }

    private function checkPivotTenantMismatch(Company $company, int $companyId, array &$issues): void
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
                code: 'catalog.category.pivot_tenant_mismatch',
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
                ],
                suggestedRemediation: 'Remove the invalid pivot row or reassign the category/product.',
            );
        }
    }

    private function checkProductPrimaryMissingFromPivot(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('products AS p')
            ->leftJoin('category_product AS cp', function ($join) use ($companyId): void {
                $join->on('p.id', '=', 'cp.product_id')
                    ->on('p.primary_category_id', '=', 'cp.category_id')
                    ->where('cp.company_id', $companyId);
            })
            ->where('p.company_id', $companyId)
            ->whereNotNull('p.primary_category_id')
            ->whereNull('cp.id')
            ->select('p.id', 'p.uuid', 'p.primary_category_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.category.product_primary_missing_from_pivot',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'product',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Product (ID %d) primary_category_id %d does not exist in category_product pivot.',
                    $row->id,
                    $row->primary_category_id,
                ),
                context: [
                    'product_id' => $row->id,
                    'primary_category_id' => $row->primary_category_id,
                ],
                suggestedRemediation: 'Add the missing pivot row or change primary_category_id.',
            );
        }
    }
}
