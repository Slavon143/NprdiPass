<?php

namespace App\Services\Catalog\Integrity\Checks;

use App\Contracts\Catalog\Integrity\CatalogIntegrityCheck;
use App\Data\Catalog\Integrity\CatalogIntegrityIssue;
use App\Enums\Catalog\CatalogIntegritySeverity;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

class VariantIntegrityCheck implements CatalogIntegrityCheck
{
    private const string CODE = 'variant_integrity';

    public function code(): string
    {
        return self::CODE;
    }

    public function check(Company $company): array
    {
        $issues = [];
        $companyId = $company->getKey();

        $this->checkProductTenantMismatch($company, $companyId, $issues);
        $this->checkDefaultArchived($company, $companyId, $issues);
        $this->checkDefaultWrongProduct($company, $companyId, $issues);
        $this->checkPrimaryMediaWrongVariant($company, $companyId, $issues);
        $this->checkPrimaryMediaTenantMismatch($company, $companyId, $issues);
        $this->checkActiveProductNoAvailableVariant($company, $companyId, $issues);

        return $issues;
    }

    private function checkProductTenantMismatch(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_variants AS v')
            ->join('products AS p', 'v.product_id', '=', 'p.id')
            ->where('v.company_id', $companyId)
            ->where('p.company_id', '!=', $companyId)
            ->select('v.id', 'v.uuid', 'v.product_id', 'p.uuid AS product_uuid', 'p.company_id AS product_company_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.variant.product_tenant_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'variant',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Variant (ID %d) company_id %d differs from its Product company_id %d.',
                    $row->id,
                    $companyId,
                    $row->product_company_id,
                ),
                context: [
                    'variant_id' => $row->id,
                    'variant_company_id' => $companyId,
                    'product_id' => $row->product_id,
                    'product_uuid' => $row->product_uuid,
                    'product_company_id' => $row->product_company_id,
                ],
                suggestedRemediation: 'Fix the variant company_id to match its product.',
            );
        }
    }

    private function checkDefaultArchived(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_variants AS v')
            ->join('products AS p', function ($join): void {
                $join->on('p.default_variant_id', '=', 'v.id')
                    ->on('p.id', '=', 'v.product_id');
            })
            ->where('v.company_id', $companyId)
            ->where('v.status', ProductVariantStatus::Archived->value)
            ->whereNull('v.deleted_at')
            ->select('v.id', 'v.uuid', 'p.id AS product_id', 'p.uuid AS product_uuid')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.variant.default_archived',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'variant',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Variant (ID %d) is default for product (ID %d) but is archived.',
                    $row->id,
                    $row->product_id,
                ),
                context: [
                    'variant_id' => $row->id,
                    'product_id' => $row->product_id,
                    'product_uuid' => $row->product_uuid,
                ],
                suggestedRemediation: 'Change the default variant or unarchive this variant.',
            );
        }
    }

    private function checkDefaultWrongProduct(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('products AS p')
            ->join('product_variants AS v', 'p.default_variant_id', '=', 'v.id')
            ->where('p.company_id', $companyId)
            ->whereColumn('v.product_id', '!=', 'p.id')
            ->select('p.id', 'p.uuid', 'p.default_variant_id', 'v.uuid AS variant_uuid', 'v.product_id AS variant_product_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.variant.default_wrong_product',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'product',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Product default_variant_id %d points to variant (UUID %s) belonging to product %d, not %d.',
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
                suggestedRemediation: 'Update default_variant_id to a variant of this product.',
            );
        }
    }

    private function checkPrimaryMediaWrongVariant(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_variants AS v')
            ->join('product_media AS pm', 'v.primary_media_id', '=', 'pm.id')
            ->where('v.company_id', $companyId)
            ->where(function ($query): void {
                $query->whereColumn('pm.product_variant_id', '!=', 'v.id')
                    ->orWhereNull('pm.product_variant_id');
            })
            ->whereNull('pm.deleted_at')
            ->select('v.id', 'v.uuid', 'v.primary_media_id', 'pm.uuid AS media_uuid', 'pm.product_variant_id AS media_variant_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.variant.primary_media_wrong_variant',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'variant',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Variant primary media (ID %d, UUID %s) belongs to variant %d, not %d.',
                    $row->primary_media_id,
                    $row->media_uuid,
                    $row->media_variant_id,
                    $row->id,
                ),
                context: [
                    'variant_id' => $row->id,
                    'primary_media_id' => $row->primary_media_id,
                    'media_uuid' => $row->media_uuid,
                    'media_variant_id' => $row->media_variant_id,
                ],
                suggestedRemediation: 'Update primary_media_id to a media owned by this variant.',
            );
        }
    }

    private function checkPrimaryMediaTenantMismatch(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_variants AS v')
            ->join('product_media AS pm', 'v.primary_media_id', '=', 'pm.id')
            ->where('v.company_id', $companyId)
            ->where('pm.company_id', '!=', $companyId)
            ->whereNull('pm.deleted_at')
            ->select('v.id', 'v.uuid', 'v.primary_media_id', 'pm.uuid AS media_uuid', 'pm.company_id AS media_company_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.variant.primary_media_tenant_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'variant',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Variant primary media (ID %d, UUID %s) belongs to company %d, not %d.',
                    $row->primary_media_id,
                    $row->media_uuid,
                    $row->media_company_id,
                    $companyId,
                ),
                context: [
                    'variant_id' => $row->id,
                    'primary_media_id' => $row->primary_media_id,
                    'media_uuid' => $row->media_uuid,
                    'media_company_id' => $row->media_company_id,
                ],
                suggestedRemediation: 'Reassign primary media to one owned by this company.',
            );
        }
    }

    private function checkActiveProductNoAvailableVariant(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('products AS p')
            ->leftJoin('product_variants AS v', function ($join): void {
                $join->on('p.id', '=', 'v.product_id')
                    ->where('v.status', '!=', ProductVariantStatus::Archived->value)
                    ->whereNull('v.deleted_at');
            })
            ->where('p.company_id', $companyId)
            ->where('p.status', ProductStatus::Active->value)
            ->whereNull('p.deleted_at')
            ->whereNull('v.id')
            ->select('p.id', 'p.uuid')
            ->groupBy('p.id', 'p.uuid')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.variant.active_product_no_available_variant',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'product',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Active product (ID %d) has no active or available variants.',
                    $row->id,
                ),
                context: ['product_id' => $row->id],
                suggestedRemediation: 'Activate at least one variant or change the product status.',
            );
        }
    }
}
