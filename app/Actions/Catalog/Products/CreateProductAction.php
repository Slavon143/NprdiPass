<?php

namespace App\Actions\Catalog\Products;

use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyPermission;
use App\Exceptions\Catalog\ProductOperationException;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CreateProductAction extends ProductAction
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $additionalCategoryUuids
     */
    public function execute(
        User $actor,
        Company $company,
        array $data,
        ?string $primaryCategoryUuid = null,
        array $additionalCategoryUuids = [],
    ): Product {
        $company = $this->authorize($actor, $company, CompanyPermission::CatalogCreate);
        $data = $this->normalizedData($data);

        try {
            return DB::transaction(function () use (
                $actor,
                $company,
                $data,
                $primaryCategoryUuid,
                $additionalCategoryUuids,
            ): Product {
                $company = $this->authorize($actor, $company, CompanyPermission::CatalogCreate);
                $product = $this->aggregateCreator->create($actor, $company, $data, [
                    'name' => 'Default',
                    'sku' => null,
                    'sku_normalized' => null,
                    'gtin' => null,
                    'mpn' => null,
                    'sort_order' => 0,
                ]);
                $categorySync = $this->categories->sync(
                    $company,
                    $product,
                    $primaryCategoryUuid,
                    $additionalCategoryUuids,
                );
                $variant = $product->defaultVariant;

                $this->auditLogger->logTenant(
                    $company,
                    AuditEvent::CatalogProductCreated,
                    $actor,
                    $product,
                    [
                        'product_uuid' => $product->uuid,
                        'name' => $product->name,
                        'slug' => $product->slug,
                        'status' => ProductStatus::Draft->value,
                        'default_variant_uuid' => $variant?->uuid,
                        'primary_category_uuid' => $categorySync['new_primary_uuid'],
                        'category_count' => count($categorySync['new_category_ids']),
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
