<?php

namespace App\Actions\Catalog\Media;

use App\Enums\AuditEvent;
use App\Exceptions\Catalog\MediaOperationException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderVariantMediaAction extends MediaAction
{
    /** @param list<string> $uuids */
    public function execute(User $actor, Company $company, Product $product, ProductVariant $variant, array $uuids): void
    {
        $company = $this->authorize($actor, $company);
        $this->assertVariant($company, $product, $variant);
        if (count($uuids) !== count(array_unique($uuids))) {
            throw MediaOperationException::invalid('media_uuids', 'Duplicate images are not allowed.');
        }
        DB::transaction(function () use ($actor, $company, $product, $variant, $uuids): void {
            $this->authorize($actor, $company);
            $product = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $variant = ProductVariant::query()->forCompany($company)->where('product_id', $product->getKey())->whereKey($variant->getKey())->lockForUpdate()->firstOrFail();
            $this->assertProduct($company, $product);
            $this->assertVariant($company, $product, $variant);
            $media = ProductMedia::query()->forCompany($company)->where('product_id', $product->getKey())->where('product_variant_id', $variant->getKey())->orderBy('id')->lockForUpdate()->get();
            if ($media->pluck('uuid')->sort()->values()->all() !== collect($uuids)->sort()->values()->all()) {
                throw MediaOperationException::invalid('media_uuids', 'The complete Variant image set is required.');
            }
            $current = $media->sortBy([['sort_order', 'asc'], ['created_at', 'asc'], ['id', 'asc']])->pluck('uuid')->values()->all();
            if ($current === $uuids) {
                return;
            }
            foreach ($uuids as $index => $uuid) {
                $item = $media->firstWhere('uuid', $uuid);
                $item?->forceFill(['sort_order' => ($index + 1) * 10])->save();
            }
            $this->auditLogger->logTenant($company, AuditEvent::CatalogMediaReordered, $actor, $variant, ['product_uuid' => $product->uuid, 'variant_uuid' => $variant->uuid, 'media_count' => count($uuids)]);
        });
    }
}
