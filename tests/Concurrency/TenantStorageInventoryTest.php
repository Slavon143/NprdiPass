<?php

use App\Models\Company;
use App\Services\Storage\TenantStorageInventory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;

beforeEach(function () {
    if (! in_array(DB::connection()->getDatabaseName(), ['nordipass_testing', 'nordipass_commercial_20261004_testing', 'nordipass_reconcile_20261004_testing'], true)) {
        throw new RuntimeException('Owned isolated schema required.');
    }
    $this->artisan('migrate')->assertSuccessful();
    DB::beginTransaction();
    $this->company = Company::factory()->create();
    foreach (['catalog_media', 'product_documents', 'production_imports', 'passport_assets'] as $disk) {
        Storage::fake($disk);
    }
    config(['catalog.media.disk' => 'catalog_media', 'documents.disk' => 'product_documents', 'imports.disk' => 'production_imports']);
});

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

test('physical inventory includes retained history and orphan bytes only for the selected tenant', function () {
    $uuid = $this->company->uuid;
    Storage::disk('catalog_media')->put($uuid.'/products/orphan.jpg', str_repeat('x', 13));
    Storage::disk('catalog_media')->put('foreign-company/products/foreign.jpg', str_repeat('x', 1000));
    Storage::disk('product_documents')->put('companies/'.$uuid.'/documents/old-version.pdf', str_repeat('x', 17));
    Storage::disk('production_imports')->put('companies/'.$uuid.'/jobs/retained-source.csv', str_repeat('x', 19));
    Storage::disk('passport_assets')->put('companies/'.$uuid.'/versions/historical/media/image.jpg', str_repeat('x', 23));
    Storage::disk('passport_assets')->put('companies/foreign-company/versions/foreign.jpg', str_repeat('x', 1000));
    expect(app(TenantStorageInventory::class)->measure($this->company))->toBe(['files' => 4, 'bytes' => 72]);
});

test('aliases of the same physical tenant directory are charged once', function () {
    $documents = Storage::disk('product_documents');
    $mediaRoot = $documents->path('companies');
    config(['filesystems.disks.media_alias' => ['driver' => 'local', 'root' => $mediaRoot], 'catalog.media.disk' => 'media_alias']);
    Storage::set('media_alias', Storage::build(['driver' => 'local', 'root' => $mediaRoot, 'throw' => true]));
    $documents->put('companies/'.$this->company->uuid.'/shared.jpg', str_repeat('x', 29));
    expect(app(TenantStorageInventory::class)->measure($this->company))->toBe(['files' => 1, 'bytes' => 29]);
});

function storageInventoryListingFixture($company, iterable $entries): void
{
    $root = Storage::disk('catalog_media')->path('');
    $driver = Mockery::mock(FilesystemOperator::class);
    $driver->shouldReceive('listContents')->once()->with($company->uuid.'/', true)->andReturn(new DirectoryListing($entries));
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('path')->with('')->andReturn($root);
    $disk->shouldReceive('getDriver')->once()->andReturn($driver);
    Storage::set('catalog_media', $disk);
}

test('an unreadable or escaped inventory cannot return a misleading zero usage', function () {
    $entries = (function () {
        yield new FileAttributes('foreign-company/file.jpg', 5);
    })();
    storageInventoryListingFixture($this->company, $entries);
    expect(fn () => app(TenantStorageInventory::class)->measure($this->company))->toThrow(RuntimeException::class, 'escaped');
});

test('a 100k file inventory streams metadata without materializing tenant file paths', function () {
    $prefix = $this->company->uuid.'/';
    $entries = (function () use ($prefix) {
        for ($i = 0; $i < 100000; $i++) {
            yield new FileAttributes($prefix.'file-'.$i, 1);
        }
    })();
    storageInventoryListingFixture($this->company, $entries);
    $before = memory_get_usage(true);
    memory_reset_peak_usage();
    expect(app(TenantStorageInventory::class)->measure($this->company))->toBe(['files' => 100000, 'bytes' => 100000]);
    expect(memory_get_peak_usage(true) - $before)->toBeLessThan(8 * 1024 * 1024);
});
