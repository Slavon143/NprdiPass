<?php

namespace App\Actions\Catalog\Categories;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BulkDeleteCategoriesAction
{
    public function __construct(
        protected readonly CompanyAuthorizer $authorizer,
        protected readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  list<string>  $uuids
     * @return array{deleted: list<string>, blocked: list<array{uuid: string, name: string, reason: string}>}
     */
    public function execute(User $actor, Company $company, array $uuids): array
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogManageCategories);

        return DB::transaction(function () use ($actor, $company, $uuids): array {
            $all = Category::query()
                ->forCompany($company)
                ->whereIn('uuid', $uuids)
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            $ids = $all->pluck('id')->all();

            $childrenByParent = Category::query()
                ->forCompany($company)
                ->whereIn('parent_id', $ids)
                ->whereNull('deleted_at')
                ->get(['id', 'parent_id'])
                ->groupBy('parent_id')
                ->map(fn (Collection $group) => $group->count());

            $primaryProductCounts = Product::query()
                ->forCompany($company)
                ->whereIn('primary_category_id', $ids)
                ->whereNull('deleted_at')
                ->get(['id', 'primary_category_id'])
                ->groupBy('primary_category_id')
                ->map(fn (Collection $group) => $group->count());

            $pivotCounts = DB::table('category_product')
                ->join('products', 'category_product.product_id', '=', 'products.id')
                ->where('category_product.company_id', $company->getKey())
                ->whereIn('category_product.category_id', $ids)
                ->whereNull('products.deleted_at')
                ->get(['category_product.category_id'])
                ->groupBy('category_id')
                ->map(fn (Collection $group) => $group->count());

            $deleted = [];
            $blocked = [];

            foreach ($uuids as $uuid) {
                $category = $all->get($uuid);

                if (! $category instanceof Category) {
                    continue;
                }

                $childCount = $childrenByParent->get($category->getKey(), 0);
                $primaryCount = $primaryProductCounts->get($category->getKey(), 0);
                $pivotCount = $pivotCounts->get($category->getKey(), 0);

                if ($childCount > 0) {
                    $blocked[] = [
                        'uuid' => $category->uuid,
                        'name' => $category->name,
                        'reason' => trans_choice(':count child categor|:count child categories', $childCount, ['count' => $childCount]),
                    ];

                    continue;
                }

                if ($primaryCount > 0) {
                    $blocked[] = [
                        'uuid' => $category->uuid,
                        'name' => $category->name,
                        'reason' => trans_choice('primary for :count product|primary for :count products', $primaryCount, ['count' => $primaryCount]),
                    ];

                    continue;
                }

                if ($pivotCount > 0) {
                    $blocked[] = [
                        'uuid' => $category->uuid,
                        'name' => $category->name,
                        'reason' => trans_choice('assigned to :count product|assigned to :count products', $pivotCount, ['count' => $pivotCount]),
                    ];

                    continue;
                }
            }

            if ($blocked !== []) {
                return ['deleted' => [], 'blocked' => $blocked];
            }

            foreach ($uuids as $uuid) {
                $category = $all->get($uuid);

                if (! $category instanceof Category) {
                    continue;
                }

                $category->forceFill(['updated_by' => $actor->getKey()])->save();
                $category->delete();

                $this->auditLogger->logTenant($company, AuditEvent::CatalogCategoryArchived, $actor, $category, [
                    'category_uuid' => $category->uuid,
                    'category_name' => $category->name,
                    'action' => 'bulk_deleted',
                ]);

                $deleted[] = $category->uuid;
            }

            return ['deleted' => $deleted, 'blocked' => $blocked];
        });
    }
}
