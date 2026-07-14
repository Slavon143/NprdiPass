<?php

namespace App\Services\Catalog\Media;

use App\Exceptions\Catalog\MediaOperationException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Support\Catalog\Media\MediaPathGuard;
use App\Support\Catalog\Media\ValidatedImage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CatalogMediaStorage
{
    public function __construct(private readonly MediaPathGuard $pathGuard) {}

    public function diskName(): string
    {
        return (string) config('catalog.media.disk', 'catalog_media');
    }

    public function disk(): FilesystemAdapter
    {
        return Storage::disk($this->diskName());
    }

    public function path(Company $company, Product $product, ?ProductVariant $variant, string $mediaUuid, string $extension): string
    {
        $base = $company->uuid.'/products/'.$product->uuid;
        $path = $variant === null
            ? $base.'/'.$mediaUuid.'.'.$extension
            : $base.'/variants/'.$variant->uuid.'/'.$mediaUuid.'.'.$extension;

        return $this->pathGuard->assertSafeRelative($path);
    }

    public function put(string $path, ValidatedImage $image): void
    {
        $path = $this->pathGuard->assertSafeRelative($path);
        $stream = fopen($image->temporaryPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('The validated image could not be opened.');
        }

        try {
            if (! $this->disk()->put($path, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('The image could not be stored.');
            }
        } finally {
            fclose($stream);
        }
    }

    public function delete(string $path): bool
    {
        return $this->disk()->delete($this->pathGuard->assertSafeRelative($path));
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($this->pathGuard->assertSafeRelative($path));
    }

    public function assertReadable(string $path): void
    {
        $path = $this->pathGuard->assertSafeRelative($path);
        $disk = $this->disk();

        if (! $disk->exists($path)) {
            throw MediaOperationException::invalid('media', 'The image file is unavailable.', 'missing_media_file');
        }

        $configuration = config('filesystems.disks.'.$this->diskName(), []);
        if (($configuration['driver'] ?? null) === 'local') {
            $this->pathGuard->assertExistingLocalContainment($disk->path(''), $disk->path($path));
        }
    }
}
