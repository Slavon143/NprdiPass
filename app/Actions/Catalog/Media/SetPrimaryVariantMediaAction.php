<?php

namespace App\Actions\Catalog\Media;

use App\Enums\AuditEvent;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SetPrimaryVariantMediaAction extends MediaAction
{
    public function execute(User $actor, Company $company, Product $product, ProductVariant $variant, ProductMedia $media): ProductVariant
    {
        $company = $this->authorize($actor, $company);
        $this->assertVariant($company, $product, $variant);
        $this->assertVariantMedia($company, $product, $variant, $media);

        return DB::transaction(function () use ($actor, $company, $product, $variant, $media): ProductVariant {
            $this->authorize($actor, $company);
            $product = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $variant = ProductVariant::query()->forCompany($company)->where('product_id', $product->getKey())->whereKey($variant->getKey())->lockForUpdate()->firstOrFail();
            $media = ProductMedia::query()->forCompany($company)->whereKey($media->getKey())->lockForUpdate()->firstOrFail();
            $this->assertVariantMedia($company, $product, $variant, $media);
            if ((int) $variant->primary_media_id === (int) $media->getKey()) {
                return $variant;
            }
            $oldValue = $variant->primaryMedia()->value('uuid');
            $old = is_string($oldValue) ? $oldValue : null;
            $variant->forceFill(['primary_media_id' => $media->getKey(), 'updated_by' => $actor->getKey()])->save();
            $this->auditLogger->logTenant($company, AuditEvent::CatalogMediaPrimaryChanged, $actor, $variant, ['product_uuid' => $product->uuid, 'variant_uuid' => $variant->uuid, 'old_primary_media_uuid' => $old, 'new_primary_media_uuid' => $media->uuid]);

            return $variant;
        });
    }
}
