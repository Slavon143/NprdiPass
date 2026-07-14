<?php

namespace App\Actions\Catalog\Categories;

use App\Enums\AuditEvent;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Exceptions\Catalog\CategoryOperationException;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArchiveCategoryAction extends CategoryAction
{
    public function execute(User $actor, Company $company, Category $category): Category
    {
        $company = $this->authorize($actor, $company);
        $this->assertTenant($company, $category);

        return DB::transaction(function () use ($actor, $company, $category): Category {
            $company = $this->authorize($actor, $company);
            $categories = $this->lockCompanyCategories($company);
            $category = $this->freshFrom($category, $categories);

            if ($category->status === CategoryStatus::Archived) {
                return $category;
            }

            $hasActiveChildren = $categories->contains(fn (Category $candidate): bool => $candidate->getAttribute('parent_id') === $category->getKey()
                && $candidate->status === CategoryStatus::Active
            );

            if ($hasActiveChildren) {
                throw CategoryOperationException::archiveBlocked('Category has active child categories.');
            }

            $activePrimaryProducts = Product::query()
                ->forCompany($company)
                ->where('primary_category_id', $category->getKey())
                ->where('status', ProductStatus::Active->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            if ($activePrimaryProducts->isNotEmpty()) {
                throw CategoryOperationException::archiveBlocked('Category is primary for active products.');
            }

            $category->forceFill([
                'status' => CategoryStatus::Archived,
                'updated_by' => $actor->getKey(),
            ])->save();
            $this->auditLogger->logTenant(
                $company,
                AuditEvent::CatalogCategoryArchived,
                $actor,
                $category,
                [
                    'category_uuid' => $category->getAttribute('uuid'),
                    'status_before' => CategoryStatus::Active->value,
                    'status_after' => CategoryStatus::Archived->value,
                ],
            );

            return $category->refresh();
        });
    }
}
