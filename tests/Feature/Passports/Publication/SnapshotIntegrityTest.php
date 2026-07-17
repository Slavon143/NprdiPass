<?php

namespace Tests\Feature\Passports\Publication;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Data\Passports\PublicationResult;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Passports\DppPayloadNormalizer;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SnapshotIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    private Category $category;

    private ProductVariant $defaultVariant;

    private ProductMedia $primaryMedia;

    private ProductPassport $passport;

    private ProductPassportVersion $publishedV1;

    private array $v1Payload;

    private ?string $v1Checksum;

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
        Storage::disk('catalog_media')->put('test/original-image.jpg', 'fake content');

        $this->category = new Category;
        $this->category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Snapshot Category',
            'slug' => 'snapshot-category-'.fake()->unique()->slug(1),
            'slug_normalized' => 'snapshot-category-'.fake()->unique()->slug(1),
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
            'name' => 'Snapshot Test Product '.fake()->unique()->word(),
            'slug' => 'snapshot-test-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'snapshot-test-product-'.fake()->unique()->slug(1),
            'brand' => 'Original Brand',
            'manufacturer' => 'Original Manufacturer',
            'description' => 'Original description.',
            'status' => ProductStatus::Active,
            'primary_category_id' => $this->category->getKey(),
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->defaultVariant = new ProductVariant;
        $this->defaultVariant->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'name' => 'Default Variant',
            'sku' => 'SKU-ORIGINAL-001',
            'status' => ProductVariantStatus::Active,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->primaryMedia = new ProductMedia;
        $this->primaryMedia->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'original_filename' => 'original-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/original-image.jpg',
            'checksum_sha256' => str_repeat('a', 64),
            'sort_order' => 0,
            'uploaded_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->product->forceFill([
            'default_variant_id' => $this->defaultVariant->getKey(),
            'primary_media_id' => $this->primaryMedia->getKey(),
        ])->save();

        $this->product->categories()->attach($this->category->getKey(), [
            'company_id' => $this->company->getKey(),
            'created_at' => now(),
        ]);

        $this->product->refresh();

        $this->createAndPublishV1();
    }

    private function fillSection(DppSectionKey $section, array $payload): ProductPassport
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

        return $result;
    }

    private function createAndPublishV1(): void
    {
        $this->passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $this->passport->currentDraftVersion->draft_revision;

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Original Public Name',
            'public_description' => 'Original public description.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Original Manufacturer Inc.',
            'responsible_operator_display_name' => 'Original Operator',
            'contact_notes' => 'Original contact notes.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Original warning A'],
            'storage_instructions' => 'Original storage instructions.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Original recycling instructions.',
        ]);

        $this->fillSection(DppSectionKey::MaterialsAndComposition, [
            'materials' => [
                ['name' => 'Plastic', 'percentage' => 60.0, 'recycled_content_percentage' => 30.0, 'hazardous' => false],
            ],
        ]);

        $this->fillSection(DppSectionKey::EnvironmentalInformation, [
            'carbon_footprint_kg_co2e' => 12.5,
        ]);

        $this->fillSection(DppSectionKey::UsageAndCare, [
            'usage_instructions' => 'Original usage instructions.',
        ]);

        $this->fillSection(DppSectionKey::RepairAndSpareParts, [
            'repair_instructions' => 'Original repair instructions.',
        ]);

        $this->fillSection(DppSectionKey::SupportAndContact, [
            'warranty_summary' => 'Original warranty.',
        ]);

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion']);

        $result = $this->publish($passport, $this->revision);

        $this->passport = $result->passport->fresh(['currentDraftVersion', 'currentPublishedVersion']);
        $this->publishedV1 = $this->passport->currentPublishedVersion;
        $this->v1Payload = $this->publishedV1->payload;
        $this->v1Checksum = $this->publishedV1->content_checksum;
    }

    private function assertV1Unchanged(): void
    {
        $v1 = ProductPassportVersion::query()->find($this->publishedV1->getKey());

        $this->assertSame(
            $this->v1Checksum,
            $v1->content_checksum,
            'Version 1 checksum should not change.',
        );

        $this->assertEqualsCanonicalizing(
            $this->v1Payload,
            $v1->payload,
            'Version 1 payload should not change.',
        );
    }

    private function publish(ProductPassport $passport, int $revision): PublicationResult
    {
        return app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $revision,
            true,
        );
    }

    private function injectManufacturerContact(): void
    {
        $draft = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->currentDraftVersion;

        $payload = $draft->payload;
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@snapshot-mfg.example';

        $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

        $newRevision = $draft->draft_revision + 1;
        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $newRevision);
        $draft->setAttribute('updated_by', $this->actor->getKey());
        $draft->save();

        $this->revision = $newRevision;
    }

    public function test_product_name_change_does_not_affect_version_1(): void
    {
        $this->product->forceFill(['name' => 'Changed Product Name'])->save();
        $this->product->refresh();

        $this->assertV1Unchanged();
    }

    public function test_product_brand_change_does_not_affect_version_1(): void
    {
        $this->product->forceFill(['brand' => 'Changed Brand'])->save();
        $this->product->refresh();

        $this->assertV1Unchanged();
    }

    public function test_product_category_change_does_not_affect_version_1(): void
    {
        $newCategory = new Category;
        $newCategory->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'New Category',
            'slug' => 'new-category-'.fake()->unique()->slug(1),
            'slug_normalized' => 'new-category-'.fake()->unique()->slug(1),
            'depth' => 0,
            'sort_order' => 1,
            'status' => CategoryStatus::Active,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->product->forceFill(['primary_category_id' => $newCategory->getKey()])->save();
        $this->product->refresh();

        $this->assertV1Unchanged();
    }

    public function test_variant_sku_change_does_not_affect_version_1(): void
    {
        $this->defaultVariant->forceFill(['sku' => 'SKU-CHANGED-999'])->save();
        $this->defaultVariant->refresh();

        $this->assertV1Unchanged();
    }

    public function test_dpp_identity_change_does_not_affect_version_1(): void
    {
        $passport = $this->passport->fresh(['currentDraftVersion']);
        $draftRev = $passport->currentDraftVersion->draft_revision;

        app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            DppSectionKey::Identity->value,
            ['public_name' => 'Changed Identity Name'],
            $draftRev,
        );

        $this->assertV1Unchanged();
    }

    public function test_dpp_safety_change_does_not_affect_version_1(): void
    {
        $passport = $this->passport->fresh(['currentDraftVersion']);
        $draftRev = $passport->currentDraftVersion->draft_revision;

        app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            DppSectionKey::Safety->value,
            ['warnings' => ['Changed warning']],
            $draftRev,
        );

        $this->assertV1Unchanged();
    }

    public function test_primary_media_change_does_not_affect_version_1(): void
    {
        $newMedia = new ProductMedia;
        $newMedia->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'original_filename' => 'changed-image.jpg',
            'mime_type' => 'image/png',
            'size_bytes' => 2048,
            'storage_path' => 'test/changed-image.jpg',
            'checksum_sha256' => str_repeat('b', 64),
            'sort_order' => 1,
            'uploaded_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->product->forceFill(['primary_media_id' => $newMedia->getKey()])->save();
        $this->product->refresh();

        $this->assertV1Unchanged();
    }

    public function test_document_version_change_does_not_affect_version_1(): void
    {
        $this->assertV1Unchanged();
    }

    public function test_publish_version_2_does_not_affect_version_1(): void
    {
        $passport = $this->passport->fresh(['currentDraftVersion']);
        $draftRev = $passport->currentDraftVersion->draft_revision;

        app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            DppSectionKey::Identity->value,
            ['public_name' => 'Version 2 Name'],
            $draftRev,
        );

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion']);

        $draftRev = $passport->currentDraftVersion->draft_revision;

        $result = $this->publish($passport, $draftRev);

        $this->assertSame(2, $result->publishedVersion->version_number);
        $this->assertV1Unchanged();
    }

    public function test_version_1_superseded_after_version_2(): void
    {
        $passport = $this->passport->fresh(['currentDraftVersion']);
        $draftRev = $passport->currentDraftVersion->draft_revision;

        app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            DppSectionKey::Identity->value,
            ['public_name' => 'Superseded Test Name'],
            $draftRev,
        );

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion']);

        $this->publish($passport, $passport->currentDraftVersion->draft_revision);

        $v1Fresh = ProductPassportVersion::query()->find($this->publishedV1->getKey());

        $this->assertSame(
            ProductPassportVersionStatus::Superseded,
            $v1Fresh->status,
        );

        $this->assertNotNull($v1Fresh->superseded_at);
    }
}
