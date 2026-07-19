<?php

namespace App\Actions\Catalog\Categories;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyPermission;
use App\Exceptions\Catalog\CategoryOperationException;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BulkArchiveCategoriesAction
{
    public function __construct(
        protected readonly CompanyAuthorizer $authorizer,
        protected readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  list<string>  $uuids
     * @return list<string>
     */
    public function execute(User $actor, Company $company, array $uuids): array
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogManageCategories);

        return DB::transaction(function () use ($actor, $company, $uuids): array {
            $allCategories = Category::query()
                ->forCompany($company)
                ->whereIn('uuid', $uuids)
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            if ($allCategories->count() !== count($uuids)) {
                throw CategoryOperationException::archiveBlocked('One or more selected categories are unavailable.');
            }

            $categoryIds = $allCategories->pluck('id')->all();

            $activeChildren = Category::query()
                ->forCompany($company)
                ->whereIn('parent_id', $categoryIds)
                ->where('status', CategoryStatus::Active->value)
                ->exists();

            if ($activeChildren) {
                throw CategoryOperationException::archiveBlocked('One or more categories have active child categories.');
            }

            $activePrimary = Product::query()
                ->forCompany($company)
                ->whereIn('primary_category_id', $categoryIds)
                ->where('status', ProductStatus::Active->value)
                ->exists();

            if ($activePrimary) {
                throw CategoryOperationException::archiveBlocked('One or more categories are primary for active products.');
            }

            $archivedUuids = [];

            foreach ($uuids as $uuid) {
                $category = $allCategories->get($uuid);

                if (! $category instanceof Category) {
                    continue;
                }

                if ($category->status === CategoryStatus::Archived) {
                    continue;
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
                        'category_uuid' => $category->uuid,
                        'status_before' => CategoryStatus::Active->value,
                        'status_after' => CategoryStatus::Archived->value,
                        'bulk_operation' => true,
                    ],
                );

                $archivedUuids[] = $category->uuid;
            }

            return $archivedUuids;
        });
    }
}
