<?php

namespace App\Actions\Catalog\Media;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Exceptions\Catalog\MediaOperationException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\CatalogLifecycleGuard;
use App\Services\Catalog\Media\CatalogMediaStorage;
use App\Services\Catalog\Media\MediaStorageFence;
use App\Services\Storage\FileWriteCompensation;
use App\Services\Storage\StorageQuotaAdmission;
use App\Support\Catalog\Media\ImageUploadValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class UploadVariantMediaAction extends MediaAction
{
    public function __construct(CompanyAuthorizer $authorizer, AuditLogger $auditLogger, CatalogLifecycleGuard $lifecycle, private readonly ImageUploadValidator $validator, private readonly CatalogMediaStorage $storage)
    {
        parent::__construct($authorizer, $auditLogger, $lifecycle);
    }

    public function execute(User $actor, Company $company, Product $product, ProductVariant $variant, UploadedFile $file, ?string $altText = null, ?string $caption = null, bool $makePrimary = false, mixed $sortOrder = null): ProductMedia
    {
        $company = $this->authorize($actor, $company);
        $this->assertProduct($company, $product);
        $this->assertVariant($company, $product, $variant);
        $image = $this->validator->validate($file);
        $uuid = (string) Str::uuid();
        $path = $this->storage->path($company, $product, $variant, $uuid, $image->extension);
        $lease = app(MediaStorageFence::class)->writer($this->storage->diskName());
        $quota = app(StorageQuotaAdmission::class);
        $reservation = $quota->reserve($company, $this->storage->diskName(), $path, $image->sizeBytes, $uuid);
        $cleanup = app(FileWriteCompensation::class)->track(
            fn (): bool => ProductMedia::withTrashed()->where('company_id', $company->getKey())
                ->where('uuid', $uuid)->sharedLock()->exists(),
            fn (): bool => $quota->compensate($company, $reservation, fn (): bool => $this->storage->delete($path)),
            ['company_uuid' => $company->uuid, 'media_uuid' => $uuid, 'operation' => 'media_upload'],
        );

        try {
            $this->storage->put($path, $image);
            $quota->written($company, $reservation);

            return DB::transaction(function () use ($actor, $company, $product, $variant, $image, $uuid, $path, $altText, $caption, $makePrimary, $sortOrder): ProductMedia {
                $company = $this->authorize($actor, $company);
                $product = Product::query()->forCompany($company)->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
                $variant = ProductVariant::query()->forCompany($company)->where('product_id', $product->getKey())->whereKey($variant->getKey())->lockForUpdate()->firstOrFail();
                $this->assertProduct($company, $product);
                $this->assertVariant($company, $product, $variant);
                if (ProductMedia::query()->forCompany($company)->where('product_id', $product->getKey())->count() >= (int) config('catalog.media.max_per_product')
                    || ProductMedia::query()->forCompany($company)->where('product_variant_id', $variant->getKey())->count() >= (int) config('catalog.media.max_per_variant')) {
                    throw MediaOperationException::invalid('image', 'The Variant image limit has been reached.', 'media_limit');
                }

                $fallbackOrder = ((int) ProductMedia::query()->where('product_variant_id', $variant->getKey())->max('sort_order')) + 10;
                $media = new ProductMedia;
                $media->forceFill([
                    'uuid' => $uuid, 'company_id' => $company->getKey(), 'product_id' => $product->getKey(), 'product_variant_id' => $variant->getKey(),
                    'original_filename' => $image->originalFilename, 'storage_path' => $path, 'mime_type' => $image->mimeType,
                    'size_bytes' => $image->sizeBytes, 'width' => $image->width, 'height' => $image->height,
                    'checksum_sha256' => $image->checksumSha256, 'alt_text' => $this->nullableText($altText, 'alt_text'),
                    'caption' => $this->nullableText($caption, 'caption'), 'sort_order' => $this->sortOrder($sortOrder, $fallbackOrder),
                    'uploaded_by' => $actor->getKey(),
                ])->save();
                $madePrimary = $variant->primary_media_id === null || $makePrimary;
                if ($madePrimary) {
                    $variant->forceFill(['primary_media_id' => $media->getKey(), 'updated_by' => $actor->getKey()])->save();
                }
                $this->auditLogger->logTenant($company, AuditEvent::CatalogMediaUploaded, $actor, $media, [
                    'product_uuid' => $product->uuid, 'variant_uuid' => $variant->uuid, 'media_uuid' => $media->uuid,
                    'mime_type' => $media->mime_type, 'size_bytes' => $media->size_bytes, 'width' => $media->width,
                    'height' => $media->height, 'checksum_prefix' => substr($media->checksum_sha256, 0, 12), 'made_primary' => $madePrimary,
                ]);

                return $media;
            });
        } catch (Throwable $exception) {
            $cleanup();
            throw $exception;
        } finally {
            if (DB::transactionLevel() === 0) {
                $lease?->release();
            }
        }
    }
}
