<?php

namespace App\Services\Catalog\Integrity\Checks;

use App\Contracts\Catalog\Integrity\CatalogIntegrityCheck;
use App\Data\Catalog\Integrity\CatalogIntegrityIssue;
use App\Enums\Catalog\CatalogIntegritySeverity;
use App\Models\Company;
use App\Services\Catalog\Media\CatalogMediaStorage;
use Illuminate\Support\Facades\DB;
use Throwable;

class MediaIntegrityCheck implements CatalogIntegrityCheck
{
    private const string CODE = 'media_integrity';

    public function __construct(
        private readonly CatalogMediaStorage $mediaStorage,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function check(Company $company): array
    {
        $issues = [];
        $companyId = $company->getKey();

        $this->checkTenantMismatch($company, $companyId, $issues);
        $this->checkProductMediaWrongProduct($company, $companyId, $issues);
        $this->checkVariantMediaWrongVariant($company, $companyId, $issues);
        $this->checkPrimaryWrongOwner($company, $companyId, $issues);
        $this->checkMissingPhysicalFile($company, $companyId, $issues);
        $this->checkInvalidMime($company, $companyId, $issues);

        return $issues;
    }

    private function checkTenantMismatch(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_media AS pm')
            ->leftJoin('products AS p', 'pm.product_id', '=', 'p.id')
            ->where('pm.company_id', $companyId)
            ->whereNull('pm.deleted_at')
            ->where(function ($query): void {
                $query->whereNotNull('pm.product_variant_id')
                    ->whereExists(function ($sub): void {
                        $sub->select(DB::raw(1))
                            ->from('product_variants')
                            ->whereColumn('product_variants.id', 'pm.product_variant_id')
                            ->where('product_variants.company_id', '!=', DB::raw('pm.company_id'));
                    });
            })
            ->orWhere(function ($query) use ($companyId): void {
                $query->where('pm.company_id', $companyId)
                    ->where('p.company_id', '!=', $companyId);
            })
            ->select('pm.id', 'pm.uuid', 'pm.product_id', 'pm.product_variant_id', 'p.uuid AS product_uuid', 'p.company_id AS product_company_id')
            ->get();

        foreach ($rows as $row) {
            $ownerType = $row->product_variant_id !== null ? 'variant' : 'product';
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.media.tenant_mismatch',
                severity: CatalogIntegritySeverity::Critical,
                companyUuid: $company->uuid,
                resourceType: 'product_media',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Media (ID %d, UUID %s) company_id does not match its %s owner.',
                    $row->id,
                    $row->uuid,
                    $ownerType,
                ),
                context: [
                    'media_id' => $row->id,
                    'product_id' => $row->product_id,
                    'product_variant_id' => $row->product_variant_id,
                    'owner_type' => $ownerType,
                ],
                suggestedRemediation: 'Fix the media company_id to match its owner.',
            );
        }
    }

    private function checkProductMediaWrongProduct(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_media')
            ->where('company_id', $companyId)
            ->whereNull('product_variant_id')
            ->whereNull('deleted_at')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('products')
                    ->whereColumn('products.id', 'product_media.product_id')
                    ->where('products.company_id', DB::raw('product_media.company_id'));
            })
            ->select('id', 'uuid', 'product_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.media.product_media_wrong_product',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'product_media',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Product-level media (ID %d, UUID %s) references product_id %d which does not exist.',
                    $row->id,
                    $row->uuid,
                    $row->product_id,
                ),
                context: [
                    'media_id' => $row->id,
                    'product_id' => $row->product_id,
                ],
                suggestedRemediation: 'Remove the orphaned media record or fix the product_id.',
            );
        }
    }

    private function checkVariantMediaWrongVariant(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_media')
            ->where('company_id', $companyId)
            ->whereNotNull('product_variant_id')
            ->whereNull('deleted_at')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('product_variants')
                    ->whereColumn('product_variants.id', 'product_media.product_variant_id')
                    ->where('product_variants.company_id', DB::raw('product_media.company_id'));
            })
            ->select('id', 'uuid', 'product_variant_id')
            ->get();

        foreach ($rows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.media.variant_media_wrong_variant',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'product_media',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Variant-level media (ID %d, UUID %s) references product_variant_id %d which does not exist.',
                    $row->id,
                    $row->uuid,
                    $row->product_variant_id,
                ),
                context: [
                    'media_id' => $row->id,
                    'product_variant_id' => $row->product_variant_id,
                ],
                suggestedRemediation: 'Remove the orphaned media record or fix the variant_id.',
            );
        }
    }

    private function checkPrimaryWrongOwner(Company $company, int $companyId, array &$issues): void
    {
        $productRows = DB::table('products AS p')
            ->join('product_media AS pm', 'p.primary_media_id', '=', 'pm.id')
            ->where('p.company_id', $companyId)
            ->whereNull('pm.deleted_at')
            ->where(function ($query): void {
                $query->whereColumn('pm.product_id', '!=', 'p.id')
                    ->orWhereNotNull('pm.product_variant_id');
            })
            ->select('p.id', 'p.uuid', 'p.primary_media_id', 'pm.uuid AS media_uuid', 'pm.product_id AS media_product_id', 'pm.product_variant_id')
            ->get();

        foreach ($productRows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.media.primary_wrong_owner',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'product',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Product primary_media_id %d points to media owned by a different entity (product_id=%d, variant_id=%d).',
                    $row->primary_media_id,
                    $row->media_product_id,
                    $row->product_variant_id,
                ),
                context: [
                    'product_id' => $row->id,
                    'primary_media_id' => $row->primary_media_id,
                    'media_uuid' => $row->media_uuid,
                    'media_product_id' => $row->media_product_id,
                    'media_variant_id' => $row->product_variant_id,
                ],
                suggestedRemediation: 'Update primary_media_id to a product-level media matching this product.',
            );
        }

        $variantRows = DB::table('product_variants AS v')
            ->join('product_media AS pm', 'v.primary_media_id', '=', 'pm.id')
            ->where('v.company_id', $companyId)
            ->whereNull('pm.deleted_at')
            ->where(function ($query): void {
                $query->whereColumn('pm.product_variant_id', '!=', 'v.id')
                    ->orWhereNull('pm.product_variant_id');
            })
            ->select('v.id', 'v.uuid', 'v.primary_media_id', 'pm.uuid AS media_uuid', 'pm.product_variant_id AS media_variant_id')
            ->get();

        foreach ($variantRows as $row) {
            $issues[] = new CatalogIntegrityIssue(
                code: 'catalog.media.primary_wrong_owner',
                severity: CatalogIntegritySeverity::Error,
                companyUuid: $company->uuid,
                resourceType: 'variant',
                resourceUuid: $row->uuid,
                message: sprintf(
                    'Variant primary_media_id %d points to media owned by a different entity (variant_id=%d).',
                    $row->primary_media_id,
                    $row->media_variant_id,
                ),
                context: [
                    'variant_id' => $row->id,
                    'primary_media_id' => $row->primary_media_id,
                    'media_uuid' => $row->media_uuid,
                    'media_variant_id' => $row->media_variant_id,
                ],
                suggestedRemediation: 'Update primary_media_id to a variant-level media matching this variant.',
            );
        }
    }

    private function checkMissingPhysicalFile(Company $company, int $companyId, array &$issues): void
    {
        $rows = DB::table('product_media')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->select('id', 'uuid', 'storage_path')
            ->get();

        foreach ($rows as $row) {
            if ($row->storage_path === null || $row->storage_path === '') {
                continue;
            }

            if (! $this->fileExists($row->storage_path)) {
                $issues[] = new CatalogIntegrityIssue(
                    code: 'catalog.media.missing_physical_file',
                    severity: CatalogIntegritySeverity::Error,
                    companyUuid: $company->uuid,
                    resourceType: 'product_media',
                    resourceUuid: $row->uuid,
                    message: sprintf(
                        'Media (ID %d) has a database record but the storage file "%s" does not exist.',
                        $row->id,
                        $row->storage_path,
                    ),
                    context: [
                        'media_id' => $row->id,
                        'storage_path' => $row->storage_path,
                    ],
                    suggestedRemediation: 'Re-upload the file or remove the database record.',
                );
            }
        }
    }

    private function checkInvalidMime(Company $company, int $companyId, array &$issues): void
    {
        $allowed = array_keys(config('catalog.media.mime_extensions', []));

        if ($allowed === []) {
            return;
        }

        $rows = DB::table('product_media')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotNull('mime_type')
            ->select('id', 'uuid', 'mime_type')
            ->get();

        foreach ($rows as $row) {
            if (! in_array($row->mime_type, $allowed, true)) {
                $issues[] = new CatalogIntegrityIssue(
                    code: 'catalog.media.invalid_mime',
                    severity: CatalogIntegritySeverity::Warning,
                    companyUuid: $company->uuid,
                    resourceType: 'product_media',
                    resourceUuid: $row->uuid,
                    message: sprintf(
                        'Media (ID %d) has MIME type "%s" which is not in the allowlist.',
                        $row->id,
                        $row->mime_type,
                    ),
                    context: [
                        'media_id' => $row->id,
                        'mime_type' => $row->mime_type,
                        'allowed_mimes' => $allowed,
                    ],
                    suggestedRemediation: 'Replace the file with a valid image type (JPEG, PNG, or WEBP).',
                );
            }
        }
    }

    private function fileExists(string $path): bool
    {
        try {
            return $this->mediaStorage->exists($path);
        } catch (Throwable) {
            return false;
        }
    }
}
