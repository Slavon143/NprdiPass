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

class RestoreCategoryAction extends CategoryAction
{
    public function execute(User $actor, Company $company, Category $category): Category
    {
        $company = $this->authorize($actor, $company);
        $this->assertTenant($company, $category);

        return DB::transaction(function () use ($actor, $company, $category): Category {
            $company = $this->authorize($actor, $company);
            $categories = $this->lockCompanyCategories($company);
            $category = $this->freshFrom($category, $categories);

            if ($category->status === CategoryStatus::Active) {
                return $category;
            }

            $parentId = $category->getAttribute('parent_id');
            $parent = $parentId === null ? null : $categories->firstWhere('id', $parentId);

            if ($parentId !== null && (! $parent instanceof Category || $parent->status !== CategoryStatus::Active)) {
                throw CategoryOperationException::parentUnavailable('Restore the parent category first.');
            }

            $expectedDepth = $parent instanceof Category ? ((int) $parent->getAttribute('depth')) + 1 : 0;
            $relativeDepth = $this->hierarchy->maximumRelativeSubtreeDepth($company, $category, $categories);

            if ($expectedDepth !== (int) $category->getAttribute('depth')
                || $expectedDepth + $relativeDepth > CategoryHierarchyService::MAX_DEPTH) {
                throw CategoryOperationException::depthExceeded();
            }

            $category->forceFill([
                'status' => CategoryStatus::Active,
                'updated_by' => $actor->getKey(),
            ])->save();
            $this->auditLogger->logTenant(
                $company,
                AuditEvent::CatalogCategoryRestored,
                $actor,
                $category,
                [
                    'category_uuid' => $category->getAttribute('uuid'),
                    'status_before' => CategoryStatus::Archived->value,
                    'status_after' => CategoryStatus::Active->value,
                ],
            );

            return $category->refresh();
        });
    }
}
