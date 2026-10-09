<?php

namespace App\Actions\Catalog\Products;

use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyPermission;
use App\Exceptions\Catalog\ProductOperationException;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\CatalogWriteTransaction;
use App\Support\Catalog\VariantIdentifierNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CreateProductAction extends ProductAction
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $additionalCategoryUuids
     * @param  array<string, mixed>  $defaultVariantIdentifiers
     */
    public function execute(
        User $actor,
        Company $company,
        array $data,
        ?string $primaryCategoryUuid = null,
        array $additionalCategoryUuids = [],
        bool $imported = false,
        array $defaultVariantIdentifiers = [],
    ): Product {
        $preflightData = null;
        if (DB::transactionLevel() === 0) {
            $company = $this->authorize($actor, $company, CompanyPermission::CatalogCreate);
            $preflightData = $this->normalizedData($data);
        }

        try {
            return app(CatalogWriteTransaction::class)->run(function () use (
                $actor,
                $company,
                $data,
                $preflightData,
                $primaryCategoryUuid,
                $additionalCategoryUuids,
                $imported,
                $defaultVariantIdentifiers,
            ): Product {
                $company = $this->authorize($actor, $company, CompanyPermission::CatalogCreate);
                $data = $preflightData ?? $this->normalizedData($data);
                $managed = ! ($imported && $company->commercial_model_version === 1);
                $this->capacity->assertPoolSlotAvailable($company);
                if ($managed) {
                    $this->capacity->assertSlotAvailable($company);
                }
                $product = $this->aggregateCreator->create($actor, $company, $data, [
                    'name' => 'Default',
                    ...app(VariantIdentifierNormalizer::class)->normalize($defaultVariantIdentifiers),
                    'sort_order' => 0,
                ], $managed);
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

                return $product->refresh()->loadMissing(['defaultVariant', 'primaryCategory', 'categories']);
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateKey($exception)) {
                $variantConflict = app(VariantIdentifierNormalizer::class)->conflict($exception);
                if ($variantConflict !== null) {
                    throw $variantConflict;
                }
                throw ProductOperationException::slugConflict($exception);
            }

            throw $exception;
        }
    }
}
