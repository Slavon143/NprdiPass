<?php

use App\Actions\Passports\ArchiveProductPassport;
use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\RestoreProductPassport;
use App\Actions\Passports\UnpublishProductPassport;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Data\Passports\PublicationResult;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportStatus;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
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
    Storage::disk('catalog_media')->put('test/qr-lifecycle.jpg', 'fake content');

    $category = new Category;
    $category->forceFill([
        'uuid' => (string) str()->uuid(),
        'company_id' => $this->company->getKey(),
        'name' => 'QR Lifecycle Category',
        'slug' => 'qr-lifecycle-cat-'.fake()->unique()->slug(1),
        'slug_normalized' => 'qr-lifecycle-cat-'.fake()->unique()->slug(1),
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
        'name' => 'QR Lifecycle Test Product '.fake()->unique()->word(),
        'slug' => 'qr-lifecycle-test-'.fake()->unique()->slug(1),
        'slug_normalized' => 'qr-lifecycle-test-'.fake()->unique()->slug(1),
        'brand' => 'QR Lifecycle Brand',
        'manufacturer' => 'QR Lifecycle Manufacturer',
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
        'sku' => 'SKU-QRL-001',
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
        'original_filename' => 'qr-lifecycle.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1024,
        'storage_path' => 'test/qr-lifecycle.jpg',
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

    $this->passport = app(CreateProductPassportDraftAction::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
    );

    $this->revision = $this->passport->currentDraftVersion->draft_revision;
});

function freshPassport(): ProductPassport
{
    return ProductPassport::query()
        ->forCompany(test()->company)
        ->where('product_id', test()->product->getKey())
        ->first()
        ->fresh(['currentDraftVersion', 'currentPublishedVersion']);
}

function publish(int $revision): PublicationResult
{
    return app(PublishProductPassport::class)->handle(
        test()->actor,
        test()->company,
        test()->product,
        freshPassport(),
        $revision,
        true,
    );
}

function fillRequiredSections(): void
{
    $passport = freshPassport();
    $revision = $passport->currentDraftVersion->draft_revision;

    $passport = app(UpdateProductPassportSectionAction::class)->handle(
        test()->actor,
        test()->company,
        test()->product,
        $passport,
        DppSectionKey::Identity->value,
        [
            'public_name' => 'QR Lifecycle Product',
            'public_description' => 'QR lifecycle test description.',
        ],
        $revision,
    );

    $revision = $passport->currentDraftVersion->draft_revision;

    $passport = app(UpdateProductPassportSectionAction::class)->handle(
        test()->actor,
        test()->company,
        test()->product,
        $passport,
        DppSectionKey::ManufacturerAndOperator->value,
        [
            'manufacturer_display_name' => 'QR Lifecycle Mfg Inc.',
            'manufacturer_email' => 'qr@lifecycle-mfg.example',
        ],
        $revision,
    );

    $revision = $passport->currentDraftVersion->draft_revision;

    $passport = app(UpdateProductPassportSectionAction::class)->handle(
        test()->actor,
        test()->company,
        test()->product,
        $passport,
        DppSectionKey::Safety->value,
        ['warnings' => ['QR Lifecycle warning']],
        $revision,
    );

    $revision = $passport->currentDraftVersion->draft_revision;

    $passport = app(UpdateProductPassportSectionAction::class)->handle(
        test()->actor,
        test()->company,
        test()->product,
        $passport,
        DppSectionKey::RecyclingAndDisposal->value,
        ['recycling_instructions' => 'QR Lifecycle recycling.'],
        $revision,
    );

    test()->revision = $passport->currentDraftVersion->draft_revision;
}

test('draft passport qr page available target 404', function () {
    fillRequiredSections();

    $response = $this->get(route('catalog.products.passport.qr.show', $this->product->uuid));
    $response->assertOk();

    $response->assertSee('Draft — not published yet', false);

    $passport = freshPassport();
    $this->get("/p/{$passport->public_id}")->assertNotFound();
});

test('published v1 qr works target 200', function () {
    fillRequiredSections();
    publish($this->revision);

    $response = $this->get(route('catalog.products.passport.qr.show', $this->product->uuid));
    $response->assertOk();

    $response->assertSee('Published · Version 1', false);

    $passport = freshPassport();
    $this->get("/p/{$passport->public_id}")->assertOk();
});

test('published v2 same qr target shows v2', function () {
    fillRequiredSections();
    publish($this->revision);

    $passport = freshPassport();
    $draft = $passport->currentDraftVersion;

    app(UpdateProductPassportSectionAction::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport,
        DppSectionKey::Identity->value,
        [
            'public_name' => 'QR Lifecycle Product V2',
            'public_description' => 'QR lifecycle test description v2.',
        ],
        $draft->draft_revision,
    );

    $passport = freshPassport();
    $this->revision = $passport->currentDraftVersion->draft_revision;
    publish($this->revision);

    $response = $this->get(route('catalog.products.passport.qr.show', $this->product->uuid));
    $response->assertOk();

    $response->assertSee('Published · Version 2', false);

    $passport = freshPassport();
    $this->get("/p/{$passport->public_id}")->assertOk();
});

test('unpublished same qr target 404', function () {
    fillRequiredSections();
    publish($this->revision);

    $passport = freshPassport();
    $publicId = $passport->public_id;

    app(UnpublishProductPassport::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport,
    );

    $response = $this->get(route('catalog.products.passport.qr.show', $this->product->uuid));
    $response->assertOk();

    $response->assertSee('Not published yet', false);

    $passport = freshPassport();
    expect($passport->public_id)->toBe($publicId);
    $this->get("/p/{$passport->public_id}")->assertNotFound();
});

test('archived same qr target 404', function () {
    fillRequiredSections();
    publish($this->revision);

    $passport = freshPassport();
    $publicId = $passport->public_id;

    app(UnpublishProductPassport::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport,
    );

    app(ArchiveProductPassport::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        freshPassport(),
    );

    $response = $this->get(route('catalog.products.passport.qr.show', $this->product->uuid));
    $response->assertOk();

    $response->assertSee('Not published yet', false);

    $passport = freshPassport();
    expect($passport->public_id)->toBe($publicId);
    $this->get("/p/{$passport->public_id}")->assertNotFound();
});

test('restored draft same qr target 404', function () {
    fillRequiredSections();
    publish($this->revision);

    $passport = freshPassport();
    $publicId = $passport->public_id;

    app(UnpublishProductPassport::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport,
    );

    app(ArchiveProductPassport::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        freshPassport(),
    );

    app(RestoreProductPassport::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        freshPassport(),
    );

    $fresh = freshPassport();
    expect($fresh->status)->toBe(ProductPassportStatus::Draft);
    expect($fresh->public_id)->toBe($publicId);

    $response = $this->get(route('catalog.products.passport.qr.show', $this->product->uuid));
    $response->assertOk();

    $response->assertSee('Draft — not published yet', false);

    $this->get("/p/{$fresh->public_id}")->assertNotFound();
});

test('republished same qr target 200', function () {
    fillRequiredSections();
    publish($this->revision);

    $passport = freshPassport();
    $publicId = $passport->public_id;

    app(UnpublishProductPassport::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport,
    );

    $passport = freshPassport();
    $this->revision = $passport->currentDraftVersion->draft_revision;
    publish($this->revision);

    $fresh = freshPassport();
    expect($fresh->status)->toBe(ProductPassportStatus::Published);
    expect($fresh->public_id)->toBe($publicId);

    $response = $this->get(route('catalog.products.passport.qr.show', $this->product->uuid));
    $response->assertOk();

    $response->assertSee('Published · Version 2', false);

    $this->get("/p/{$fresh->public_id}")->assertOk();
});

test('svg checksums are stable across lifecycle', function () {
    fillRequiredSections();

    $collectSvgChecksum = function (): string {
        $response = $this->get(route('catalog.products.passport.qr.svg', $this->product->uuid));

        return hash('sha256', $response->getContent());
    };

    $checksums = [];

    $checksums[] = $collectSvgChecksum();

    publish($this->revision);
    $checksums[] = $collectSvgChecksum();

    $passport = freshPassport();
    $draft = $passport->currentDraftVersion;

    app(UpdateProductPassportSectionAction::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport,
        DppSectionKey::Identity->value,
        [
            'public_name' => 'QR Lifecycle Product V2',
            'public_description' => 'QR lifecycle test description v2.',
        ],
        $draft->draft_revision,
    );

    $passport = freshPassport();
    $this->revision = $passport->currentDraftVersion->draft_revision;
    publish($this->revision);
    $checksums[] = $collectSvgChecksum();

    $passport = freshPassport();
    app(UnpublishProductPassport::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport,
    );
    $checksums[] = $collectSvgChecksum();

    app(ArchiveProductPassport::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        freshPassport(),
    );
    $checksums[] = $collectSvgChecksum();

    $unique = array_unique($checksums);

    expect($unique)->toHaveCount(1);
});
