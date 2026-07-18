<?php

namespace App\Actions\Catalog\Categories;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Exceptions\Catalog\CategoryOperationException;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteCategoryAction
{
    public function __construct(
        protected readonly CompanyAuthorizer $authorizer,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function execute(User $actor, Company $company, Category $category): Category
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogManageCategories);

        return DB::transaction(function () use ($actor, $company, $category): Category {
            $locked = Category::query()
                ->forCompany($company)
                ->whereKey($category->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertNoChildren($company, $locked);
            $this->assertNoPrimaryProducts($company, $locked);
            $this->assertNoPivotProducts($company, $locked);

            $locked->forceFill(['updated_by' => $actor->getKey()])->save();
            $locked->delete();

            $this->auditLogger->logTenant($company, AuditEvent::CatalogCategoryArchived, $actor, $locked, [
                'category_uuid' => $locked->uuid,
                'category_name' => $locked->name,
                'action' => 'deleted',
            ]);

            return $locked;
        });
    }

    private function assertNoChildren(Company $company, Category $category): void
    {
        $count = Category::query()
            ->forCompany($company)
            ->where('parent_id', $category->getKey())
            ->whereNull('deleted_at')
            ->count();

        if ($count > 0) {
            throw CategoryOperationException::archiveBlocked(
                trans_choice('Category has :count child categor:|Category has :count child categories.', $count, ['count' => $count])
            );
        }
    }

    private function assertNoPrimaryProducts(Company $company, Category $category): void
    {
        $count = Product::query()
            ->forCompany($company)
            ->where('primary_category_id', $category->getKey())
            ->whereNull('deleted_at')
            ->count();

        if ($count > 0) {
            throw CategoryOperationException::archiveBlocked(
                trans_choice('Category is primary for :count product.|Category is primary for :count products.', $count, ['count' => $count])
            );
        }
    }

    private function assertNoPivotProducts(Company $company, Category $category): void
    {
        $count = Product::query()
            ->forCompany($company)
            ->whereNull('deleted_at')
            ->whereHas('categories', fn ($q) => $q->where('category_id', $category->getKey()))
            ->count();

        if ($count > 0) {
            throw CategoryOperationException::archiveBlocked(
                trans_choice('Category is assigned to :count product.|Category is assigned to :count products.', $count, ['count' => $count])
            );
        }
    }
}
