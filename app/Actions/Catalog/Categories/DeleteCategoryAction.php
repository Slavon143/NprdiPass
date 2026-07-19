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
        $children = Category::query()
            ->forCompany($company)
            ->where('parent_id', $category->getKey())
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->limit(4)
            ->get(['name']);
        $count = Category::query()
            ->forCompany($company)
            ->where('parent_id', $category->getKey())
            ->whereNull('deleted_at')
            ->count();

        if ($count > 0) {
            throw CategoryOperationException::archiveBlocked(
                trans_choice('Category has :count child category.|Category has :count child categories.', $count, ['count' => $count])
                .' '.$this->examples('Children', $children->pluck('name')->all())
                .' Delete or move child categories first.'
            );
        }
    }

    private function assertNoPrimaryProducts(Company $company, Category $category): void
    {
        $products = Product::query()
            ->forCompany($company)
            ->where('primary_category_id', $category->getKey())
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->limit(4)
            ->get(['name', 'status']);
        $count = Product::query()
            ->forCompany($company)
            ->where('primary_category_id', $category->getKey())
            ->whereNull('deleted_at')
            ->count();

        if ($count > 0) {
            throw CategoryOperationException::archiveBlocked(
                trans_choice('Category is primary for :count product.|Category is primary for :count products.', $count, ['count' => $count])
                .' '.$this->examples('Products', $products->map(
                    fn (Product $product): string => "{$product->name} ({$product->status->value})"
                )->all())
                .' Change the primary category on those products first.'
            );
        }
    }

    private function assertNoPivotProducts(Company $company, Category $category): void
    {
        $products = Product::query()
            ->forCompany($company)
            ->whereNull('deleted_at')
            ->whereHas('categories', fn ($q) => $q->where('category_id', $category->getKey()))
            ->orderBy('name')
            ->limit(4)
            ->get(['name', 'status']);
        $count = Product::query()
            ->forCompany($company)
            ->whereNull('deleted_at')
            ->whereHas('categories', fn ($q) => $q->where('category_id', $category->getKey()))
            ->count();

        if ($count > 0) {
            throw CategoryOperationException::archiveBlocked(
                trans_choice('Category is assigned to :count product.|Category is assigned to :count products.', $count, ['count' => $count])
                .' '.$this->examples('Products', $products->map(
                    fn (Product $product): string => "{$product->name} ({$product->status->value})"
                )->all())
                .' Remove this category from those products first.'
            );
        }
    }

    /**
     * @param  list<string>  $items
     */
    private function examples(string $label, array $items): string
    {
        $items = array_values(array_filter($items, fn (string $item): bool => trim($item) !== ''));

        if ($items === []) {
            return '';
        }

        $suffix = count($items) === 4 ? ', …' : '';

        return "{$label}: ".implode(', ', array_slice($items, 0, 3)).$suffix.'.';
    }
}
