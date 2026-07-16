<?php

namespace App\Services\Catalog\Integrity\Checks;

use App\Contracts\Catalog\Integrity\CatalogIntegrityCheck;
use App\Data\Catalog\Integrity\CatalogIntegrityIssue;
use App\Enums\Catalog\CatalogIntegritySeverity;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

class IdentifierIntegrityCheck implements CatalogIntegrityCheck
{
    private const string CODE = 'identifier_integrity';

    /** @var int[] */
    private const array VALID_GTIN_LENGTHS = [8, 12, 13, 14];

    public function code(): string
    {
        return self::CODE;
    }

    public function check(Company $company): array
    {
        $issues = [];
        $companyId = $company->getKey();

        $this->checkSkuDuplicates($company, $companyId, $issues);
        $this->checkGtinDuplicates($company, $companyId, $issues);
        $this->checkSkuNotNormalized($company, $companyId, $issues);
        $this->checkGtinInvalidLength($company, $companyId, $issues);
        $this->checkGtinNonNumeric($company, $companyId, $issues);

        return $issues;
    }

    private function checkSkuDuplicates(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_variants')
            ->where('company_id', $companyId)
            ->whereNotNull('sku_normalized')
            ->where('sku_normalized', '!=', '')
            ->whereNull('deleted_at')
            ->select('sku_normalized', DB::raw('COUNT(*) AS cnt'), DB::raw('GROUP_CONCAT(uuid) AS uuids'))
            ->groupBy('sku_normalized')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($rows as $row) {
            $uuids = explode(',', $row->uuids);
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.identifier.sku_duplicate',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'variant',
                resourceUuid: $uuids[0],
                message: sprintf(
                    'Duplicate SKU "%s" found %d times within company %d.',
                    $row->sku_normalized,
                    $row->cnt,
                    $companyId,
                ),
                context: [
                    'sku_normalized' => $row->sku_normalized,
                    'count' => $row->cnt,
                    'variant_uuids' => $uuids,
                ],
                suggestedRemediation: 'Ensure each variant has a unique SKU within the company.',
            );
        }
    }

    private function checkGtinDuplicates(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_variants')
            ->where('company_id', $companyId)
            ->whereNotNull('gtin')
            ->where('gtin', '!=', '')
            ->whereNull('deleted_at')
            ->select('gtin', DB::raw('COUNT(*) AS cnt'), DB::raw('GROUP_CONCAT(uuid) AS uuids'))
            ->groupBy('gtin')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($rows as $row) {
            $uuids = explode(',', $row->uuids);
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.identifier.gtin_duplicate',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'variant',
                resourceUuid: $uuids[0],
                message: sprintf(
                    'Duplicate GTIN "%s" found %d times within company %d.',
                    $row->gtin,
                    $row->cnt,
                    $companyId,
                ),
                context: [
                    'gtin' => $row->gtin,
                    'count' => $row->cnt,
                    'variant_uuids' => $uuids,
                ],
                suggestedRemediation: 'Ensure each variant has a unique GTIN within the company.',
            );
        }
    }

    private function checkSkuNotNormalized(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_variants')
            ->where('company_id', $companyId)
            ->whereNotNull('sku')
            ->whereNotNull('sku_normalized')
            ->whereColumn('sku', '!=', 'sku_normalized')
            ->whereNull('deleted_at')
            ->select('id', 'uuid', 'sku', 'sku_normalized')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.identifier.sku_not_normalized',
                severity: CatalogIntegritySeverity::Warning,
                companyUuid: $company->uuid,
                resourceType: 'variant',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Variant SKU "%s" differs from its normalized form "%s".',
                    $row->sku,
                    $row->sku_normalized,
                ),
                context: [
                    'variant_id' => $row->id,
                    'sku' => $row->sku,
                    'sku_normalized' => $row->sku_normalized,
                ],
                suggestedRemediation: 'Update the SKU to its normalized form.',
            );
        }
    }

    private function checkGtinInvalidLength(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_variants')
            ->where('company_id', $companyId)
            ->whereNotNull('gtin')
            ->where('gtin', '!=', '')
            ->whereNull('deleted_at')
            ->select('id', 'uuid', 'gtin')
            ->get();

        foreach ($rows as $row) {
            $length = strlen($row->gtin);

            if (! in_array($length, self::VALID_GTIN_LENGTHS, true)) {
                $issues[] = new CatalogIntegrityIssue(
                    code: 'catalog.identifier.gtin_invalid_length',
                    severity: CatalogIntegritySeverity::Error,
                    companyUuid: $company->uuid,
                    resourceType: 'variant',
                    resourceUuid: $row->uuid,
                    message: sprintf(
                        'Variant GTIN "%s" has length %d, expected one of [%s].',
                        $row->gtin,
                        $length,
                        implode(', ', self::VALID_GTIN_LENGTHS),
                    ),
                    context: [
                        'variant_id' => $row->id,
                        'gtin' => $row->gtin,
                        'length' => $length,
                        'valid_lengths' => self::VALID_GTIN_LENGTHS,
                    ],
                    suggestedRemediation: 'Correct the GTIN to a valid length (8, 12, 13, or 14 digits).',
                );
            }
        }
    }

    private function checkGtinNonNumeric(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_variants')
            ->where('company_id', $companyId)
            ->whereNotNull('gtin')
            ->where('gtin', '!=', '')
            ->whereNull('deleted_at')
            ->select('id', 'uuid', 'gtin')
            ->get();

        foreach ($rows as $row) {
            if (! ctype_digit($row->gtin)) {
                $issues[] = new CatalogIntegrityIssue(
                    code: 'catalog.identifier.gtin_non_numeric',
                    severity: CatalogIntegritySeverity::Error,
                    companyUuid: $company->uuid,
                    resourceType: 'variant',
                    resourceUuid: $row->uuid,
                    message: sprintf(
                        'Variant GTIN "%s" contains non-digit characters.',
                        $row->gtin,
                    ),
                    context: [
                        'variant_id' => $row->id,
                        'gtin' => $row->gtin,
                    ],
                    suggestedRemediation: 'Ensure the GTIN contains only digit characters.',
                );
            }
        }
    }
}
