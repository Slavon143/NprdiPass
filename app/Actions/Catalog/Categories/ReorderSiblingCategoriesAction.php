<?php

namespace App\Actions\Catalog\Categories;

use App\Enums\AuditEvent;
use App\Exceptions\Catalog\CategoryOperationException;
use App\Models\Catalog\Category;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\CategoryHierarchyService;
use Illuminate\Support\Facades\DB;

class ReorderSiblingCategoriesAction extends CategoryAction
{
    /** @param list<string> $orderedUuids */
    public function execute(
        User $actor,
        Company $company,
        ?Category $parent,
        array $orderedUuids,
    ): void {
        $company = $this->authorize($actor, $company);

        if ($parent !== null) {
            $this->assertTenant($company, $parent);
        }

        DB::transaction(function () use ($actor, $company, $parent, $orderedUuids): void {
            $company = $this->authorize($actor, $company);
            $categories = $this->lockCompanyCategories($company);
            $parent = $this->freshParent($parent, $categories);
            if (count($orderedUuids) !== count(array_unique($orderedUuids))) {
                throw CategoryOperationException::invalidReorder('The category order contains duplicates.');
            }

            $parentId = $parent?->getKey();
            $siblings = $categories
                ->filter(fn (Category $category): bool => $category->getAttribute('parent_id') === $parentId)
                ->sortBy([
                    ['sort_order', 'asc'],
                    ['name', 'asc'],
                    ['id', 'asc'],
                ])
                ->values();
            $siblingUuids = $siblings->pluck('uuid')->map('strval')->all();
            $expected = $siblingUuids;
            $received = $orderedUuids;
            sort($expected);
            sort($received);

            if ($expected !== $received) {
                throw CategoryOperationException::invalidReorder('Submit the complete sibling category set for this parent.');
            }

            if ($siblingUuids === $orderedUuids) {
                return;
            }

            $byUuid = $siblings->keyBy('uuid');

            foreach ($orderedUuids as $index => $uuid) {
                $category = $byUuid->get($uuid);

                if (! $category instanceof Category) {
                    throw CategoryOperationException::invalidReorder('A category is not part of this sibling set.');
                }

                $category->forceFill([
                    'sort_order' => ($index + 1) * CategoryHierarchyService::SORT_STEP,
                    'updated_by' => $actor->getKey(),
                ])->save();
            }

            $this->auditLogger->logTenant(
                $company,
                AuditEvent::CatalogCategoryReordered,
                $actor,
                $parent ?? $company,
                [
                    'parent_uuid' => $parent?->getAttribute('uuid'),
                    'ordered_category_uuids' => $orderedUuids,
                    'category_count' => count($orderedUuids),
                ],
            );
        });
    }
}
