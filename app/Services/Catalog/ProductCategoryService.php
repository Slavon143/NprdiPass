<?php

namespace App\Services\Catalog;

use App\Enums\Catalog\CategoryStatus;
use App\Exceptions\Catalog\ProductOperationException;
use App\Models\Catalog\Category;
use App\Models\Catalog\CategoryProduct;
use App\Models\Catalog\Product;
use App\Models\Company;

class ProductCategoryService
{
    public const MAX_CATEGORIES_PER_PRODUCT = 20;

    /**
     * @param  list<string>  $additionalCategoryUuids
     * @return array{
     *   changed: bool,
     *   old_primary_uuid: string|null,
     *   new_primary_uuid: string|null,
     *   old_category_ids: list<int>,
     *   new_category_ids: list<int>
     * }
     */
    public function sync(
        Company $company,
        Product $product,
        ?string $primaryCategoryUuid,
        array $additionalCategoryUuids,
    ): array {
        if ((int) $product->getAttribute('company_id') !== (int) $company->getKey()) {
            throw ProductOperationException::tenantMismatch();
        }

        $primaryCategoryUuid = $this->normalizedUuid($primaryCategoryUuid);
        $additionalCategoryUuids = array_map(
            fn (mixed $uuid): string => $this->requiredUuid($uuid),
            $additionalCategoryUuids,
        );
        $requestedUuids = array_values(array_unique(array_filter([
            $primaryCategoryUuid,
            ...$additionalCategoryUuids,
        ])));

        if (count($requestedUuids) > self::MAX_CATEGORIES_PER_PRODUCT) {
            throw ProductOperationException::tooManyCategories();
        }

        $categories = Category::query()
            ->forCompany($company)
            ->whereIn('uuid', $requestedUuids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('uuid');

        if ($primaryCategoryUuid !== null && ! $categories->has($primaryCategoryUuid)) {
            throw ProductOperationException::primaryCategoryUnavailable();
        }

        if ($categories->count() !== count($requestedUuids)) {
            throw ProductOperationException::categoriesUnavailable();
        }

        if ($categories->contains(fn (Category $category): bool => $category->status === CategoryStatus::Archived)) {
            throw ProductOperationException::archivedCategory();
        }

        $newCategoryIds = $categories->values()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        sort($newCategoryIds);
        $assignments = CategoryProduct::query()
            ->forCompany($company)
            ->where('product_id', $product->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $oldCategoryIds = $assignments->pluck('category_id')->map(fn (mixed $id): int => (int) $id)->all();
        sort($oldCategoryIds);
        $oldPrimaryId = $product->getAttribute('primary_category_id');
        $newPrimary = $primaryCategoryUuid === null ? null : $categories->get($primaryCategoryUuid);
        $newPrimaryId = $newPrimary instanceof Category ? $newPrimary->getKey() : null;
        $oldPrimaryUuid = $oldPrimaryId === null
            ? null
            : Category::withTrashed()->forCompany($company)->whereKey($oldPrimaryId)->value('uuid');
        $primaryChanged = $oldPrimaryId !== $newPrimaryId;
        $categoriesChanged = $oldCategoryIds !== $newCategoryIds;

        if ($categoriesChanged) {
            CategoryProduct::query()
                ->forCompany($company)
                ->where('product_id', $product->getKey())
                ->whereNotIn('category_id', $newCategoryIds)
                ->delete();

            $existingIds = array_flip($oldCategoryIds);

            foreach ($newCategoryIds as $categoryId) {
                if (isset($existingIds[$categoryId])) {
                    continue;
                }

                $assignment = new CategoryProduct;
                $assignment->forceFill([
                    'company_id' => $company->getKey(),
                    'product_id' => $product->getKey(),
                    'category_id' => $categoryId,
                    'created_at' => now(),
                ])->save();
            }
        }

        if ($primaryChanged) {
            $product->forceFill(['primary_category_id' => $newPrimaryId])->save();
        }

        return [
            'changed' => $primaryChanged || $categoriesChanged,
            'old_primary_uuid' => is_string($oldPrimaryUuid) ? $oldPrimaryUuid : null,
            'new_primary_uuid' => $newPrimary instanceof Category ? $newPrimary->uuid : null,
            'old_category_ids' => $oldCategoryIds,
            'new_category_ids' => $newCategoryIds,
        ];
    }

    private function normalizedUuid(?string $uuid): ?string
    {
        $uuid = $uuid === null ? '' : trim($uuid);

        return $uuid === '' ? null : $uuid;
    }

    private function requiredUuid(mixed $uuid): string
    {
        if (! is_string($uuid) || trim($uuid) === '') {
            throw ProductOperationException::categoriesUnavailable();
        }

        return trim($uuid);
    }
}
