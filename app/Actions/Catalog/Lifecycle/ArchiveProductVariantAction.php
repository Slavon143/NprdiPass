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

class ArchiveProductVariantAction extends LifecycleAction
{
    public function execute(User $actor, Company $company, Product $product, ProductVariant $variant): ProductVariant
    {
        $company = $this->authorize($actor, $company, CompanyPermission::CatalogArchive);
        $this->assertProduct($company, $product);
        $this->assertVariant($company, $product, $variant);

        return DB::transaction(function () use ($actor, $company, $product, $variant): ProductVariant {
            $company = $this->authorize($actor, $company, CompanyPermission::CatalogArchive);
            $lockedProduct = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $variants = ProductVariant::query()->forCompany($company)
                ->where('product_id', $lockedProduct->getKey())->orderBy('id')->lockForUpdate()->get();
            $lockedVariant = $variants->firstWhere('id', $variant->getKey());
            if (! $lockedVariant instanceof ProductVariant) {
                throw LifecycleOperationException::unavailable();
            }

            if ($lockedVariant->status === ProductVariantStatus::Archived) {
                return $lockedVariant;
            }

            if ((int) $lockedProduct->default_variant_id === (int) $lockedVariant->getKey()) {
                throw LifecycleOperationException::defaultVariant();
            }

            if ($variants->where('status', '!=', ProductVariantStatus::Archived)->count() <= 1) {
                throw LifecycleOperationException::lastVariant();
            }

            $previous = $lockedVariant->status;
            $lockedVariant->forceFill(['status' => ProductVariantStatus::Archived, 'updated_by' => $actor->getKey()])->save();
            $this->auditLogger->logTenant($company, AuditEvent::CatalogVariantArchived, $actor, $lockedVariant, [
                'product_uuid' => $lockedProduct->uuid,
                'variant_uuid' => $lockedVariant->uuid,
                'sku' => $lockedVariant->sku,
                'previous_status' => $previous->value,
                'new_status' => ProductVariantStatus::Archived->value,
                'was_default' => false,
            ]);

            return $lockedVariant->refresh();
        });
    }
}
