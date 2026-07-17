<?php

namespace Tests\Feature\Passports\Public;

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
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicPassportPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    private ProductPassport $passport;

    private string $publicId;

    protected function setUp(): void
    {
        parent::setUp();

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
        Storage::disk('catalog_media')->put('test/public-passport.jpg', 'fake content');

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Public Passport Category',
            'slug' => 'public-passport-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'public-passport-cat-'.fake()->unique()->slug(1),
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
            'name' => 'Public Test Product '.fake()->unique()->word(),
            'slug' => 'public-test-'.fake()->unique()->slug(1),
            'slug_normalized' => 'public-test-'.fake()->unique()->slug(1),
            'brand' => 'Public Brand',
            'manufacturer' => 'Public Manufacturer',
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
            'sku' => 'SKU-PUB-001',
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
            'original_filename' => 'public-passport.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/public-passport.jpg',
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

        $passport = $this->fillSection($passport, DppSectionKey::Identity, [
            'public_name' => 'Test Product',
            'public_description' => 'A test description.',
        ], $revision);

        $revision = $passport->currentDraftVersion->draft_revision;

        $passport = $this->fillSection($passport, DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Public Manufacturer Inc.',
            'manufacturer_email' => 'contact@public-mfg.example',
        ], $revision);

        $revision = $passport->currentDraftVersion->draft_revision;

        $passport = $this->fillSection($passport, DppSectionKey::Safety, [
            'warnings' => ['Warning A'],
        ], $revision);

        $revision = $passport->currentDraftVersion->draft_revision;

        $passport = $this->fillSection($passport, DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Recycle responsibly.',
        ], $revision);

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

        $this->publicId = $this->passport->public_id;
    }

    private function fillSection(ProductPassport $passport, DppSectionKey $section, array $payload, int $revision): ProductPassport
    {
        return app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $section->value,
            $payload,
            $revision,
        );
    }

    public function test_published_passport_returns_200_without_authentication(): void
    {
        auth()->guard('web')->logout();

        $this->get(route('public.passports.show', ['publicId' => $this->publicId]))
            ->assertOk();
    }

    public function test_product_name_is_displayed(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get(route('public.passports.show', ['publicId' => $this->publicId]));
        $response->assertOk();

        $response->assertSee($this->product->name, false);
    }

    public function test_public_description_is_displayed(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get(route('public.passports.show', ['publicId' => $this->publicId]));
        $response->assertOk();

        $response->assertSee('A test description.', false);
    }

    public function test_safety_section_is_visible(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get(route('public.passports.show', ['publicId' => $this->publicId]));
        $response->assertOk();

        $response->assertSee('Warning A', false);
        $response->assertSee('Safety Information', false);
    }

    public function test_recycling_section_is_visible(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get(route('public.passports.show', ['publicId' => $this->publicId]));
        $response->assertOk();

        $response->assertSee('Recycle responsibly.', false);
        $response->assertSee('Recycling &amp; Disposal', false);
    }

    public function test_publication_version_is_shown(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get(route('public.passports.show', ['publicId' => $this->publicId]));
        $response->assertOk();

        $response->assertSee('Passport Version', false);
    }

    public function test_legal_disclaimer_is_present(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get(route('public.passports.show', ['publicId' => $this->publicId]));
        $response->assertOk();

        $response->assertSee('does not independently certify', false);
    }

    public function test_admin_navigation_does_not_appear(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get(route('public.passports.show', ['publicId' => $this->publicId]));
        $response->assertOk();

        $response->assertDontSee('passport.edit', false);
        $response->assertDontSee('catalog.products.passport', false);
    }

    public function test_raw_uuids_are_not_visible_in_html(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get(route('public.passports.show', ['publicId' => $this->publicId]));
        $response->assertOk();

        $response->assertDontSee($this->product->uuid, false);
        $response->assertDontSee($this->passport->uuid, false);
        $response->assertDontSee($this->company->uuid, false);
    }

    public function test_raw_rule_codes_are_not_visible(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get(route('public.passports.show', ['publicId' => $this->publicId]));
        $response->assertOk();

        $response->assertDontSee('dpp.', false);
        $response->assertDontSee('passport.', false);
        $response->assertDontSee('catalog.', false);
    }

    public function test_private_paths_are_not_visible(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get(route('public.passports.show', ['publicId' => $this->publicId]));
        $response->assertOk();

        $response->assertDontSee('catalog/products', false);
        $response->assertDontSee('/dashboard', false);
        $response->assertDontSee('/admin', false);
        $response->assertDontSee('/company', false);
    }

    public function test_storage_key_is_not_visible(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get(route('public.passports.show', ['publicId' => $this->publicId]));
        $response->assertOk();

        $response->assertDontSee('storage_key', false);
    }

    public function test_primary_image_is_referenced(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get(route('public.passports.show', ['publicId' => $this->publicId]));
        $response->assertOk();

        $response->assertSee('<img ', false);
    }

    public function test_page_title_contains_product_name(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get(route('public.passports.show', ['publicId' => $this->publicId]));
        $response->assertOk();

        $response->assertSee('<title>'.$this->product->name.' — Product Passport</title>', false);
    }

    public function test_json_ld_script_is_present(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get(route('public.passports.show', ['publicId' => $this->publicId]));
        $response->assertOk();

        $response->assertSee('application/ld+json', false);
        $response->assertSee('@context', false);
        $response->assertSee('https://schema.org', false);
        $response->assertSee($this->product->name, false);
    }

    public function test_published_date_is_shown(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get(route('public.passports.show', ['publicId' => $this->publicId]));
        $response->assertOk();

        $response->assertSee('Published', false);
    }

    public function test_page_is_mobile_responsive(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get(route('public.passports.show', ['publicId' => $this->publicId]));
        $response->assertOk();

        $response->assertSee('viewport', false);
        $response->assertSee('width=device-width', false);
        $response->assertSee('initial-scale=1', false);
    }
}
