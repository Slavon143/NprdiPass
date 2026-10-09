<?php

namespace App\Actions\Catalog\Products;

use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Exceptions\Catalog\ProductOperationException;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\CatalogWriteTransaction;
use Illuminate\Database\QueryException;

class UpdateProductAction extends ProductAction
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $additionalCategoryUuids
     *
     * Category sync is opt-in: callers that do not manage taxonomy (for example
     * the Fortnox article applier updating only name/description) must not pass
     * $syncCategories, and the existing primary/additional categories are kept
     * untouched. Passing $syncCategories=true replaces the product taxonomy with
     * the provided UUIDs (empty lists clear it).
     */
    public function execute(
        User $actor,
        Company $company,
        Product $product,
        array $data,
        ?string $primaryCategoryUuid = null,
        array $additionalCategoryUuids = [],
        bool $syncCategories = false,
    ): Product {
        $company = $this->authorize($actor, $company, CompanyPermission::CatalogUpdate);
        $this->assertTenant($company, $product);

        try {
            return app(CatalogWriteTransaction::class)->run(function () use (
                $actor,
                $company,
                $product,
                $data,
                $primaryCategoryUuid,
                $additionalCategoryUuids,
                $syncCategories,
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

                $categorySync = $syncCategories
                    ? $this->categories->sync(
                        $company,
                        $product,
                        $primaryCategoryUuid,
                        $additionalCategoryUuids,
                    )
                    : null;

                if ($categorySync !== null && $categorySync['old_primary_uuid'] !== $categorySync['new_primary_uuid']) {
                    $changedFields[] = 'primary_category';
                }

                if ($categorySync !== null && $categorySync['old_category_ids'] !== $categorySync['new_category_ids']) {
                    $changedFields[] = 'categories';
                }

                if ($categorySync !== null && $categorySync['changed']) {
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
                        'old_primary_category_uuid' => $categorySync['old_primary_uuid'] ?? null,
                        'new_primary_category_uuid' => $categorySync['new_primary_uuid'] ?? null,
                        'old_category_count' => $categorySync !== null ? count($categorySync['old_category_ids']) : null,
                        'new_category_count' => $categorySync !== null ? count($categorySync['new_category_ids']) : null,
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
