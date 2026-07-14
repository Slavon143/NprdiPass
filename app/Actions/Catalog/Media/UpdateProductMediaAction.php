<?php

namespace App\Actions\Catalog\Media;

use App\Enums\AuditEvent;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateProductMediaAction extends MediaAction
{
    /** @param array<string, mixed> $data */
    public function executeProduct(User $actor, Company $company, Product $product, ProductMedia $media, array $data): ProductMedia
    {
        return $this->execute($actor, $company, $product, null, $media, $data);
    }

    /** @param array<string, mixed> $data */
    public function executeVariant(User $actor, Company $company, Product $product, ProductVariant $variant, ProductMedia $media, array $data): ProductMedia
    {
        return $this->execute($actor, $company, $product, $variant, $media, $data);
    }

    /** @param array<string, mixed> $data */
    private function execute(User $actor, Company $company, Product $product, ?ProductVariant $variant, ProductMedia $media, array $data): ProductMedia
    {
        $company = $this->authorize($actor, $company);
        $variant === null ? $this->assertProductMedia($company, $product, $media) : $this->assertVariantMedia($company, $product, $variant, $media);

        return DB::transaction(function () use ($actor, $company, $product, $variant, $media, $data): ProductMedia {
            $this->authorize($actor, $company);
            Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            if ($variant !== null) {
                ProductVariant::query()->forCompany($company)->where('product_id', $product->getKey())->whereKey($variant->getKey())->lockForUpdate()->firstOrFail();
            }
            $media = ProductMedia::query()->forCompany($company)->whereKey($media->getKey())->lockForUpdate()->firstOrFail();
            $variant === null ? $this->assertProductMedia($company, $product, $media) : $this->assertVariantMedia($company, $product, $variant, $media);
            $media->fill([
                'alt_text' => $this->nullableText($data['alt_text'] ?? null, 'alt_text'),
                'caption' => $this->nullableText($data['caption'] ?? null, 'caption'),
                'sort_order' => $this->sortOrder($data['sort_order'] ?? null, (int) $media->sort_order),
            ]);
            $changed = array_keys($media->getDirty());
            if ($changed === []) {
                return $media;
            }
            $media->save();
            $this->auditLogger->logTenant($company, AuditEvent::CatalogMediaUpdated, $actor, $media, ['media_uuid' => $media->uuid, 'changed_fields' => $changed]);

            return $media;
        });
    }
}
