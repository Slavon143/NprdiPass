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
    Storage::disk('catalog_media')->put('test/qr-security.jpg', 'fake content');

    $category = new Category;
    $category->forceFill([
        'uuid' => (string) str()->uuid(),
        'company_id' => $this->company->getKey(),
        'name' => 'QR Security Category',
        'slug' => 'qr-security-cat-'.fake()->unique()->slug(1),
        'slug_normalized' => 'qr-security-cat-'.fake()->unique()->slug(1),
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
        'name' => 'QR Security Test Product '.fake()->unique()->word(),
        'slug' => 'qr-security-test-'.fake()->unique()->slug(1),
        'slug_normalized' => 'qr-security-test-'.fake()->unique()->slug(1),
        'brand' => 'QR Security Brand',
        'manufacturer' => 'QR Security Manufacturer',
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
        'sku' => 'SKU-QRS-001',
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
        'original_filename' => 'qr-security.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1024,
        'storage_path' => 'test/qr-security.jpg',
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
            'public_name' => 'QR Security Product',
            'public_description' => 'QR security test description.',
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
            'manufacturer_display_name' => 'QR Security Mfg Inc.',
            'manufacturer_email' => 'qr@security-mfg.example',
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
        ['warnings' => ['QR Security warning']],
        $revision,
    );

    $revision = $passport->currentDraftVersion->draft_revision;

    $passport = app(UpdateProductPassportSectionAction::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport,
        DppSectionKey::RecyclingAndDisposal->value,
        ['recycling_instructions' => 'QR Security recycling.'],
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

test('host header injection ignored payload uses config url', function () {
    $response = $this->get(route('catalog.products.passport.qr.show', $this->product->uuid), [
        'Host' => 'evil.example.com',
    ]);
    $response->assertOk();

    $expectedBaseUrl = rtrim(config('passports.public_base_url'), '/');
    $response->assertSee($expectedBaseUrl, false);
    $response->assertDontSee('evil.example.com', false);
});

test('crlf in product name does not affect filename', function () {
    $this->product->forceFill([
        'name' => "Clean\r\nX-Injected: true\r\nProduct",
    ])->save();

    $response = $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid));
    $response->assertOk();

    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->not()->toContain("\r");
    expect($disposition)->not()->toContain("\n");
    expect($disposition)->toContain('attachment; filename=');
});

test('script in product name escaped in filename', function () {
    $this->product->forceFill([
        'name' => '<script>alert(1)</script>',
    ])->save();

    $response = $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid));
    $response->assertOk();

    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->not()->toContain('<script');
    expect($disposition)->not()->toContain('</script>');
    expect($disposition)->toContain('attachment; filename=');
});

test('foreign tenant product returns 404 on svg', function () {
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
        'name' => 'Foreign Security Category',
        'slug' => 'foreign-sec-cat-'.fake()->unique()->slug(1),
        'slug_normalized' => 'foreign-sec-cat-'.fake()->unique()->slug(1),
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
        'name' => 'Foreign Security Product',
        'slug' => 'foreign-sec-'.fake()->unique()->slug(1),
        'slug_normalized' => 'foreign-sec-'.fake()->unique()->slug(1),
        'status' => ProductStatus::Active,
        'primary_category_id' => $foreignCategory->getKey(),
        'created_by' => $this->actor->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ])->save();

    $this->get(route('catalog.products.passport.qr.svg', $foreignProduct->uuid))
        ->assertNotFound();
});

test('svg response contains no script', function () {
    $response = $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid));
    $response->assertOk();

    $svg = $response->getContent();

    expect($svg)->not()->toContain('<script');
    expect($svg)->not()->toContain('javascript:');
});

test('svg response contains no foreign object', function () {
    $response = $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid));
    $response->assertOk();

    $svg = $response->getContent();

    expect($svg)->not()->toContain('foreignObject');
});

test('svg response contains no external image references', function () {
    $response = $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid));
    $response->assertOk();

    $svg = $response->getContent();

    expect($svg)->not()->toContain('xlink:href="http');
    expect($svg)->not()->toContain('xlink:href="file');
});

test('no internal uuids except public id in qr page', function () {
    $response = $this->get(route('catalog.products.passport.qr.show', $this->product->uuid));
    $response->assertOk();

    $body = $response->getContent();

    expect($body)->not()->toContain((string) $this->passport->uuid);
    expect($body)->not()->toContain((string) $this->company->uuid);
    expect($body)->toContain($this->passport->public_id);
});

test('no database ids in qr page', function () {
    $response = $this->get(route('catalog.products.passport.qr.show', $this->product->uuid));
    $response->assertOk();

    $body = $response->getContent();

    $productId = (string) $this->product->getKey();

    expect($body)->not()->toContain('>'.$productId.'<');
    expect($body)->not()->toContain('&quot;id&quot;:'.$productId);
});

test('no filesystem paths in qr page', function () {
    $response = $this->get(route('catalog.products.passport.qr.show', $this->product->uuid));
    $response->assertOk();

    $body = $response->getContent();

    expect($body)->not()->toContain('test/qr-security.jpg');
    expect($body)->not()->toContain('storage_path');
    expect($body)->not()->toContain('passport_assets');
    expect($body)->not()->toContain('catalog_media');
});
