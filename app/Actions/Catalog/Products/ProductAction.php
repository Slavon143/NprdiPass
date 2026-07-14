<?php

namespace App\Actions\Catalog\Products;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Exceptions\Catalog\ProductOperationException;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\ProductCategoryService;
use App\Support\Catalog\CatalogIdentifierNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;

abstract class ProductAction
{
    public function __construct(
        protected readonly CompanyAuthorizer $authorizer,
        protected readonly CatalogIdentifierNormalizer $normalizer,
        protected readonly AuditLogger $auditLogger,
        protected readonly ProductCategoryService $categories,
        protected readonly ProductAggregateCreator $aggregateCreator,
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

    protected function assertTenant(Company $company, Product $product): void
    {
        if ((int) $product->getAttribute('company_id') !== (int) $company->getKey()) {
            throw ProductOperationException::tenantMismatch();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, slug: string, short_description: string|null, description: string|null, brand: string|null, manufacturer: string|null}
     */
    protected function normalizedData(array $data, ?Product $fallback = null): array
    {
        $name = array_key_exists('name', $data)
            ? trim((string) $data['name'])
            : (string) $fallback?->getAttribute('name');

        if ($name === '' || mb_strlen($name) > 255) {
            throw ProductOperationException::invalid('name', 'Name is required and may not exceed 255 characters.');
        }

        $slugValue = array_key_exists('slug', $data)
            ? trim((string) $data['slug'])
            : (string) $fallback?->getAttribute('slug');
        $slug = $this->normalizer->normalizeProductSlug($slugValue === '' ? $name : $slugValue);

        if ($slug === '') {
            throw ProductOperationException::invalid('slug', 'Slug is required.');
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'short_description' => $this->nullableText($data, 'short_description', 500, $fallback),
            'description' => $this->nullableText($data, 'description', 10000, $fallback),
            'brand' => $this->nullableText($data, 'brand', 255, $fallback),
            'manufacturer' => $this->nullableText($data, 'manufacturer', 255, $fallback),
        ];
    }

    /** @param array<string, mixed> $data */
    private function nullableText(array $data, string $field, int $maximum, ?Product $fallback): ?string
    {
        if (! array_key_exists($field, $data)) {
            $value = $fallback?->getAttribute($field);

            return is_string($value) ? $value : null;
        }

        $value = trim((string) ($data[$field] ?? ''));

        if (mb_strlen($value) > $maximum) {
            throw ProductOperationException::invalid($field, "The {$field} field may not exceed {$maximum} characters.");
        }

        return $value === '' ? null : $value;
    }

    protected function isDuplicateKey(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }
}
