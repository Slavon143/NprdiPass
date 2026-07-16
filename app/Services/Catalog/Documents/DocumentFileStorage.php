<?php

namespace App\Services\Catalog\Documents;

use App\Models\Catalog\Product;
use App\Models\Company;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DocumentFileStorage
{
    public function diskName(): string
    {
        return (string) config('documents.disk', 'product_documents');
    }

    public function disk(): FilesystemAdapter
    {
        return Storage::disk($this->diskName());
    }

    public function buildStorageKey(Company $company, Product $product, string $documentUuid, string $versionUuid): string
    {
        $path = sprintf(
            'companies/%s/products/%s/documents/%s/versions/%s.pdf',
            $company->uuid,
            $product->uuid,
            $documentUuid,
            $versionUuid,
        );

        $this->assertSafeRelative($path);

        return $path;
    }

    public function put(string $storageKey, ValidatedPdf $pdf): void
    {
        $this->assertSafeRelative($storageKey);

        $stream = fopen($pdf->temporaryPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The validated PDF could not be opened.');
        }

        try {
            if (! $this->disk()->put($storageKey, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('The PDF could not be stored.');
            }
        } finally {
            fclose($stream);
        }
    }

    public function delete(string $storageKey): bool
    {
        return $this->disk()->delete($this->assertSafeRelative($storageKey));
    }

    public function exists(string $storageKey): bool
    {
        return $this->disk()->exists($this->assertSafeRelative($storageKey));
    }

    public function assertReadable(string $storageKey): void
    {
        $this->assertSafeRelative($storageKey);
        $disk = $this->disk();

        if (! $disk->exists($storageKey)) {
            throw new RuntimeException('The document file is unavailable.');
        }
    }

    public function assertSafeRelative(string $path): string
    {
        if ($path === '' || $path === '0') {
            throw new RuntimeException('The storage key must not be empty.');
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            throw new RuntimeException('The storage key must be relative.');
        }

        if (str_contains($path, '\\')) {
            throw new RuntimeException('The storage key must not contain backslashes.');
        }

        if (str_contains($path, '../') || str_contains($path, '..\\')) {
            throw new RuntimeException('The storage key must not contain traversal sequences.');
        }

        return $path;
    }

    public function verifyChecksum(string $storageKey, string $expectedChecksum): void
    {
        $this->assertReadable($storageKey);

        $actualChecksum = hash('sha256', $this->disk()->get($storageKey));

        if (! hash_equals($expectedChecksum, $actualChecksum)) {
            throw new RuntimeException('The stored file checksum does not match.');
        }
    }
}
