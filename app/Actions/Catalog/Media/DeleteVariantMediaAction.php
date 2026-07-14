<?php

namespace App\Actions\Catalog\Media;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\Media\CatalogMediaStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteVariantMediaAction extends MediaAction
{
    public function __construct(CompanyAuthorizer $authorizer, AuditLogger $auditLogger, private readonly CatalogMediaStorage $storage)
    {
        parent::__construct($authorizer, $auditLogger);
    }

    public function execute(User $actor, Company $company, Product $product, ProductVariant $variant, ProductMedia $media): void
    {
        $company = $this->authorize($actor, $company);
        $this->assertVariantMedia($company, $product, $variant, $media);
        [$path, $uuid] = DB::transaction(function () use ($actor, $company, $product, $variant, $media): array {
            $this->authorize($actor, $company);
            $product = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $variant = ProductVariant::query()->forCompany($company)->where('product_id', $product->getKey())->whereKey($variant->getKey())->lockForUpdate()->firstOrFail();
            $media = ProductMedia::query()->forCompany($company)->whereKey($media->getKey())->lockForUpdate()->firstOrFail();
            $this->assertVariantMedia($company, $product, $variant, $media);
            $wasPrimary = (int) $variant->primary_media_id === (int) $media->getKey();
            if ($wasPrimary) {
                $variant->forceFill(['primary_media_id' => null, 'updated_by' => $actor->getKey()])->save();
            }
            $media->delete();
            $this->auditLogger->logTenant($company, AuditEvent::CatalogMediaDeleted, $actor, $media, ['media_uuid' => $media->uuid, 'product_uuid' => $product->uuid, 'variant_uuid' => $variant->uuid, 'was_primary' => $wasPrimary, 'mime_type' => $media->mime_type, 'size_bytes' => $media->size_bytes]);

            return [$media->storage_path, $media->uuid];
        });
        try {
            $this->storage->delete($path);
        } catch (Throwable) {
            Log::warning('Catalog media physical deletion failed.', ['company_uuid' => $company->uuid, 'product_uuid' => $product->uuid, 'variant_uuid' => $variant->uuid, 'media_uuid' => $uuid, 'operation' => 'delete', 'error_code' => 'physical_delete_failed']);
        }
    }
}
