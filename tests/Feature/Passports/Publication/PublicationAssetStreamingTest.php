<?php

use App\Models\Passports\ProductPassport;
use App\Services\Passports\Publication\PublicationAssetStager;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

test('large publication assets are streamed with identical checksums through promotion', function () {
    Storage::fake('product_documents');
    Storage::fake('passport_assets');
    $passport = ProductPassport::factory()->create();
    $source = Storage::disk('product_documents');
    $handle = fopen($source->path('large.pdf'), 'wb');
    for ($chunk = 0; $chunk < 40; $chunk++) {
        fwrite($handle, str_repeat(chr(65 + $chunk % 26), 1024 * 1024));
    }
    fclose($handle);
    $expected = hash_file('sha256', $source->path('large.pdf'));
    $stager = app(PublicationAssetStager::class);
    $version = (string) str()->uuid();
    $asset = (string) str()->uuid();
    memory_reset_peak_usage();
    $before = memory_get_usage(true);
    $staged = $stager->stageAssets($passport, $version, [[
        'asset_uuid' => $asset, 'source_disk' => 'product_documents',
        'source_storage_key' => 'large.pdf', 'file_extension' => 'pdf',
    ]]);
    $promoted = $stager->promoteAssets($staged, $version);
    $growth = memory_get_peak_usage(true) - $before;
    expect($growth)->toBeLessThan(8 * 1024 * 1024)
        ->and($promoted[$asset]['checksum'])->toBe($expected)
        ->and(hash_file('sha256', Storage::disk('passport_assets')->path($promoted[$asset]['path'])))->toBe($expected)
        ->and(Storage::disk('passport_assets')->exists($staged[$asset]['path']))->toBeFalse();
});

test('a partial remote promotion removes its new destination when move fails', function () {
    config(['filesystems.disks.passport_assets.driver' => 'remote']);
    $staging = 'companies/company/passports/passport/versions/version/staging/asset.pdf';
    $final = str_replace('/staging/', '/media/', $staging);
    $destination = Mockery::mock(FilesystemAdapter::class);
    $destination->shouldReceive('exists')->once()->with($final)->andReturnFalse();
    $destination->shouldReceive('move')->once()->with($staging, $final)->andReturnFalse();
    $destination->shouldReceive('exists')->once()->with($final)->andReturnTrue();
    $destination->shouldReceive('delete')->once()->with($final)->andReturnTrue();
    Storage::set('passport_assets', $destination);
    expect(fn () => app(PublicationAssetStager::class)->promoteAssets([
        'asset' => ['path' => $staging, 'checksum' => str_repeat('a', 64)],
    ], 'version'))->toThrow(RuntimeException::class, 'Failed to promote');
});

test('partial staging writes are cleaned even when the adapter throws before verification', function () {
    Storage::fake('product_documents');
    Storage::disk('product_documents')->put('source.pdf', 'complete source bytes');
    $passport = ProductPassport::factory()->create();
    $version = (string) str()->uuid();
    $asset = (string) str()->uuid();
    $path = "companies/{$passport->company->uuid}/passports/{$passport->uuid}/versions/{$version}/staging/{$asset}.pdf";
    $destination = Mockery::mock(FilesystemAdapter::class);
    $destination->shouldReceive('exists')->once()->with(str_replace('/staging/', '/media/', $path))->andReturnFalse();
    $destination->shouldReceive('put')->once()->with($path, Mockery::type('resource'))
        ->andThrow(new RuntimeException('Partial adapter write'));
    $destination->shouldReceive('exists')->once()->with($path)->andReturnTrue();
    $destination->shouldReceive('delete')->once()->with($path)->andReturnTrue();
    Storage::set('passport_assets', $destination);
    expect(fn () => app(PublicationAssetStager::class)->stageAssets($passport, $version, [[
        'asset_uuid' => $asset, 'source_disk' => 'product_documents',
        'source_storage_key' => 'source.pdf', 'file_extension' => 'pdf',
    ]]))->toThrow(RuntimeException::class, 'Partial adapter write');
});
