<?php

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyPermission;
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
use Illuminate\Support\Facades\Gate;
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
    Storage::disk('catalog_media')->put('test/qr-page.jpg', 'fake content');

    $category = new Category;
    $category->forceFill([
        'uuid' => (string) str()->uuid(),
        'company_id' => $this->company->getKey(),
        'name' => 'QR Page Category',
        'slug' => 'qr-page-cat-'.fake()->unique()->slug(1),
        'slug_normalized' => 'qr-page-cat-'.fake()->unique()->slug(1),
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
        'name' => 'QR Page Test Product '.fake()->unique()->word(),
        'slug' => 'qr-page-test-'.fake()->unique()->slug(1),
        'slug_normalized' => 'qr-page-test-'.fake()->unique()->slug(1),
        'brand' => 'QR Page Brand',
        'manufacturer' => 'QR Page Manufacturer',
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
        'sku' => 'SKU-QRP-001',
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
        'original_filename' => 'qr-page.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1024,
        'storage_path' => 'test/qr-page.jpg',
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
            'public_name' => 'QR Page Product',
            'public_description' => 'QR page test description.',
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
            'manufacturer_display_name' => 'QR Page Mfg Inc.',
            'manufacturer_email' => 'qr@page-mfg.example',
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
        ['warnings' => ['QR Page warning']],
        $revision,
    );

    $revision = $passport->currentDraftVersion->draft_revision;

    $passport = app(UpdateProductPassportSectionAction::class)->handle(
        $this->actor,
        $this->company,
        $this->product,
        $passport,
        DppSectionKey::RecyclingAndDisposal->value,
        ['recycling_instructions' => 'QR Page recycling.'],
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

test('guest is redirected to login', function () {
    auth()->guard('web')->logout();

    $this->get(route('catalog.products.passport.qr.show', $this->product->uuid))
        ->assertRedirect(route('login'));
});

test('owner sees qr page', function () {
    $this->get(route('catalog.products.passport.qr.show', $this->product->uuid))
        ->assertOk();
});

test('admin sees qr page', function () {
    $admin = User::factory()->create(['email_verified_at' => now()]);
    CompanyMembership::factory()->create([
        'company_id' => $this->company->getKey(),
        'user_id' => $admin->getKey(),
        'role' => CompanyRole::Admin,
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($this->company);

    $this->get(route('catalog.products.passport.qr.show', $this->product->uuid))
        ->assertOk();
});

test('editor sees qr page', function () {
    $editor = User::factory()->create(['email_verified_at' => now()]);
    CompanyMembership::factory()->create([
        'company_id' => $this->company->getKey(),
        'user_id' => $editor->getKey(),
        'role' => CompanyRole::Editor,
    ]);

    $this->actingAs($editor);
    app(CurrentCompany::class)->set($this->company);

    $this->get(route('catalog.products.passport.qr.show', $this->product->uuid))
        ->assertOk();
});

test('viewer sees qr page', function () {
    $viewer = User::factory()->create(['email_verified_at' => now()]);
    CompanyMembership::factory()->create([
        'company_id' => $this->company->getKey(),
        'user_id' => $viewer->getKey(),
        'role' => CompanyRole::Viewer,
    ]);

    $this->actingAs($viewer);
    app(CurrentCompany::class)->set($this->company);

    $this->get(route('catalog.products.passport.qr.show', $this->product->uuid))
        ->assertOk();
});

test('non passport viewer receives 404', function () {
    $noView = User::factory()->create(['email_verified_at' => now()]);
    CompanyMembership::factory()->create([
        'company_id' => $this->company->getKey(),
        'user_id' => $noView->getKey(),
        'role' => CompanyRole::Viewer,
    ]);

    $this->actingAs($noView);
    app(CurrentCompany::class)->set($this->company);

    Gate::define(CompanyPermission::PassportsView->value, fn () => false);

    $this->get(route('catalog.products.passport.qr.show', $this->product->uuid))
        ->assertNotFound();
});

test('wrong tenant receives 404', function () {
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
        'name' => 'Foreign Category',
        'slug' => 'foreign-cat-'.fake()->unique()->slug(1),
        'slug_normalized' => 'foreign-cat-'.fake()->unique()->slug(1),
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
        'name' => 'Foreign Product',
        'slug' => 'foreign-prod-'.fake()->unique()->slug(1),
        'slug_normalized' => 'foreign-prod-'.fake()->unique()->slug(1),
        'status' => ProductStatus::Active,
        'primary_category_id' => $foreignCategory->getKey(),
        'created_by' => $this->actor->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ])->save();

    $this->get(route('catalog.products.passport.qr.show', $foreignProduct->uuid))
        ->assertNotFound();
});

test('public link is displayed on page', function () {
    $response = $this->get(route('catalog.products.passport.qr.show', $this->product->uuid));
    $response->assertOk();

    $publicUrl = config('passports.public_base_url').'/p/'.$this->passport->public_id;

    $response->assertSee($publicUrl, false);
    $response->assertSee('Public link', false);
});

test('download buttons are displayed', function () {
    $response = $this->get(route('catalog.products.passport.qr.show', $this->product->uuid));
    $response->assertOk();

    $response->assertSee('Download SVG', false);
    $response->assertSee('Download PNG', false);
});

test('target status shows published state', function () {
    $response = $this->get(route('catalog.products.passport.qr.show', $this->product->uuid));
    $response->assertOk();

    $label = 'Published · Version 1';

    $response->assertSee($label, false);
});

test('target status shows draft state', function () {
    $product = new Product;
    $product->forceFill([
        'uuid' => (string) str()->uuid(),
        'company_id' => $this->company->getKey(),
        'name' => 'Draft Only Product '.fake()->unique()->word(),
        'slug' => 'draft-only-'.fake()->unique()->slug(1),
        'slug_normalized' => 'draft-only-'.fake()->unique()->slug(1),
        'brand' => 'Draft Brand',
        'manufacturer' => 'Draft Manufacturer',
        'status' => ProductStatus::Active,
        'primary_category_id' => $this->product->primary_category_id,
        'created_by' => $this->actor->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ])->save();

    app(CreateProductPassportDraftAction::class)->handle(
        $this->actor,
        $this->company,
        $product,
    );

    $response = $this->get(route('catalog.products.passport.qr.show', $product->uuid));
    $response->assertOk();

    $response->assertSee('Draft — not published yet', false);
});

test('no storage paths displayed', function () {
    $response = $this->get(route('catalog.products.passport.qr.show', $this->product->uuid));
    $response->assertOk();

    $body = $response->getContent();

    expect($body)->not()->toContain('storage_path');
    expect($body)->not()->toContain('passport_assets');
});
