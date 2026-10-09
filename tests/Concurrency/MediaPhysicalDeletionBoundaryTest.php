<?php

use App\Actions\Catalog\Media\DeleteProductMediaAction;
use App\Actions\Catalog\Media\DeleteVariantMediaAction;
use App\Actions\Catalog\Media\UploadProductMediaAction;
use App\Actions\Catalog\Media\UploadVariantMediaAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Companies\CreateCompanyForOwner;
use App\Models\Catalog\ProductMedia;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('physical media deletion waits for the outer commit and rollback preserves row and bytes', function (bool $variantLevel) {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toBeIn(['nordipass_testing', 'nordipass_commercial_20261004_testing']);
    $this->artisan('migrate:fresh')->assertSuccessful();
    Http::preventStrayRequests();
    Queue::fake();
    Storage::fake('catalog_media');
    $actor = User::factory()->create();
    $company = app(CreateCompanyForOwner::class)->execute($actor, 'Media transaction boundary');
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $product = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Retained image']);
    $file = UploadedFile::fake()->image('boundary.png', 20, 20);
    $variant = $product->defaultVariant;
    $media = $variantLevel
        ? app(UploadVariantMediaAction::class)->execute($actor, $company, $product, $variant, $file)
        : app(UploadProductMediaAction::class)->execute($actor, $company, $product, $file);
    $delete = fn () => $variantLevel
        ? app(DeleteVariantMediaAction::class)->execute($actor, $company, $product->fresh(), $variant->fresh(), $media->fresh())
        : app(DeleteProductMediaAction::class)->execute($actor, $company, $product->fresh(), $media->fresh());
    $path = $media->storage_path;
    $checksum = hash('sha256', Storage::disk('catalog_media')->get($path));
    DB::beginTransaction();
    try {
        $delete();
        expect(ProductMedia::query()->find($media->id))->toBeNull();
        Storage::disk('catalog_media')->assertExists($path);
    } finally {
        DB::rollBack();
    }
    expect(ProductMedia::query()->find($media->id))->not->toBeNull()
        ->and(hash('sha256', Storage::disk('catalog_media')->get($path)))->toBe($checksum);
    DB::beginTransaction();
    try {
        $delete();
        Storage::disk('catalog_media')->assertExists($path);
        DB::commit();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
    expect(ProductMedia::query()->find($media->id))->toBeNull();
    Storage::disk('catalog_media')->assertMissing($path);
})->with([false, true]);
