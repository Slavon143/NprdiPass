<?php

namespace App\Actions\Catalog\Categories;

use App\Enums\AuditEvent;
use App\Enums\Catalog\CategoryStatus;
use App\Exceptions\Catalog\CategoryOperationException;
use App\Models\Catalog\Category;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\CategoryHierarchyService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CreateCategoryAction extends CategoryAction
{
    /** @param array<string, mixed> $data */
    public function execute(User $actor, Company $company, array $data, ?Category $parent = null): Category
    {
        $company = $this->authorize($actor, $company);

        if ($parent !== null) {
            $this->assertTenant($company, $parent);
        }

        $name = $this->normalizedName($data);
        $slug = $this->normalizedSlug($data, $name);
        $description = $this->normalizedDescription($data);
        $sortOrder = $this->normalizedSortOrder($data);

        try {
            return DB::transaction(function () use (
                $actor,
                $company,
                $parent,
                $name,
                $slug,
                $description,
                $sortOrder,
            ): Category {
                $company = $this->authorize($actor, $company);
                $categories = $this->lockCompanyCategories($company);

                if ($categories->count() >= CategoryHierarchyService::MAX_CATEGORIES_PER_COMPANY) {
                    throw CategoryOperationException::limitExceeded();
                }

                $parent = $this->freshParent($parent, $categories);

                if ($parent?->status === CategoryStatus::Archived) {
                    throw CategoryOperationException::parentUnavailable('Archived parent cannot be used.');
                }

                $depth = $parent === null ? 0 : ((int) $parent->getAttribute('depth')) + 1;

                if ($depth > CategoryHierarchyService::MAX_DEPTH) {
                    throw CategoryOperationException::depthExceeded();
                }

                if ($categories->contains(
                    fn (Category $category): bool => $category->getRawOriginal('slug_normalized') === $slug,
                )) {
                    throw CategoryOperationException::slugConflict();
                }

                $category = new Category;
                $category->forceFill([
                    'company_id' => $company->getKey(),
                    'parent_id' => $parent?->getKey(),
                    'depth' => $depth,
                    'name' => $name,
                    'slug' => $slug,
                    'slug_normalized' => $slug,
                    'description' => $description,
                    'sort_order' => $sortOrder,
                    'status' => CategoryStatus::Active,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ])->save();

                $this->auditLogger->logTenant(
                    $company,
                    AuditEvent::CatalogCategoryCreated,
                    $actor,
                    $category,
                    [
                        'category_uuid' => $category->getAttribute('uuid'),
                        'name' => $name,
                        'slug' => $slug,
                        'parent_uuid' => $parent?->getAttribute('uuid'),
                        'depth' => $depth,
                    ],
                );

                return $category->refresh();
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateKey($exception)) {
                throw CategoryOperationException::slugConflict($exception);
            }

            throw $exception;
        }
    }
}
