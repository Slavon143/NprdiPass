<?php

namespace App\Actions\Catalog\Products;

use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Exceptions\Catalog\ProductOperationException;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class UpdateProductAction extends ProductAction
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $additionalCategoryUuids
     */
    public function execute(
        User $actor,
        Company $company,
        Product $product,
        array $data,
        ?string $primaryCategoryUuid = null,
        array $additionalCategoryUuids = [],
    ): Product {
        $company = $this->authorize($actor, $company, CompanyPermission::CatalogUpdate);
        $this->assertTenant($company, $product);

        try {
            return DB::transaction(function () use (
                $actor,
                $company,
                $product,
                $data,
                $primaryCategoryUuid,
                $additionalCategoryUuids,
            ): Product {
                $company = $this->authorize($actor, $company, CompanyPermission::CatalogUpdate);
                $product = Product::query()
                    ->forCompany($company)
                    ->whereKey($product->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->lifecycle->assertProductEditable($product);
                $values = $this->normalizedData($data, $product);

                $duplicateSlug = Product::query()
                    ->forCompany($company)
                    ->where('slug_normalized', $values['slug'])
                    ->whereKeyNot($product->getKey())
                    ->exists();

                if ($duplicateSlug) {
                    throw ProductOperationException::slugConflict();
                }

                $product->forceFill([...$values, 'slug_normalized' => $values['slug']]);
                $changedFields = [];

                foreach (array_keys($values) as $field) {
                    if ($product->isDirty($field)) {
                        $changedFields[] = $field;
                    }
                }

                if ($changedFields !== []) {
                    $product->forceFill(['updated_by' => $actor->getKey()])->save();
                }

                $categorySync = $this->categories->sync(
                    $company,
                    $product,
                    $primaryCategoryUuid,
                    $additionalCategoryUuids,
                );

                if ($categorySync['old_primary_uuid'] !== $categorySync['new_primary_uuid']) {
                    $changedFields[] = 'primary_category';
                }

                if ($categorySync['old_category_ids'] !== $categorySync['new_category_ids']) {
                    $changedFields[] = 'categories';
                }

                if ($categorySync['changed']) {
                    $product->forceFill(['updated_by' => $actor->getKey()])->save();
                }

                if ($changedFields === []) {
                    return $product->load(['defaultVariant', 'primaryCategory', 'categories']);
                }

                $this->auditLogger->logTenant(
                    $company,
                    AuditEvent::CatalogProductUpdated,
                    $actor,
                    $product,
                    [
                        'product_uuid' => $product->uuid,
                        'changed_fields' => $changedFields,
                        'old_primary_category_uuid' => $categorySync['old_primary_uuid'],
                        'new_primary_category_uuid' => $categorySync['new_primary_uuid'],
                        'old_category_count' => count($categorySync['old_category_ids']),
                        'new_category_count' => count($categorySync['new_category_ids']),
                    ],
                );

                return $product->refresh()->load(['defaultVariant', 'primaryCategory', 'categories']);
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateKey($exception)) {
                throw ProductOperationException::slugConflict($exception);
            }

            throw $exception;
        }
    }
}
