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
use App\Services\Catalog\CatalogLifecycleGuard;
use App\Services\Catalog\ProductVariantService;
use App\Support\Catalog\CatalogIdentifierNormalizer;
use App\Support\Catalog\VariantIdentifierNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;

abstract class VariantAction
{
    public function __construct(
        protected readonly CompanyAuthorizer $authorizer,
        protected readonly CatalogIdentifierNormalizer $normalizer,
        protected readonly AuditLogger $auditLogger,
        protected readonly ProductVariantService $variants,
        protected readonly CatalogLifecycleGuard $lifecycle,
    ) {}

    protected function authorize(User $actor, Company $company, CompanyPermission $permission, bool $sharedCatalog = false): Company
    {
        $freshCompany = Company::query()->find($company->getKey());

        if ($freshCompany?->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        if ($sharedCatalog) {
            $this->authorizer->authorizeCatalogWrite($actor, $freshCompany, $permission);
        } else {
            $this->authorizer->authorize($actor, $freshCompany, $permission);
        }

        return $freshCompany;
    }

    protected function assertProductTenant(Company $company, Product $product): void
    {
        if ((int) $product->getAttribute('company_id') !== (int) $company->getKey()) {
            throw VariantOperationException::tenantMismatch();
        }

        $this->lifecycle->assertProductEditable($product);
    }

    protected function assertVariantOwner(Company $company, Product $product, ProductVariant $variant): void
    {
        if ((int) $variant->getAttribute('company_id') !== (int) $company->getKey()) {
            throw VariantOperationException::tenantMismatch();
        }

        if ((int) $variant->getAttribute('product_id') !== (int) $product->getKey()) {
            throw VariantOperationException::productMismatch();
        }

        $this->lifecycle->assertVariantEditable($product, $variant);
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

        $identifiers = app(VariantIdentifierNormalizer::class)->normalize($data, $fallback);

        return [
            'name' => $name,
            ...$identifiers,
            'sort_order' => $this->sortOrder($data, $fallback),
        ];
    }

    protected function mapConstraint(QueryException $exception): ?VariantOperationException
    {
        return app(VariantIdentifierNormalizer::class)->conflict($exception);
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
