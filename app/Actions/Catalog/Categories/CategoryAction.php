<?php

namespace App\Actions\Catalog\Categories;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Exceptions\Catalog\CategoryOperationException;
use App\Models\Catalog\Category;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\CategoryHierarchyService;
use App\Support\Catalog\CatalogIdentifierNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

abstract class CategoryAction
{
    public function __construct(
        protected readonly CompanyAuthorizer $authorizer,
        protected readonly CatalogIdentifierNormalizer $normalizer,
        protected readonly AuditLogger $auditLogger,
        protected readonly CategoryHierarchyService $hierarchy,
    ) {}

    protected function authorize(User $actor, Company $company): Company
    {
        $freshCompany = Company::query()->find($company->getKey());

        if ($freshCompany?->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        $this->authorizer->authorize($actor, $freshCompany, CompanyPermission::CatalogManageCategories);

        return $freshCompany;
    }

    protected function assertTenant(Company $company, Category $category): void
    {
        if ((int) $category->getAttribute('company_id') !== (int) $company->getKey()) {
            throw CategoryOperationException::tenantMismatch();
        }
    }

    /** @return Collection<int, Category> */
    protected function lockCompanyCategories(Company $company): Collection
    {
        return Category::query()
            ->forCompany($company)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param Collection<int, Category> $categories */
    protected function freshFrom(Category $category, Collection $categories): Category
    {
        $fresh = $categories->first(fn (Category $candidate): bool => $candidate->is($category));

        if (! $fresh instanceof Category) {
            throw CategoryOperationException::tenantMismatch();
        }

        return $fresh;
    }

    /** @param Collection<int, Category> $categories */
    protected function freshParent(?Category $parent, Collection $categories): ?Category
    {
        return $parent === null ? null : $this->freshFrom($parent, $categories);
    }

    protected function normalizedName(array $data): string
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255) {
            throw CategoryOperationException::invalid('name', 'Name is required and may not exceed 255 characters.');
        }

        return $name;
    }

    protected function normalizedSlug(array $data, string $fallback = ''): string
    {
        $value = trim((string) ($data['slug'] ?? ''));
        $slug = $this->normalizer->normalizeCategorySlug($value === '' ? $fallback : $value);

        if ($slug === '') {
            throw CategoryOperationException::invalid('slug', 'Slug is required.');
        }

        return $slug;
    }

    protected function normalizedDescription(array $data, ?string $fallback = null): ?string
    {
        if (! array_key_exists('description', $data)) {
            return $fallback;
        }

        $description = trim((string) ($data['description'] ?? ''));

        if (mb_strlen($description) > 1000) {
            throw CategoryOperationException::invalid('description', 'Description may not exceed 1,000 characters.');
        }

        return $description === '' ? null : $description;
    }

    protected function normalizedSortOrder(array $data, int $fallback = 0): int
    {
        if (! array_key_exists('sort_order', $data)) {
            return $fallback;
        }

        $sortOrder = filter_var($data['sort_order'], FILTER_VALIDATE_INT);

        if ($sortOrder === false || $sortOrder < 0 || $sortOrder > 4294967295) {
            throw CategoryOperationException::invalid('sort_order', 'Sort order must be a non-negative integer.');
        }

        return $sortOrder;
    }

    protected function isDuplicateKey(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }
}
