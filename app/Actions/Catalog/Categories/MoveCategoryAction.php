<?php

namespace App\Actions\Catalog\Categories;

use App\Enums\AuditEvent;
use App\Enums\Catalog\CategoryStatus;
use App\Exceptions\Catalog\CategoryOperationException;
use App\Models\Catalog\Category;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\CategoryHierarchyService;
use Illuminate\Support\Facades\DB;

class MoveCategoryAction extends CategoryAction
{
    public function execute(
        User $actor,
        Company $company,
        Category $category,
        ?Category $newParent,
    ): Category {
        $company = $this->authorize($actor, $company);
        $this->assertTenant($company, $category);

        if ($newParent !== null) {
            $this->assertTenant($company, $newParent);
        }

        return DB::transaction(function () use ($actor, $company, $category, $newParent): Category {
            $company = $this->authorize($actor, $company);
            $categories = $this->lockCompanyCategories($company);
            $category = $this->freshFrom($category, $categories);
            $newParent = $this->freshParent($newParent, $categories);

            if ($newParent?->status === CategoryStatus::Archived) {
                throw CategoryOperationException::parentUnavailable('Archived parent cannot be used.');
            }

            if ($this->hierarchy->wouldCreateCycle($company, $category, $newParent, $categories)) {
                throw CategoryOperationException::cycle();
            }

            $oldParentId = $category->getAttribute('parent_id');
            $newParentId = $newParent?->getKey();

            if ($oldParentId === $newParentId) {
                return $category;
            }

            $oldParent = $oldParentId === null
                ? null
                : $categories->firstWhere('id', $oldParentId);
            $oldDepth = (int) $category->getAttribute('depth');
            $newDepth = $newParent === null ? 0 : ((int) $newParent->getAttribute('depth')) + 1;
            $relativeDepth = $this->hierarchy->maximumRelativeSubtreeDepth($company, $category, $categories);

            if ($newDepth + $relativeDepth > CategoryHierarchyService::MAX_DEPTH) {
                throw CategoryOperationException::depthExceeded();
            }

            $subtree = $this->hierarchy->subtree($company, $category, $categories);
            $depthDelta = $newDepth - $oldDepth;

            foreach ($subtree as $node) {
                $values = [
                    'depth' => ((int) $node->getAttribute('depth')) + $depthDelta,
                    'updated_by' => $actor->getKey(),
                ];

                if ($node->is($category)) {
                    $values['parent_id'] = $newParentId;
                }

                $node->forceFill($values)->save();
            }

            $this->auditLogger->logTenant(
                $company,
                AuditEvent::CatalogCategoryMoved,
                $actor,
                $category,
                [
                    'category_uuid' => $category->getAttribute('uuid'),
                    'old_parent_uuid' => $oldParent instanceof Category ? $oldParent->getAttribute('uuid') : null,
                    'new_parent_uuid' => $newParent?->getAttribute('uuid'),
                    'old_depth' => $oldDepth,
                    'new_depth' => $newDepth,
                    'descendant_count' => $subtree->count() - 1,
                ],
            );

            return $category->refresh();
        });
    }
}
