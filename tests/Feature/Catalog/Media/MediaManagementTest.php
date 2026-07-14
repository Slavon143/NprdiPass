<?php

use App\Actions\Catalog\Media\DeleteProductMediaAction;
use App\Actions\Catalog\Media\ReorderProductMediaAction;
use App\Actions\Catalog\Media\SetPrimaryProductMediaAction;
use App\Actions\Catalog\Media\UpdateProductMediaAction;
use App\Actions\Catalog\Media\UploadProductMediaAction;
use App\Actions\Catalog\Media\UploadVariantMediaAction;
use App\Actions\Catalog\Products\ProductAggregateCreator;
use App\Enums\AuditEvent;
use App\Enums\CompanyRole;
use App\Exceptions\Catalog\MediaOperationException;
use App\Models\AuditLog;
use App\Models\Catalog\ProductMedia;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\Media\CatalogMediaStorage;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function r18Bytes(string $format = 'png'): string
{
    $values = [
        'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        'jpg' => '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==',
        'webp' => 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v89WAAAAA==',
    ];

    return base64_decode($values[$format], true) ?: throw new RuntimeException('Fixture decode failed.');
}

function r18File(string $format = 'png', ?string $name = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name ?? 'catalog-image.'.$format, r18Bytes($format));
}

function r18Context(CompanyRole $role = CompanyRole::Owner): array
{
    Storage::fake('catalog_media');
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->create(['user_id' => $user, 'company_id' => $company, 'role' => $role]);
    test()->actingAs($user);
    app(CurrentCompany::class)->set($company);
    $product = app(ProductAggregateCreator::class)->create($user, $company, ['name' => 'Media Product', 'slug' => 'media-product', 'short_description' => null, 'description' => null, 'brand' => null, 'manufacturer' => null], ['name' => 'Default', 'sku' => null, 'sku_normalized' => null, 'gtin' => null, 'mpn' => null, 'sort_order' => 0]);

    return [$user, $company, $product, $product->defaultVariant()->firstOrFail()];
}

test('valid JPEG PNG and WEBP uploads are content-verified and privately stored', function (string $format, string $mime) {
    [$user,$company,$product] = r18Context();
    $media = app(UploadProductMediaAction::class)->execute($user, $company, $product, r18File($format), ' Accessible image ', ' Caption ', false);
    expect($media->mime_type)->toBe($mime)->and($media->width)->toBe(1)->and($media->height)->toBe(1)
        ->and($media->checksum_sha256)->toMatch('/^[0-9a-f]{64}$/')->and($media->uploaded_by)->toBe($user->id)
        ->and($media->product_variant_id)->toBeNull()->and($product->fresh()?->primary_media_id)->toBe($media->id);
    Storage::disk('catalog_media')->assertExists($media->storage_path);
    expect($media->storage_path)->not->toContain($media->original_filename);
    expect(AuditLog::query()->where('event', AuditEvent::CatalogMediaUploaded->value)->count())->toBe(1);
})->with([['jpg', 'image/jpeg'], ['png', 'image/png'], ['webp', 'image/webp']]);

test('invalid content and MIME extension mismatches leave no row file pointer or audit', function () {
    [$user,$company,$product] = r18Context();
    $pointer = $product->primary_media_id;
    expect(fn () => app(UploadProductMediaAction::class)->execute($user, $company, $product, UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo 1;')))->toThrow(MediaOperationException::class);
    expect(fn () => app(UploadProductMediaAction::class)->execute($user, $company, $product, r18File('png', 'mismatch.jpg')))->toThrow(MediaOperationException::class);
    expect(ProductMedia::query()->count())->toBe(0)->and($product->fresh()?->primary_media_id)->toBe($pointer)->and(AuditLog::query()->where('event', AuditEvent::CatalogMediaUploaded->value)->exists())->toBeFalse();
});

test('variant upload remains owner-scoped and does not change Product primary', function () {
    [$user,$company,$product,$variant] = r18Context();
    $productMedia = app(UploadProductMediaAction::class)->execute($user, $company, $product, r18File());
    $variantMedia = app(UploadVariantMediaAction::class)->execute($user, $company, $product, $variant, r18File('webp'));
    expect($variantMedia->product_variant_id)->toBe($variant->id)->and($variant->fresh()?->primary_media_id)->toBe($variantMedia->id)
        ->and($product->fresh()?->primary_media_id)->toBe($productMedia->id);
});

test('Owner Admin and Editor can manage media', function (CompanyRole $role) {
    [$user, $company, $product] = r18Context($role);
    $media = app(UploadProductMediaAction::class)->execute($user, $company, $product, r18File());
    expect($media->exists)->toBeTrue();
})->with([CompanyRole::Owner, CompanyRole::Admin, CompanyRole::Editor]);

test('upload database failure compensates the final file', function () {
    [$user, $company, $product] = r18Context();
    config()->set('catalog.media.max_per_product', 0);
    expect(fn () => app(UploadProductMediaAction::class)->execute($user, $company, $product, r18File()))->toThrow(MediaOperationException::class);
    expect(ProductMedia::query()->count())->toBe(0)->and(Storage::disk('catalog_media')->allFiles())->toBe([])
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogMediaUploaded->value)->exists())->toBeFalse();
});

test('dimension limits and unsupported images are rejected before storage', function () {
    [$user, $company, $product] = r18Context();
    config()->set('catalog.media.max_pixels', 0);
    expect(fn () => app(UploadProductMediaAction::class)->execute($user, $company, $product, r18File()))->toThrow(MediaOperationException::class);
    config()->set('catalog.media.max_pixels', 40_000_000);
    expect(fn () => app(UploadProductMediaAction::class)->execute($user, $company, $product, UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')))->toThrow(MediaOperationException::class);
    expect(ProductMedia::query()->count())->toBe(0)->and(Storage::disk('catalog_media')->allFiles())->toBe([]);
});

test('a Product cannot select another Product or Variant image as primary', function () {
    [$user, $company, $product, $variant] = r18Context();
    $variantMedia = app(UploadVariantMediaAction::class)->execute($user, $company, $product, $variant, r18File());
    expect(fn () => app(SetPrimaryProductMediaAction::class)->execute($user, $company, $product, $variantMedia))->toThrow(MediaOperationException::class);
    $other = app(ProductAggregateCreator::class)->create($user, $company, ['name' => 'Other', 'slug' => 'other-media-product', 'short_description' => null, 'description' => null, 'brand' => null, 'manufacturer' => null], ['name' => 'Default', 'sku' => null, 'sku_normalized' => null, 'gtin' => null, 'mpn' => null, 'sort_order' => 0]);
    $otherMedia = app(UploadProductMediaAction::class)->execute($user, $company, $other, r18File());
    expect(fn () => app(SetPrimaryProductMediaAction::class)->execute($user, $company, $product, $otherMedia))->toThrow(MediaOperationException::class);
});

test('metadata primary reorder and deletion are idempotent and audited', function () {
    [$user,$company,$product] = r18Context();
    $first = app(UploadProductMediaAction::class)->execute($user, $company, $product, r18File('png', 'first.png'));
    $second = app(UploadProductMediaAction::class)->execute($user, $company, $product, r18File('png', 'second.png'));
    app(UpdateProductMediaAction::class)->executeProduct($user, $company, $product, $second, ['alt_text' => '<b>Plain</b>', 'caption' => '<script>x</script>', 'sort_order' => 20]);
    app(SetPrimaryProductMediaAction::class)->execute($user, $company, $product, $second);
    $count = AuditLog::query()->where('event', AuditEvent::CatalogMediaPrimaryChanged->value)->count();
    app(SetPrimaryProductMediaAction::class)->execute($user, $company, $product, $second);
    expect(AuditLog::query()->where('event', AuditEvent::CatalogMediaPrimaryChanged->value)->count())->toBe($count);
    app(ReorderProductMediaAction::class)->execute($user, $company, $product, [$second->uuid, $first->uuid]);
    expect($second->fresh()?->sort_order)->toBe(10)->and($first->fresh()?->sort_order)->toBe(20);
    app(DeleteProductMediaAction::class)->execute($user, $company, $product, $second);
    expect($product->fresh()?->primary_media_id)->toBeNull()->and(ProductMedia::withTrashed()->find($second->id)?->trashed())->toBeTrue();
    Storage::disk('catalog_media')->assertMissing($second->storage_path);
});

test('physical delete failure preserves the committed soft-delete for cleanup retry', function () {
    [$user, $company, $product] = r18Context();
    $media = app(UploadProductMediaAction::class)->execute($user, $company, $product, r18File());
    $failingStorage = Mockery::mock(CatalogMediaStorage::class);
    $failingStorage->shouldReceive('delete')->once()->with($media->storage_path)->andThrow(new RuntimeException('controlled failure'));
    app()->instance(CatalogMediaStorage::class, $failingStorage);

    app(DeleteProductMediaAction::class)->execute($user, $company, $product, $media);

    expect($product->fresh()?->primary_media_id)->toBeNull()
        ->and(ProductMedia::withTrashed()->find($media->id)?->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogMediaDeleted->value)->exists())->toBeTrue();
    Storage::disk('catalog_media')->assertExists($media->storage_path);
});

test('authenticated content delivery is private tenant-safe and handles missing files', function () {
    [$user,$company,$product] = r18Context();
    $media = app(UploadProductMediaAction::class)->execute($user, $company, $product, r18File());
    $this->get(route('catalog.media.content', $media->uuid))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'max-age=3600, private')->assertHeader('ETag', '"'.$media->checksum_sha256.'"');
    $this->withHeader('If-None-Match', '"'.$media->checksum_sha256.'"')->get(route('catalog.media.content', $media->uuid))->assertStatus(304);
    Storage::disk('catalog_media')->delete($media->storage_path);
    $this->withHeader('If-None-Match', '"different"')->get(route('catalog.media.content', $media->uuid))->assertNotFound();
    $foreign = Company::factory()->create();
    CompanyMembership::factory()->create(['user_id' => $user, 'company_id' => $foreign, 'role' => CompanyRole::Owner]);
    app(CurrentCompany::class)->set($foreign);
    $this->get(route('catalog.media.content', $media->uuid))->assertNotFound();
});

test('Viewer sees galleries but mutation controls and uploads are denied', function () {
    [$viewer,$company,$product] = r18Context(CompanyRole::Viewer);
    $this->get(route('catalog.products.media.index', $product->uuid))->assertOk()->assertDontSee('Upload Product image');
    $this->post(route('catalog.products.media.store', $product->uuid), ['image' => r18File()])->assertForbidden();
});

test('orphan cleanup defaults to dry run and requires explicit delete', function () {
    r18Context();
    Storage::disk('catalog_media')->put('safe/orphan.png', r18Bytes());
    $this->artisan('catalog:prune-orphan-media', ['--older-than' => 0, '--limit' => 10])->assertSuccessful();
    Storage::disk('catalog_media')->assertExists('safe/orphan.png');
    $this->artisan('catalog:prune-orphan-media', ['--delete' => true, '--older-than' => 0, '--limit' => 10])->assertSuccessful();
    Storage::disk('catalog_media')->assertMissing('safe/orphan.png');
});

test('cleanup retries physical files retained for soft-deleted media', function () {
    [$user, $company, $product] = r18Context();
    $media = app(UploadProductMediaAction::class)->execute($user, $company, $product, r18File());
    $product->forceFill(['primary_media_id' => null])->save();
    $media->delete();
    Storage::disk('catalog_media')->assertExists($media->storage_path);
    $this->artisan('catalog:prune-orphan-media', ['--delete' => true, '--older-than' => 0, '--limit' => 10])->assertSuccessful();
    Storage::disk('catalog_media')->assertMissing($media->storage_path);
    expect(ProductMedia::withTrashed()->find($media->id)?->trashed())->toBeTrue();
});
