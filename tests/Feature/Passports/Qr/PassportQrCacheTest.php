<?php

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\Qr\PassportQrRenderer;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->company = Company::factory()->create(['status' => CompanyStatus::Active]);
    $this->actor = User::factory()->create(['email_verified_at' => now()]);

    CompanyMembership::factory()->create([
        'company_id' => $this->company->getKey(),
        'user_id' => $this->actor->getKey(),
        'role' => CompanyRole::Owner,
    ]);

    $this->actingAs($this->actor);
    app(CurrentCompany::class)->set($this->company);

    Storage::fake('catalog_media');
    Storage::disk('catalog_media')->put('test/qr-cache.jpg', 'fake content');

    $category = new Category;
    $category->forceFill([
        'uuid' => (string) str()->uuid(),
        'company_id' => $this->company->getKey(),
        'name' => 'QR Cache Category',
        'slug' => 'qr-cache-cat-'.fake()->unique()->slug(1),
        'slug_normalized' => 'qr-cache-cat-'.fake()->unique()->slug(1),
        'depth' => 0,
        'sort_order' => 0,
        'status' => CategoryStatus::Active,
        'created_at' => now(),
        'updated_at' => now(),
    ])->save();

    $this->product = new Product;
    $this->product->forceFill([
        'uuid' => (string) str()->uuid(),
        'company_id' => $this->company->getKey(),
        'name' => 'QR Cache Test Product '.fake()->unique()->word(),
        'slug' => 'qr-cache-test-'.fake()->unique()->slug(1),
        'slug_normalized' => 'qr-cache-test-'.fake()->unique()->slug(1),
        'brand' => 'QR Cache Brand',
        'manufacturer' => 'QR Cache Manufacturer',
        'status' => ProductStatus::Active,
        'primary_category_id' => $category->getKey(),
        'created_by' => $this->actor->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ])->save();

    $variant = new ProductVariant;
    $variant->forceFill([
        'uuid' => (string) str()->uuid(),
        'company_id' => $this->company->getKey(),
        'product_id' => $this->product->getKey(),
        'name' => 'Default Variant',
        'sku' => 'SKU-QRC-001',
        'status' => ProductVariantStatus::Active,
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ])->save();

    $media = new ProductMedia;
    $media->forceFill([
        'uuid' => (string) str()->uuid(),
        'company_id' => $this->company->getKey(),
        'product_id' => $this->product->getKey(),
        'original_filename' => 'qr-cache.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1024,
        'storage_path' => 'test/qr-cache.jpg',
        'checksum_sha256' => str_repeat('a', 64),
        'sort_order' => 0,
        'uploaded_by' => $this->actor->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ])->save();

    $this->product->forceFill([
        'default_variant_id' => $variant->getKey(),
        'primary_media_id' => $media->getKey(),
    ])->save();

    $this->product->categories()->attach($category->getKey(), [
        'company_id' => $this->company->getKey(),
        'created_at' => now(),
    ]);

    $this->product->refresh();

    $passport = app(CreateProductPassportDraftAction::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
    );

    $revision = $passport->currentDraftVersion->draft_revision;

    $passport = app(UpdateProductPassportSectionAction::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport,
        DppSectionKey::Identity->value,
        [
            'public_name' => 'QR Cache Product',
            'public_description' => 'QR cache test description.',
        ],
        $revision,
    );

    $revision = $passport->currentDraftVersion->draft_revision;

    $passport = app(UpdateProductPassportSectionAction::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport,
        DppSectionKey::ManufacturerAndOperator->value,
        [
            'manufacturer_display_name' => 'QR Cache Mfg Inc.',
            'manufacturer_email' => 'qr@cache-mfg.example',
        ],
        $revision,
    );

    $revision = $passport->currentDraftVersion->draft_revision;

    $passport = app(UpdateProductPassportSectionAction::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport,
        DppSectionKey::Safety->value,
        ['warnings' => ['QR Cache warning']],
        $revision,
    );

    $revision = $passport->currentDraftVersion->draft_revision;

    $passport = app(UpdateProductPassportSectionAction::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport,
        DppSectionKey::RecyclingAndDisposal->value,
        ['recycling_instructions' => 'QR Cache recycling.'],
        $revision,
    );

    $revision = $passport->currentDraftVersion->draft_revision;

    app(PublishProductPassport::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport->fresh(['currentDraftVersion']),
        $revision,
        true,
    );

    $this->passport = $passport->fresh(['currentPublishedVersion']);
});

test('svg response has cache control public max age header', function () {
    $response = $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid));
    $response->assertOk();

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('public');
    expect($cacheControl)->toContain('max-age=86400');
});

test('png response has cache control public max age header', function () {
    $response = $this->get(route('catalog.products.passport.qr.png', $this->product->uuid));
    $response->assertOk();

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('public');
    expect($cacheControl)->toContain('max-age=86400');
});

test('svg etag is deterministic for repeated requests', function () {
    $a = $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid));
    $a->assertOk();

    $b = $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid));
    $b->assertOk();

    expect($a->headers->get('ETag'))->toBe($b->headers->get('ETag'));
});

test('png etag is deterministic for repeated requests', function () {
    $a = $this->get(route('catalog.products.passport.qr.png', $this->product->uuid));
    $a->assertOk();

    $b = $this->get(route('catalog.products.passport.qr.png', $this->product->uuid));
    $b->assertOk();

    expect($a->headers->get('ETag'))->toBe($b->headers->get('ETag'));
});

test('svg etag differs from png etag for same passport', function () {
    $svg = $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid));
    $svg->assertOk();

    $png = $this->get(route('catalog.products.passport.qr.png', $this->product->uuid));
    $png->assertOk();

    expect($svg->headers->get('ETag'))->not()->toBe($png->headers->get('ETag'));
});

test('svg if none match returns 304', function () {
    $renderer = $this->app->make(PassportQrRenderer::class);
    $etag = $renderer->eTag($this->passport->public_id, 'svg');

    $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid), [
        'If-None-Match' => $etag,
    ])->assertStatus(304);
});

test('png if none match returns 304', function () {
    $renderer = $this->app->make(PassportQrRenderer::class);
    $etag = $renderer->eTag($this->passport->public_id, 'png');

    $this->get(route('catalog.products.passport.qr.png', $this->product->uuid), [
        'If-None-Match' => $etag,
    ])->assertStatus(304);
});

test('svg different if none match returns 200', function () {
    $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid), [
        'If-None-Match' => '"different-etag-value"',
    ])->assertOk();
});

test('png different if none match returns 200', function () {
    $this->get(route('catalog.products.passport.qr.png', $this->product->uuid), [
        'If-None-Match' => '"different-etag-value"',
    ])->assertOk();
});

test('etag does not change after republishing', function () {
    $passport = ProductPassport::query()
        ->forCompany($this->company)
        ->where('product_id', $this->product->getKey())
        ->first()
        ->fresh(['currentPublishedVersion', 'currentDraftVersion']);

    $svgBefore = $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid));
    $svgBefore->assertOk();
    $etagBefore = $svgBefore->headers->get('ETag');

    $revision = $passport->currentDraftVersion->draft_revision;

    app(UpdateProductPassportSectionAction::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport,
        DppSectionKey::Identity->value,
        [
            'public_name' => 'QR Cache Product Updated',
            'public_description' => 'QR cache test description updated.',
        ],
        $revision,
    );

    $passport = ProductPassport::query()
        ->forCompany($this->company)
        ->where('product_id', $this->product->getKey())
        ->first()
        ->fresh(['currentDraftVersion']);

    $newRevision = $passport->currentDraftVersion->draft_revision;

    app(PublishProductPassport::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport,
        $newRevision,
        true,
    );

    $svgAfter = $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid));
    $svgAfter->assertOk();
    $etagAfter = $svgAfter->headers->get('ETag');

    expect($etagBefore)->toBe($etagAfter);
});

test('no cache control no store on svg', function () {
    $response = $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid));
    $response->assertOk();

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->not()->toContain('no-store');
});

test('no cache control no store on png', function () {
    $response = $this->get(route('catalog.products.passport.qr.png', $this->product->uuid));
    $response->assertOk();

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->not()->toContain('no-store');
});
