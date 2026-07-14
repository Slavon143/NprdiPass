<?php

namespace App\Actions\Catalog\Media;

use App\Enums\AuditEvent;
use App\Exceptions\Catalog\MediaOperationException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderProductMediaAction extends MediaAction
{
    /** @param list<string> $uuids */
    public function execute(User $actor, Company $company, Product $product, array $uuids): void
    {
        $company = $this->authorize($actor, $company);
        $this->assertProduct($company, $product);
        if (count($uuids) !== count(array_unique($uuids))) {
            throw MediaOperationException::invalid('media_uuids', 'Duplicate images are not allowed.');
        }
        DB::transaction(function () use ($actor, $company, $product, $uuids): void {
            $this->authorize($actor, $company);
            $product = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $media = ProductMedia::query()->forCompany($company)->where('product_id', $product->getKey())->productLevel()->orderBy('id')->lockForUpdate()->get();
            if ($media->pluck('uuid')->sort()->values()->all() !== collect($uuids)->sort()->values()->all()) {
                throw MediaOperationException::invalid('media_uuids', 'The complete Product image set is required.');
            }
            $current = $media->sortBy([['sort_order', 'asc'], ['created_at', 'asc'], ['id', 'asc']])->pluck('uuid')->values()->all();
            if ($current === $uuids) {
                return;
            }
            foreach ($uuids as $index => $uuid) {
                $item = $media->firstWhere('uuid', $uuid);
                $item?->forceFill(['sort_order' => ($index + 1) * 10])->save();
            }
            $this->auditLogger->logTenant($company, AuditEvent::CatalogMediaReordered, $actor, $product, ['product_uuid' => $product->uuid, 'variant_uuid' => null, 'media_count' => count($uuids)]);
        });
    }
}
