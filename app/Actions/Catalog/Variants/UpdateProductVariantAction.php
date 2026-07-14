<?php

namespace App\Actions\Catalog\Variants;

use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class UpdateProductVariantAction extends VariantAction
{
    /** @param array<string, mixed> $data */
    public function execute(
        User $actor,
        Company $company,
        Product $product,
        ProductVariant $variant,
        array $data,
    ): ProductVariant {
        $company = $this->authorize($actor, $company, CompanyPermission::CatalogUpdate);
        $this->assertProductTenant($company, $product);
        $this->assertVariantOwner($company, $product, $variant);

        try {
            return DB::transaction(function () use ($actor, $company, $product, $variant, $data): ProductVariant {
                $company = $this->authorize($actor, $company, CompanyPermission::CatalogUpdate);
                $lockedProduct = Product::query()
                    ->forCompany($company)
                    ->whereKey($product->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedVariant = ProductVariant::query()
                    ->forCompany($company)
                    ->where('product_id', $lockedProduct->getKey())
                    ->whereKey($variant->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->lifecycle->assertVariantEditable($lockedProduct, $lockedVariant);
                $values = $this->normalizedData($data, $lockedVariant);
                $lockedVariant->forceFill($values);
                $changedFields = [];

                foreach (['name', 'sku', 'gtin', 'mpn', 'sort_order'] as $field) {
                    if ($lockedVariant->isDirty($field)) {
                        $changedFields[] = $field;
                    }
                }

                if ($changedFields === []) {
                    return $lockedVariant->load('product');
                }

                $lockedVariant->forceFill(['updated_by' => $actor->getKey()])->save();
                $this->auditLogger->logTenant(
                    $company,
                    AuditEvent::CatalogVariantUpdated,
                    $actor,
                    $lockedVariant,
                    [
                        'product_uuid' => $lockedProduct->uuid,
                        'variant_uuid' => $lockedVariant->uuid,
                        'changed_fields' => $changedFields,
                    ],
                );

                return $lockedVariant->refresh()->load('product');
            });
        } catch (QueryException $exception) {
            throw $this->mapConstraint($exception) ?? $exception;
        }
    }
}
