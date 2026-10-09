<?php

use App\Models\Catalog\ProductMedia;
use App\Models\Company;
use App\Services\Catalog\Media\CatalogMediaStorage;
use App\Services\Catalog\Operations\CatalogMediaOrphanScanner;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;

function orphanScannerProduct(Company $company): int
{
    $uuid = (string) Str::uuid();

    return DB::table('products')->insertGetId([
        'uuid' => $uuid, 'company_id' => $company->id, 'name' => 'Scanner fixture',
        'slug' => $uuid, 'slug_normalized' => $uuid, 'status' => 'draft',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function orphanScannerMedia(Company $company, int $product, string $path): ProductMedia
{
    return ProductMedia::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'product_id' => $product,
        'storage_path' => $path, 'original_filename' => 'fixture.jpg',
        'mime_type' => 'image/jpeg', 'size_bytes' => 1, 'checksum_sha256' => str_repeat('a', 64),
        'sort_order' => 0,
    ]);
}

test('orphan scanning lists only tenant storage and checks references in bounded batches', function () {
    $company = Company::factory()->create();
    $prefix = $company->uuid.'/';
    $known = orphanScannerMedia($company, orphanScannerProduct($company), $prefix.'kept.jpg');
    $known->delete();
    $driver = Mockery::mock(FilesystemOperator::class);
    $driver->shouldReceive('listContents')->once()->with($prefix, true)->andReturn(
        new DirectoryListing((function () use ($prefix) {
            yield new FileAttributes($prefix.'kept.jpg', 1, null, now()->subDays(2)->timestamp);
            for ($i = 0; $i < 1001; $i++) {
                yield new FileAttributes($prefix.'orphan-'.$i.'.jpg', 1, null, now()->subDays(2)->timestamp);
            }
            yield new FileAttributes('another-company/secret.jpg', 1, null, 1);
            yield new FileAttributes($prefix.'recent.jpg', 1, null, now()->timestamp);
        })())
    );
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldNotReceive('allFiles');
    $disk->shouldReceive('getDriver')->once()->andReturn($driver);
    $storage = Mockery::mock(CatalogMediaStorage::class);
    $storage->shouldReceive('disk')->once()->andReturn($disk);
    DB::enableQueryLog();
    $result = (new CatalogMediaOrphanScanner($storage))->scanOrphans($company);
    $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'product_media'));
    DB::disableQueryLog();
    expect($result)->toHaveCount(1001)->not->toContain($known->storage_path)
        ->and($queries)->toHaveCount(3);
    foreach ($queries as $query) {
        expect($query['query'])->toContain('company_id')->and($query['bindings'])->toContain($company->id);
    }
});

test('missing file scanning batches product reads and reports the actual variant UUID', function () {
    Storage::fake('catalog_media');
    $company = Company::factory()->create();
    $product = orphanScannerProduct($company);
    $variantUuid = (string) Str::uuid();
    $variant = DB::table('product_variants')->insertGetId([
        'uuid' => $variantUuid, 'company_id' => $company->id, 'product_id' => $product,
        'name' => 'Default', 'status' => 'draft', 'sort_order' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    for ($i = 0; $i < 20; $i++) {
        $media = orphanScannerMedia($company, $product, $company->uuid.'/missing-'.$i.'.jpg');
        $media->forceFill(['product_variant_id' => $variant])->save();
    }
    DB::enableQueryLog();
    $result = app(CatalogMediaOrphanScanner::class)->scanMissingFiles($company);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($result)->toHaveCount(20)->and(count($queries))->toBeLessThanOrEqual(4)
        ->and(array_unique(array_column($result, 'variant_uuid')))->toBe([$variantUuid]);
});

test('missing file scanning does not disclose a product from a corrupt cross tenant pointer', function () {
    Storage::fake('catalog_media');
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $otherProduct = orphanScannerProduct($other);
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    try {
        orphanScannerMedia($company, $otherProduct, $company->uuid.'/missing.jpg');
    } finally {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
    $result = app(CatalogMediaOrphanScanner::class)->scanMissingFiles($company);
    expect($result)->toHaveCount(1)->and($result[0]['product_uuid'])->toBe('')
        ->and($result[0]['variant_uuid'])->toBeNull();
});

test('unavailable storage is not reported as a confirmed missing file', function () {
    $company = Company::factory()->create();
    orphanScannerMedia($company, orphanScannerProduct($company), $company->uuid.'/unknown.jpg');
    $storage = Mockery::mock(CatalogMediaStorage::class);
    $storage->shouldReceive('exists')->once()->andThrow(new RuntimeException('Storage unavailable'));
    expect(fn () => (new CatalogMediaOrphanScanner($storage))->scanMissingFiles($company))
        ->toThrow(RuntimeException::class);
});
