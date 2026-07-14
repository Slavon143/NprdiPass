<?php

namespace App\Actions\Catalog\Media;

use App\Enums\AuditEvent;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SetPrimaryProductMediaAction extends MediaAction
{
    public function execute(User $actor, Company $company, Product $product, ProductMedia $media): Product
    {
        $company = $this->authorize($actor, $company);
        $this->assertProductMedia($company, $product, $media);

        return DB::transaction(function () use ($actor, $company, $product, $media): Product {
            $this->authorize($actor, $company);
            $product = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $media = ProductMedia::query()->forCompany($company)->whereKey($media->getKey())->lockForUpdate()->firstOrFail();
            $this->assertProductMedia($company, $product, $media);
            if ((int) $product->primary_media_id === (int) $media->getKey()) {
                return $product;
            }
            $oldValue = $product->primaryMedia()->value('uuid');
            $old = is_string($oldValue) ? $oldValue : null;
            $product->forceFill(['primary_media_id' => $media->getKey(), 'updated_by' => $actor->getKey()])->save();
            $this->auditLogger->logTenant($company, AuditEvent::CatalogMediaPrimaryChanged, $actor, $product, ['product_uuid' => $product->uuid, 'variant_uuid' => null, 'old_primary_media_uuid' => $old, 'new_primary_media_uuid' => $media->uuid]);

            return $product;
        });
    }
}
