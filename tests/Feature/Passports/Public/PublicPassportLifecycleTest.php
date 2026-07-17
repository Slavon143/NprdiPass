<?php

namespace Tests\Feature\Passports\Public;

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
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\DppPayloadNormalizer;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicPassportLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    private ProductPassport $passport;

    private int $revision = 1;

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
        Storage::disk('catalog_media')->put('test/public-lifecycle.jpg', 'fake content');

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Public Lifecycle Category',
            'slug' => 'public-lifecycle-category-'.fake()->unique()->slug(1),
            'slug_normalized' => 'public-lifecycle-category-'.fake()->unique()->slug(1),
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
            'name' => 'Public Lifecycle Test Product '.fake()->unique()->word(),
            'slug' => 'public-lifecycle-test-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'public-lifecycle-test-product-'.fake()->unique()->slug(1),
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
            'sku' => 'SKU-PLC-001',
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
            'original_filename' => 'public-lifecycle.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/public-lifecycle.jpg',
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
    }

    private function createDraft(): ProductPassport
    {
        $this->passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $this->passport->currentDraftVersion->draft_revision;

        return $this->passport;
    }

    private function fillSection(DppSectionKey $section, array $payload): void
    {
        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        $result = app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $section->value,
            $payload,
            $this->revision,
        );

        $this->revision = $result->currentDraftVersion->draft_revision;
    }

    private function fillMinimalSections(?string $publicName = null, ?string $publicDescription = null): void
    {
        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => $publicName ?? 'Public Lifecycle Product',
            'public_description' => $publicDescription ?? 'Public lifecycle test description.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Public Manufacturer Inc.',
            'responsible_operator_display_name' => 'Public Operator',
            'contact_notes' => 'Public lifecycle contact.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Keep away from water'],
            'storage_instructions' => 'Store in a dry place.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Disassemble and sort by material type.',
        ]);
    }

    private function injectManufacturerContact(): void
    {
        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        $draft = $passport->currentDraftVersion;
        $payload = $draft->payload;
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@public-mfg.example';

        $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

        $newRevision = $draft->draft_revision + 1;
        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $newRevision);
        $draft->setAttribute('updated_by', $this->actor->getKey());
        $draft->save();

        $this->revision = $newRevision;
    }

    private function freshPassport(): ProductPassport
    {
        return ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion', 'currentPublishedVersion']);
    }

    private function publish(): PublicationResult
    {
        $passport = $this->freshPassport();

        return app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );
    }

    private function publicUrl(): string
    {
        return "/p/{$this->freshPassport()->public_id}";
    }

    public function test_never_published_passport_returns_404(): void
    {
        $this->createDraft();

        $this->get($this->publicUrl())->assertNotFound();
    }

    public function test_published_passport_returns_200(): void
    {
        $this->createDraft();
        $this->fillMinimalSections();
        $this->publish();

        $this->get($this->publicUrl())->assertOk();
    }

    public function test_unpublished_passport_returns_404(): void
    {
        $this->createDraft();
        $this->fillMinimalSections();
        $this->publish();

        $url = $this->publicUrl();
        $this->get($url)->assertOk();

        app(UnpublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        $this->get($url)->assertNotFound();
    }

    public function test_archived_passport_returns_404(): void
    {
        $this->createDraft();
        $this->fillMinimalSections();
        $this->publish();

        app(UnpublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        app(ArchiveProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        $this->get($this->publicUrl())->assertNotFound();
    }

    public function test_restored_but_not_published_returns_404(): void
    {
        $this->createDraft();
        $this->fillMinimalSections();
        $this->publish();

        app(UnpublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        app(ArchiveProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        app(RestoreProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        $this->get($this->publicUrl())->assertNotFound();
    }

    public function test_republished_returns_200(): void
    {
        $this->createDraft();
        $this->fillMinimalSections();
        $this->publish();

        app(UnpublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        $passport = $this->freshPassport();
        $this->revision = $passport->currentDraftVersion->draft_revision;

        $this->fillMinimalSections('Republished Product');

        $this->publish();

        $this->get($this->publicUrl())->assertOk();
    }

    public function test_superseded_version_not_shown_on_public_page(): void
    {
        $this->createDraft();
        $this->fillMinimalSections('V1 Product Name', 'V1 description text.');
        $this->publish();

        $url = $this->publicUrl();
        $this->get($url)->assertSee('V1 Product Name');

        $passport = $this->freshPassport();
        $this->revision = $passport->currentDraftVersion->draft_revision;

        $this->fillMinimalSections('V2 Product Name', 'V2 description text.');
        $this->publish();

        $this->get($url)
            ->assertSee('V2 Product Name')
            ->assertDontSee('V1 Product Name');
    }

    public function test_new_published_version_replaces_content_on_same_url(): void
    {
        $this->createDraft();
        $this->fillMinimalSections('V1 Product Name');
        $this->publish();

        $url = $this->publicUrl();
        $this->get($url)->assertSee('V1 Product Name');

        $passport = $this->freshPassport();
        $this->revision = $passport->currentDraftVersion->draft_revision;

        $this->fillMinimalSections('V2 Product Name');
        $this->publish();

        $this->get($url)
            ->assertSee('V2 Product Name')
            ->assertDontSee('V1 Product Name');
    }
}
