<?php

namespace App\Actions\Catalog\Variants;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Exceptions\Catalog\VariantOperationException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\ProductVariantService;
use App\Support\Catalog\CatalogIdentifierNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

abstract class VariantAction
{
    public function __construct(
        protected readonly CompanyAuthorizer $authorizer,
        protected readonly CatalogIdentifierNormalizer $normalizer,
        protected readonly AuditLogger $auditLogger,
        protected readonly ProductVariantService $variants,
    ) {}

    protected function authorize(User $actor, Company $company, CompanyPermission $permission): Company
    {
        $freshCompany = Company::query()->find($company->getKey());

        if ($freshCompany?->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        $this->authorizer->authorize($actor, $freshCompany, $permission);

        return $freshCompany;
    }

    protected function assertProductTenant(Company $company, Product $product): void
    {
        if ((int) $product->getAttribute('company_id') !== (int) $company->getKey()) {
            throw VariantOperationException::tenantMismatch();
        }
    }

    protected function assertVariantOwner(Company $company, Product $product, ProductVariant $variant): void
    {
        if ((int) $variant->getAttribute('company_id') !== (int) $company->getKey()) {
            throw VariantOperationException::tenantMismatch();
        }

        if ((int) $variant->getAttribute('product_id') !== (int) $product->getKey()) {
            throw VariantOperationException::productMismatch();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string|null, sku: string|null, sku_normalized: string|null, gtin: string|null, mpn: string|null, sort_order: int}
     */
    protected function normalizedData(array $data, ?ProductVariant $fallback = null): array
    {
        $name = $this->nullableString($data, 'name', $fallback);

        if ($name !== null && mb_strlen($name) > 255) {
            throw VariantOperationException::invalid('name', 'The name field may not exceed 255 characters.');
        }

        $sku = $this->nullableString($data, 'sku', $fallback);

        if ($sku !== null && mb_strlen($sku) > 100) {
            throw VariantOperationException::invalid('sku', 'The SKU field may not exceed 100 characters.');
        }

        try {
            $skuNormalized = $sku === null ? null : $this->normalizer->normalizeSku($sku);
        } catch (InvalidArgumentException $exception) {
            throw VariantOperationException::invalid('sku', $exception->getMessage());
        }

        $gtinValue = $this->nullableString($data, 'gtin', $fallback);

        try {
            $gtin = $this->normalizer->normalizeGtin($gtinValue);
        } catch (InvalidArgumentException $exception) {
            throw VariantOperationException::invalid('gtin', $exception->getMessage());
        }

        $mpnValue = $this->nullableString($data, 'mpn', $fallback);

        try {
            $mpn = $this->normalizer->normalizeMpn($mpnValue);
        } catch (InvalidArgumentException $exception) {
            throw VariantOperationException::invalid('mpn', $exception->getMessage());
        }

        return [
            'name' => $name,
            'sku' => $sku,
            'sku_normalized' => $skuNormalized === '' ? null : $skuNormalized,
            'gtin' => $gtin,
            'mpn' => $mpn,
            'sort_order' => $this->sortOrder($data, $fallback),
        ];
    }

    protected function mapConstraint(QueryException $exception): ?VariantOperationException
    {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
            return null;
        }

        $driverMessage = (string) ($exception->errorInfo[2] ?? '');

        return match (true) {
            str_contains($driverMessage, 'variants_company_sku_unique') => VariantOperationException::skuConflict($exception),
            str_contains($driverMessage, 'variants_company_gtin_unique') => VariantOperationException::gtinConflict($exception),
            default => null,
        };
    }

    /** @param array<string, mixed> $data */
    private function nullableString(array $data, string $field, ?ProductVariant $fallback): ?string
    {
        if (! array_key_exists($field, $data)) {
            $value = $fallback?->getAttribute($field);

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        $value = $data[$field];

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw VariantOperationException::invalid($field, "The {$field} field must be a string.");
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $data */
    private function sortOrder(array $data, ?ProductVariant $fallback): int
    {
        if (! array_key_exists('sort_order', $data)) {
            return (int) ($fallback?->getAttribute('sort_order') ?? 0);
        }

        $value = $data['sort_order'];

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 0 || $value > 4294967295) {
            throw VariantOperationException::invalid('sort_order', 'The sort order must be a non-negative integer.');
        }

        return $value;
    }
}
