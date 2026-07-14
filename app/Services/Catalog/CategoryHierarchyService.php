<?php

namespace App\Services\Catalog;

use App\Exceptions\Catalog\CategoryOperationException;
use App\Models\Catalog\Category;
use App\Models\Company;
use Illuminate\Support\Collection;

class CategoryHierarchyService
{
    public const MAX_DEPTH = 5;

    public const MAX_CATEGORIES_PER_COMPANY = 500;

    public const SORT_STEP = 10;

    /**
     * @param  Collection<int, Category>  $categories
     * @return list<int>
     */
    public function ancestorIds(Company $company, Category $category, Collection $categories): array
    {
        $this->assertTenant($company, $category);
        $byId = $this->tenantCategories($company, $categories)->keyBy('id');
        $ancestorIds = [];
        $seen = [$category->getKey() => true];
        $parentId = $category->getAttribute('parent_id');

        while (is_int($parentId)) {
            if (isset($seen[$parentId])) {
                throw CategoryOperationException::cycle('The category hierarchy contains a cycle.');
            }

            $parent = $byId->get($parentId);

            if (! $parent instanceof Category) {
                break;
            }

            $ancestorIds[] = $parentId;
            $seen[$parentId] = true;
            $parentId = $parent->getAttribute('parent_id');
        }

        return $ancestorIds;
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return list<int>
     */
    public function descendantIds(Company $company, Category $category, Collection $categories): array
    {
        $this->assertTenant($company, $category);
        $tenantCategories = $this->tenantCategories($company, $categories);
        $descendantIds = [];
        $frontier = [$category->getKey()];
        $seen = [$category->getKey() => true];

        while ($frontier !== []) {
            $children = $tenantCategories
                ->filter(fn (Category $candidate): bool => in_array($candidate->getAttribute('parent_id'), $frontier, true));
            $frontier = [];

            foreach ($children as $child) {
                $childId = $child->getKey();

                if (isset($seen[$childId])) {
                    throw CategoryOperationException::cycle('The category hierarchy contains a cycle.');
                }

                $seen[$childId] = true;
                $descendantIds[] = $childId;
                $frontier[] = $childId;
            }
        }

        return $descendantIds;
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return Collection<int, Category>
     */
    public function subtree(Company $company, Category $category, Collection $categories): Collection
    {
        $ids = [$category->getKey(), ...$this->descendantIds($company, $category, $categories)];

        return $this->tenantCategories($company, $categories)
            ->filter(fn (Category $candidate): bool => in_array($candidate->getKey(), $ids, true))
            ->values();
    }

    /** @param Collection<int, Category> $categories */
    public function maximumRelativeSubtreeDepth(Company $company, Category $category, Collection $categories): int
    {
        $maximumDepth = $this->subtree($company, $category, $categories)
            ->max(fn (Category $candidate): int => (int) $candidate->getAttribute('depth'));

        return (int) $maximumDepth - (int) $category->getAttribute('depth');
    }

    /** @param Collection<int, Category> $categories */
    public function wouldCreateCycle(
        Company $company,
        Category $category,
        ?Category $newParent,
        Collection $categories,
    ): bool {
        if ($newParent === null) {
            return false;
        }

        $this->assertTenant($company, $newParent);

        return $newParent->is($category)
            || in_array($newParent->getKey(), $this->descendantIds($company, $category, $categories), true);
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return list<Category>
     */
    public function breadcrumb(Company $company, Category $category, Collection $categories): array
    {
        $byId = $this->tenantCategories($company, $categories)->keyBy('id');
        $ancestors = [];

        foreach ($this->ancestorIds($company, $category, $categories) as $ancestorId) {
            $ancestor = $byId->get($ancestorId);

            if ($ancestor instanceof Category) {
                $ancestors[] = $ancestor;
            }
        }

        return [...array_reverse($ancestors), $category];
    }

    private function assertTenant(Company $company, Category $category): void
    {
        if ((int) $category->getAttribute('company_id') !== (int) $company->getKey()) {
            throw CategoryOperationException::tenantMismatch();
        }
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return Collection<int, Category>
     */
    private function tenantCategories(Company $company, Collection $categories): Collection
    {
        return $categories
            ->filter(fn (Category $category): bool => (int) $category->getAttribute('company_id') === (int) $company->getKey())
            ->values();
    }
}
