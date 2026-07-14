<?php

namespace App\Actions\Catalog\Lifecycle;

use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyPermission;
use App\Exceptions\Catalog\LifecycleOperationException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestoreProductVariantAction extends LifecycleAction
{
    public function execute(User $actor, Company $company, Product $product, ProductVariant $variant): ProductVariant
    {
        $company = $this->authorize($actor, $company, CompanyPermission::CatalogArchive);
        $this->assertProduct($company, $product);
        $this->assertVariant($company, $product, $variant);

        return DB::transaction(function () use ($actor, $company, $product, $variant): ProductVariant {
            $company = $this->authorize($actor, $company, CompanyPermission::CatalogArchive);
            $lockedProduct = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $lockedVariant = ProductVariant::query()->forCompany($company)
                ->where('product_id', $lockedProduct->getKey())->whereKey($variant->getKey())->lockForUpdate()->first();
            if (! $lockedVariant instanceof ProductVariant) {
                throw LifecycleOperationException::unavailable();
            }

            if ($lockedVariant->status === ProductVariantStatus::Active) {
                return $lockedVariant;
            }

            if ($lockedVariant->status !== ProductVariantStatus::Archived) {
                throw LifecycleOperationException::invalidTransition('Only an archived Variant can be restored.');
            }

            $lockedVariant->forceFill(['status' => ProductVariantStatus::Active, 'updated_by' => $actor->getKey()])->save();
            $this->auditLogger->logTenant($company, AuditEvent::CatalogVariantRestored, $actor, $lockedVariant, [
                'product_uuid' => $lockedProduct->uuid,
                'variant_uuid' => $lockedVariant->uuid,
                'sku' => $lockedVariant->sku,
                'previous_status' => ProductVariantStatus::Archived->value,
                'new_status' => ProductVariantStatus::Active->value,
                'was_default' => (int) $lockedProduct->default_variant_id === (int) $lockedVariant->getKey(),
            ]);

            return $lockedVariant->refresh();
        });
    }
}
