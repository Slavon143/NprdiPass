<?php

namespace App\Services\Catalog\Integrity\Checks;

use App\Contracts\Catalog\Integrity\CatalogIntegrityCheck;
use App\Data\Catalog\Integrity\CatalogIntegrityIssue;
use App\Enums\Catalog\CatalogIntegritySeverity;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Services\Catalog\ProductActivationReadinessService;
use Illuminate\Support\Facades\DB;

class LifecycleIntegrityCheck implements CatalogIntegrityCheck
{
    private const string CODE = 'lifecycle_integrity';

    /** @var string[] */
    private const array VALID_PRODUCT_STATUSES = ['draft', 'active', 'archived'];

    /** @var string[] */
    private const array VALID_VARIANT_STATUSES = ['draft', 'active', 'archived'];

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

        $this->checkActiveProductBlockers($company, $companyId, $issues);
        $this->checkArchivedProductDefaultVariant($company, $companyId, $issues);
        $this->checkInvalidStatusValue($company, $companyId, $issues);

        return $issues;
    }

    private function checkActiveProductBlockers(Company $company, int $companyId, array &$issues): void
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
                    code: 'catalog.lifecycle.active_product_blockers',
                    severity: CatalogIntegritySeverity::Error,
                    companyUuid: $company->uuid,
                    resourceType: 'product',
                    resourceUuid: $product->uuid,
                    message: sprintf(
                        'Active product (ID %d) has %d activation blockers.',
                        $product->getKey(),
                        count($readiness->blockers),
                    ),
                    context: [
                        'product_id' => $product->getKey(),
                        'blockers' => array_map(fn ($blocker): array => $blocker->toArray(), $readiness->blockers),
                    ],
                    suggestedRemediation: 'Resolve blockers or change the product to draft/archived.',
                );
            }
        }
    }

    private function checkArchivedProductDefaultVariant(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('products AS p')
            ->join('product_variants AS v', 'p.default_variant_id', '=', 'v.id')
            ->where('p.company_id', $companyId)
            ->where('p.status', ProductStatus::Archived->value)
            ->where('v.status', '!=', ProductVariantStatus::Archived->value)
            ->whereNull('p.deleted_at')
            ->whereNull('v.deleted_at')
            ->select('p.id', 'p.uuid', 'p.default_variant_id', 'v.uuid AS variant_uuid', 'v.status AS variant_status')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.lifecycle.archived_product_default_variant',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'product',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Archived product (ID %d) has default variant (ID %d) with status "%s", expected "archived".',
                    $row->id,
                    $row->default_variant_id,
                    $row->variant_status,
                ),
                context: [
                    'product_id' => $row->id,
                    'variant_id' => $row->default_variant_id,
                    'variant_uuid' => $row->variant_uuid,
                    'variant_status' => $row->variant_status,
                ],
                suggestedRemediation: 'Archive the default variant or change the product status.',
            );
        }
    }

    private function checkInvalidStatusValue(Company $company, int $companyId, array &$issues): void
    {
        $productRows = DB::table('products')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', self::VALID_PRODUCT_STATUSES)
            ->select('id', 'uuid', 'status')
            ->get();

        foreach ($productRows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.lifecycle.invalid_status_value',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'product',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Product (ID %d) has invalid status "%s", expected one of [%s].',
                    $row->id,
                    $row->status,
                    implode(', ', self::VALID_PRODUCT_STATUSES),
                ),
                context: [
                    'product_id' => $row->id,
                    'status' => $row->status,
                    'valid_statuses' => self::VALID_PRODUCT_STATUSES,
                ],
                suggestedRemediation: 'Set the product status to a valid value.',
            );
        }

        $variantRows = DB::table('product_variants')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', self::VALID_VARIANT_STATUSES)
            ->select('id', 'uuid', 'status')
            ->get();

        foreach ($variantRows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.lifecycle.invalid_status_value',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'variant',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Variant (ID %d) has invalid status "%s", expected one of [%s].',
                    $row->id,
                    $row->status,
                    implode(', ', self::VALID_VARIANT_STATUSES),
                ),
                context: [
                    'variant_id' => $row->id,
                    'status' => $row->status,
                    'valid_statuses' => self::VALID_VARIANT_STATUSES,
                ],
                suggestedRemediation: 'Set the variant status to a valid value.',
            );
        }
    }
}
