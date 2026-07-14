<?php

namespace App\Actions\Catalog\Variants;

use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyPermission;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CreateProductVariantAction extends VariantAction
{
    /** @param array<string, mixed> $data */
    public function execute(User $actor, Company $company, Product $product, array $data): ProductVariant
    {
        $company = $this->authorize($actor, $company, CompanyPermission::CatalogCreate);
        $this->assertProductTenant($company, $product);

        try {
            return DB::transaction(function () use ($actor, $company, $product, $data): ProductVariant {
                $company = $this->authorize($actor, $company, CompanyPermission::CatalogCreate);
                $lockedProduct = Product::query()
                    ->forCompany($company)
                    ->whereKey($product->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->variants->assertCapacity($lockedProduct);
                $values = $this->normalizedData($data);
                $variant = new ProductVariant;
                $variant->forceFill([
                    'company_id' => $company->getKey(),
                    'product_id' => $lockedProduct->getKey(),
                    ...$values,
                    'status' => ProductVariantStatus::Draft,
                    'primary_media_id' => null,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ])->save();

                $this->auditLogger->logTenant(
                    $company,
                    AuditEvent::CatalogVariantCreated,
                    $actor,
                    $variant,
                    [
                        'product_uuid' => $lockedProduct->uuid,
                        'variant_uuid' => $variant->uuid,
                        'variant_name' => $variant->name,
                        'sku' => $variant->sku,
                        'gtin_present' => $variant->gtin !== null,
                        'mpn_present' => $variant->mpn !== null,
                        'status' => ProductVariantStatus::Draft->value,
                    ],
                );

                return $variant->refresh()->load('product');
            });
        } catch (QueryException $exception) {
            throw $this->mapConstraint($exception) ?? $exception;
        }
    }
}
