<?php

namespace App\Actions\Catalog\Media;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\CatalogLifecycleGuard;
use App\Services\Catalog\Media\CatalogMediaStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteProductMediaAction extends MediaAction
{
    public function __construct(CompanyAuthorizer $authorizer, AuditLogger $auditLogger, CatalogLifecycleGuard $lifecycle, private readonly CatalogMediaStorage $storage)
    {
        parent::__construct($authorizer, $auditLogger, $lifecycle);
    }

    public function execute(User $actor, Company $company, Product $product, ProductMedia $media): void
    {
        $company = $this->authorize($actor, $company);
        $this->assertProduct($company, $product);
        $this->assertProductMedia($company, $product, $media);
        [$path, $uuid] = DB::transaction(function () use ($actor, $company, $product, $media): array {
            $this->authorize($actor, $company);
            $product = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $this->assertProduct($company, $product);
            $media = ProductMedia::query()->forCompany($company)->whereKey($media->getKey())->lockForUpdate()->firstOrFail();
            $this->assertProductMedia($company, $product, $media);
            $wasPrimary = (int) $product->primary_media_id === (int) $media->getKey();
            if ($wasPrimary) {
                $product->forceFill(['primary_media_id' => null, 'updated_by' => $actor->getKey()])->save();
            }
            $media->delete();
            $this->auditLogger->logTenant($company, AuditEvent::CatalogMediaDeleted, $actor, $media, ['media_uuid' => $media->uuid, 'product_uuid' => $product->uuid, 'variant_uuid' => null, 'was_primary' => $wasPrimary, 'mime_type' => $media->mime_type, 'size_bytes' => $media->size_bytes]);

            return [$media->storage_path, $media->uuid];
        });
        $this->deletePhysical($company, $product, null, $uuid, $path);
    }

    protected function deletePhysical(Company $company, Product $product, ?string $variantUuid, string $mediaUuid, string $path): void
    {
        try {
            $this->storage->delete($path);
        } catch (Throwable) {
            Log::warning('Catalog media physical deletion failed.', ['company_uuid' => $company->uuid, 'product_uuid' => $product->uuid, 'variant_uuid' => $variantUuid, 'media_uuid' => $mediaUuid, 'operation' => 'delete', 'error_code' => 'physical_delete_failed']);
        }
    }
}
