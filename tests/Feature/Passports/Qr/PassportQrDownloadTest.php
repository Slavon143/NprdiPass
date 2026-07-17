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
    Storage::disk('catalog_media')->put('test/qr-download.jpg', 'fake content');

    $category = new Category;
    $category->forceFill([
        'uuid' => (string) str()->uuid(),
        'company_id' => $this->company->getKey(),
        'name' => 'QR Download Category',
        'slug' => 'qr-download-cat-'.fake()->unique()->slug(1),
        'slug_normalized' => 'qr-download-cat-'.fake()->unique()->slug(1),
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
        'name' => 'QR Download Test Product '.fake()->unique()->word(),
        'slug' => 'qr-download-test-'.fake()->unique()->slug(1),
        'slug_normalized' => 'qr-download-test-'.fake()->unique()->slug(1),
        'brand' => 'QR Download Brand',
        'manufacturer' => 'QR Download Manufacturer',
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
        'sku' => 'SKU-QRD-001',
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
        'original_filename' => 'qr-download.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1024,
        'storage_path' => 'test/qr-download.jpg',
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
            'public_name' => 'QR Download Product',
            'public_description' => 'QR download test description.',
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
            'manufacturer_display_name' => 'QR Download Mfg Inc.',
            'manufacturer_email' => 'qr@download-mfg.example',
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
        ['warnings' => ['QR Download warning']],
        $revision,
    );

    $revision = $passport->currentDraftVersion->draft_revision;

    $passport = app(UpdateProductPassportSectionAction::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport,
        DppSectionKey::RecyclingAndDisposal->value,
        ['recycling_instructions' => 'QR Download recycling.'],
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
    $this->safeProductName = preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $this->product->name);
    $this->safeProductName = preg_replace('/\s+/', '-', trim($this->safeProductName));
    $this->safeProductName = $this->safeProductName !== '' ? $this->safeProductName : 'passport';
});

test('svg download returns 200 with correct content type', function () {
    $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml');
});

test('png download returns 200 with correct content type', function () {
    $this->get(route('catalog.products.passport.qr.png', $this->product->uuid))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

test('svg download has content disposition attachment header', function () {
    $expectedFilename = "attachment; filename=\"nordipass-{$this->safeProductName}-qr.svg\"";

    $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid))
        ->assertOk()
        ->assertHeader('Content-Disposition', $expectedFilename);
});

test('png download has content disposition attachment header', function () {
    $expectedFilename = "attachment; filename=\"nordipass-{$this->safeProductName}-qr.png\"";

    $this->get(route('catalog.products.passport.qr.png', $this->product->uuid))
        ->assertOk()
        ->assertHeader('Content-Disposition', $expectedFilename);
});

test('etag header present on svg', function () {
    $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid))
        ->assertOk()
        ->assertHeader('ETag');
});

test('etag header present on png', function () {
    $this->get(route('catalog.products.passport.qr.png', $this->product->uuid))
        ->assertOk()
        ->assertHeader('ETag');
});

test('if none match returns 304 on svg', function () {
    $renderer = $this->app->make(PassportQrRenderer::class);
    $etag = $renderer->eTag($this->passport->public_id, 'svg');

    $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid), [
        'If-None-Match' => $etag,
    ])->assertStatus(304);
});

test('if none match returns 304 on png', function () {
    $renderer = $this->app->make(PassportQrRenderer::class);
    $etag = $renderer->eTag($this->passport->public_id, 'png');

    $this->get(route('catalog.products.passport.qr.png', $this->product->uuid), [
        'If-None-Match' => $etag,
    ])->assertStatus(304);
});

test('x content type options nosniff present on svg', function () {
    $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('x content type options nosniff present on png', function () {
    $this->get(route('catalog.products.passport.qr.png', $this->product->uuid))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('filename is sanitized for product name with special characters', function () {
    $this->product->forceFill([
        'name' => 'Test<>:"/\\|?*Product!@#$%^&()+=[]{}',
    ])->save();

    $response = $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid));
    $response->assertOk();

    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)
        ->toContain('attachment; filename="nordipass-TestProduct-qr.svg"');
});

test('wrong tenant product returns 404 for svg download', function () {
    $foreignCompany = Company::factory()->create(['status' => CompanyStatus::Active]);
    CompanyMembership::factory()->create([
        'company_id' => $foreignCompany->getKey(),
        'user_id' => $this->actor->getKey(),
        'role' => CompanyRole::Owner,
    ]);

    $foreignCategory = new Category;
    $foreignCategory->forceFill([
        'uuid' => (string) str()->uuid(),
        'company_id' => $foreignCompany->getKey(),
        'name' => 'Foreign Download Category',
        'slug' => 'foreign-dl-cat-'.fake()->unique()->slug(1),
        'slug_normalized' => 'foreign-dl-cat-'.fake()->unique()->slug(1),
        'depth' => 0,
        'sort_order' => 0,
        'status' => CategoryStatus::Active,
        'created_at' => now(),
        'updated_at' => now(),
    ])->save();

    $foreignProduct = new Product;
    $foreignProduct->forceFill([
        'uuid' => (string) str()->uuid(),
        'company_id' => $foreignCompany->getKey(),
        'name' => 'Foreign Download Product',
        'slug' => 'foreign-dl-'.fake()->unique()->slug(1),
        'slug_normalized' => 'foreign-dl-'.fake()->unique()->slug(1),
        'status' => ProductStatus::Active,
        'primary_category_id' => $foreignCategory->getKey(),
        'created_by' => $this->actor->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ])->save();

    $this->get(route('catalog.products.passport.qr.svg', $foreignProduct->uuid))
        ->assertNotFound();
});

test('wrong tenant product returns 404 for png download', function () {
    $foreignCompany = Company::factory()->create(['status' => CompanyStatus::Active]);
    CompanyMembership::factory()->create([
        'company_id' => $foreignCompany->getKey(),
        'user_id' => $this->actor->getKey(),
        'role' => CompanyRole::Owner,
    ]);

    $foreignCategory = new Category;
    $foreignCategory->forceFill([
        'uuid' => (string) str()->uuid(),
        'company_id' => $foreignCompany->getKey(),
        'name' => 'Foreign PNG Category',
        'slug' => 'foreign-png-cat-'.fake()->unique()->slug(1),
        'slug_normalized' => 'foreign-png-cat-'.fake()->unique()->slug(1),
        'depth' => 0,
        'sort_order' => 0,
        'status' => CategoryStatus::Active,
        'created_at' => now(),
        'updated_at' => now(),
    ])->save();

    $foreignProduct = new Product;
    $foreignProduct->forceFill([
        'uuid' => (string) str()->uuid(),
        'company_id' => $foreignCompany->getKey(),
        'name' => 'Foreign PNG Product',
        'slug' => 'foreign-png-'.fake()->unique()->slug(1),
        'slug_normalized' => 'foreign-png-'.fake()->unique()->slug(1),
        'status' => ProductStatus::Active,
        'primary_category_id' => $foreignCategory->getKey(),
        'created_by' => $this->actor->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ])->save();

    $this->get(route('catalog.products.passport.qr.png', $foreignProduct->uuid))
        ->assertNotFound();
});
