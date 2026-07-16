<?php

namespace App\Services\Catalog\Integrity\Checks;

use App\Contracts\Catalog\Integrity\CatalogIntegrityCheck;
use App\Data\Catalog\Integrity\CatalogIntegrityIssue;
use App\Enums\Catalog\CatalogIntegritySeverity;
use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Services\Catalog\ProductActivationReadinessService;
use Illuminate\Support\Facades\DB;

class ProductIntegrityCheck implements CatalogIntegrityCheck
{
    private const string CODE = 'product_integrity';

    public function __construct(
        private readonly ProductActivationReadinessService $readinessService,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function check(Company $company): array
    {
        $issues = [];
        $companyId = $company->getKey();

        $this->checkDefaultVariantMissing($company, $companyId, $issues);
        $this->checkDefaultVariantWrongProduct($company, $companyId, $issues);
        $this->checkDefaultVariantTenantMismatch($company, $companyId, $issues);
        $this->checkPrimaryCategoryTenantMismatch($company, $companyId, $issues);
        $this->checkPrimaryMediaWrongProduct($company, $companyId, $issues);
        $this->checkPrimaryMediaTenantMismatch($company, $companyId, $issues);
        $this->checkActiveNotReady($company, $companyId, $issues);
        $this->checkNoVariants($company, $companyId, $issues);

        return $issues;
    }

    private function checkDefaultVariantMissing(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('products AS p')
            ->leftJoin('product_variants AS v', 'p.default_variant_id', '=', 'v.id')
            ->where('p.company_id', $companyId)
            ->whereNotNull('p.default_variant_id')
            ->whereNull('v.id')
            ->select('p.id', 'p.uuid', 'p.default_variant_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.product.default_variant_missing',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'product',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Product (ID %d) default_variant_id %d does not exist.',
                    $row->id,
                    $row->default_variant_id,
                ),
                context: [
                    'product_id' => $row->id,
                    'default_variant_id' => $row->default_variant_id,
                ],
                suggestedRemediation: 'Set a valid default variant or remove the reference.',
            );
        }
    }

    private function checkDefaultVariantWrongProduct(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('products AS p')
            ->join('product_variants AS v', 'p.default_variant_id', '=', 'v.id')
            ->where('p.company_id', $companyId)
            ->whereColumn('v.product_id', '!=', 'p.id')
            ->select('p.id', 'p.uuid', 'p.default_variant_id', 'v.uuid AS variant_uuid', 'v.product_id AS variant_product_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.product.default_variant_wrong_product',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'product',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Product default variant (ID %d, UUID %s) belongs to product %d, not %d.',
                    $row->default_variant_id,
                    $row->variant_uuid,
                    $row->variant_product_id,
                    $row->id,
                ),
                context: [
                    'product_id' => $row->id,
                    'default_variant_id' => $row->default_variant_id,
                    'variant_uuid' => $row->variant_uuid,
                    'variant_product_id' => $row->variant_product_id,
                ],
                suggestedRemediation: 'Update default_variant_id to a variant owned by this product.',
            );
        }
    }

    private function checkDefaultVariantTenantMismatch(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('products AS p')
            ->join('product_variants AS v', 'p.default_variant_id', '=', 'v.id')
            ->where('p.company_id', $companyId)
            ->where('v.company_id', '!=', $companyId)
            ->select('p.id', 'p.uuid', 'p.default_variant_id', 'v.uuid AS variant_uuid', 'v.company_id AS variant_company_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.product.default_variant_tenant_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'product',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Default variant (ID %d, UUID %s) belongs to company %d, not %d.',
                    $row->default_variant_id,
                    $row->variant_uuid,
                    $row->variant_company_id,
                    $companyId,
                ),
                context: [
                    'product_id' => $row->id,
                    'default_variant_id' => $row->default_variant_id,
                    'variant_uuid' => $row->variant_uuid,
                    'variant_company_id' => $row->variant_company_id,
                ],
                suggestedRemediation: 'Reassign to a default variant owned by this company.',
            );
        }
    }

    private function checkPrimaryCategoryTenantMismatch(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('products AS p')
            ->join('categories AS c', 'p.primary_category_id', '=', 'c.id')
            ->where('p.company_id', $companyId)
            ->where('c.company_id', '!=', $companyId)
            ->select('p.id', 'p.uuid', 'p.primary_category_id', 'c.uuid AS category_uuid', 'c.company_id AS category_company_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.product.primary_category_tenant_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'product',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Product primary category (ID %d, UUID %s) belongs to company %d, not %d.',
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
                suggestedRemediation: 'Reassign to a primary category owned by this company.',
            );
        }
    }

    private function checkPrimaryMediaWrongProduct(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('products AS p')
            ->join('product_media AS pm', 'p.primary_media_id', '=', 'pm.id')
            ->where('p.company_id', $companyId)
            ->whereColumn('pm.product_id', '!=', 'p.id')
            ->whereNull('pm.deleted_at')
            ->select('p.id', 'p.uuid', 'p.primary_media_id', 'pm.uuid AS media_uuid', 'pm.product_id AS media_product_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.product.primary_media_wrong_product',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'product',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Product primary media (ID %d, UUID %s) belongs to product %d, not %d.',
                    $row->primary_media_id,
                    $row->media_uuid,
                    $row->media_product_id,
                    $row->id,
                ),
                context: [
                    'product_id' => $row->id,
                    'primary_media_id' => $row->primary_media_id,
                    'media_uuid' => $row->media_uuid,
                    'media_product_id' => $row->media_product_id,
                ],
                suggestedRemediation: 'Update primary_media_id to a media owned by this product.',
            );
        }
    }

    private function checkPrimaryMediaTenantMismatch(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('products AS p')
            ->join('product_media AS pm', 'p.primary_media_id', '=', 'pm.id')
            ->where('p.company_id', $companyId)
            ->where('pm.company_id', '!=', $companyId)
            ->whereNull('pm.deleted_at')
            ->select('p.id', 'p.uuid', 'p.primary_media_id', 'pm.uuid AS media_uuid', 'pm.company_id AS media_company_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.product.primary_media_tenant_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'product',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Product primary media (ID %d, UUID %s) belongs to company %d, not %d.',
                    $row->primary_media_id,
                    $row->media_uuid,
                    $row->media_company_id,
                    $companyId,
                ),
                context: [
                    'product_id' => $row->id,
                    'primary_media_id' => $row->primary_media_id,
                    'media_uuid' => $row->media_uuid,
                    'media_company_id' => $row->media_company_id,
                ],
                suggestedRemediation: 'Reassign primary media to one owned by this company.',
            );
        }
    }

    private function checkActiveNotReady(Company $company, int $companyId, array &$issues): void
    {
        $products = Product::query()
            ->forCompany($company)
            ->where('status', ProductStatus::Active->value)
            ->select('id', 'uuid', 'company_id')
            ->get();

        foreach ($products as $product) {
            $readiness = $this->readinessService->evaluate($company, $product);

            if (! $readiness->ready) {
                $issues[] = new CatalogIntegrityIssue(
                    code: 'catalog.product.active_not_ready',
                    severity: CatalogIntegritySeverity::Warning,
                    companyUuid: $company->uuid,
                    resourceType: 'product',
                    resourceUuid: $product->uuid,
                    message: sprintf(
                        'Active product (ID %d) has %d readiness blockers.',
                        $product->getKey(),
                        count($readiness->blockers),
                    ),
                    context: [
                        'product_id' => $product->getKey(),
                        'blockers' => array_map(fn ($blocker): array => $blocker->toArray(), $readiness->blockers),
                    ],
                    suggestedRemediation: 'Resolve readiness blockers or change product status.',
                );
            }
        }
    }

    private function checkNoVariants(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('products AS p')
            ->leftJoin('product_variants AS v', function ($join): void {
                $join->on('p.id', '=', 'v.product_id')
                    ->whereNull('v.deleted_at');
            })
            ->where('p.company_id', $companyId)
            ->whereNull('v.id')
            ->whereNull('p.deleted_at')
            ->select('p.id', 'p.uuid')
            ->groupBy('p.id', 'p.uuid')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.product.no_variants',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'product',
                resourceUuid: $row->uuid,
                message: sprintf('Product (ID %d) has zero variants.', $row->id),
                context: ['product_id' => $row->id],
                suggestedRemediation: 'Create at least one variant for this product.',
            );
        }
    }
}
