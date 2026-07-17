<?php

namespace Tests\Feature\Passports\Publication;

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
use App\Enums\Passports\ProductPassportVersionStatus;
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
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class LifecycleTest extends TestCase
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
        Storage::disk('catalog_media')->put('test/lifecycle.jpg', 'fake content');

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Lifecycle Category',
            'slug' => 'lifecycle-category-'.fake()->unique()->slug(1),
            'slug_normalized' => 'lifecycle-category-'.fake()->unique()->slug(1),
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
            'name' => 'Lifecycle Test Product '.fake()->unique()->word(),
            'slug' => 'lifecycle-test-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'lifecycle-test-product-'.fake()->unique()->slug(1),
            'brand' => 'Lifecycle Brand',
            'manufacturer' => 'Lifecycle Manufacturer',
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
            'sku' => 'SKU-LC-001',
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
            'original_filename' => 'lifecycle.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/lifecycle.jpg',
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

    private function fillAllSections(): void
    {
        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Lifecycle Product Name',
            'public_description' => 'Lifecycle test description.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Lifecycle Manufacturer Inc.',
            'responsible_operator_display_name' => 'Lifecycle Operator',
            'contact_notes' => 'Lifecycle contact.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Lifecycle warning'],
            'storage_instructions' => 'Lifecycle storage.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Lifecycle recycling.',
        ]);

        $this->fillSection(DppSectionKey::MaterialsAndComposition, [
            'materials' => [
                ['name' => 'Plastic', 'percentage' => 100.0, 'recycled_content_percentage' => 30.0, 'hazardous' => false],
            ],
        ]);

        $this->fillSection(DppSectionKey::EnvironmentalInformation, [
            'carbon_footprint_kg_co2e' => 10.0,
        ]);

        $this->fillSection(DppSectionKey::UsageAndCare, [
            'usage_instructions' => 'Lifecycle usage.',
        ]);

        $this->fillSection(DppSectionKey::RepairAndSpareParts, [
            'repair_instructions' => 'Lifecycle repair.',
        ]);

        $this->fillSection(DppSectionKey::SupportAndContact, [
            'warranty_summary' => 'Lifecycle warranty.',
        ]);
    }

    private function freshPassport(): ProductPassport
    {
        return ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion', 'currentPublishedVersion']);
    }

    private function injectManufacturerContact(): void
    {
        $draft = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->currentDraftVersion;

        $payload = $draft->payload;
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@lifecycle-mfg.example';

        $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

        $newRevision = $draft->draft_revision + 1;
        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $newRevision);
        $draft->setAttribute('updated_by', $this->actor->getKey());
        $draft->save();

        $this->revision = $newRevision;
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

    public function test_draft_to_published(): void
    {
        $this->createDraft();
        $this->fillAllSections();

        $passport = $this->freshPassport();

        $result = $this->publish($passport, $this->revision);

        $fresh = $this->freshPassport();

        $this->assertSame(ProductPassportStatus::Published, $fresh->status);
        $this->assertSame(ProductPassportVersionStatus::Published, $fresh->currentPublishedVersion->status);
        $this->assertNotNull($fresh->current_published_version_id);
        $this->assertNotNull($fresh->first_published_at);
        $this->assertNotNull($fresh->last_published_at);
    }

    public function test_published_to_unpublished(): void
    {
        $this->createDraft();
        $this->fillAllSections();

        $passport = $this->freshPassport();

        $this->publish($passport, $this->revision);

        $publishedPassport = $this->freshPassport();

        app(UnpublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $publishedPassport,
        );

        $fresh = $this->freshPassport();

        $this->assertSame(ProductPassportStatus::Unpublished, $fresh->status);
        $this->assertNull($fresh->current_published_version_id);
        $this->assertNotNull($fresh->unpublished_at);

        $withdrawnVersion = $publishedPassport->currentPublishedVersion->fresh();
        $this->assertSame(ProductPassportVersionStatus::Withdrawn, $withdrawnVersion->status);
        $this->assertNotNull($withdrawnVersion->withdrawn_at);
    }

    public function test_unpublished_to_published(): void
    {
        $this->createDraft();
        $this->fillAllSections();

        $passport = $this->freshPassport();

        $firstPublish = $this->publish($passport, $this->revision);

        $this->assertSame(1, $firstPublish->publishedVersion->version_number);

        app(UnpublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        $passport = $this->freshPassport();
        $this->revision = $passport->currentDraftVersion->draft_revision;

        $result = $this->publish($passport, $this->revision);

        $this->assertSame(2, $result->publishedVersion->version_number);

        $fresh = $this->freshPassport();
        $this->assertSame(ProductPassportStatus::Published, $fresh->status);
        $this->assertSame(ProductPassportVersionStatus::Published, $fresh->currentPublishedVersion->status);
    }

    public function test_draft_to_archived(): void
    {
        $this->createDraft();

        app(ArchiveProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        $fresh = $this->freshPassport();

        $this->assertSame(ProductPassportStatus::Archived, $fresh->status);
        $this->assertNotNull($fresh->archived_at);
    }

    public function test_unpublished_to_archived(): void
    {
        $this->createDraft();
        $this->fillAllSections();

        $passport = $this->freshPassport();

        $this->publish($passport, $this->revision);

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

        $fresh = $this->freshPassport();

        $this->assertSame(ProductPassportStatus::Archived, $fresh->status);
    }

    public function test_archived_to_draft(): void
    {
        $this->createDraft();

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

        $fresh = $this->freshPassport();

        $this->assertSame(ProductPassportStatus::Draft, $fresh->status);
        $this->assertNull($fresh->archived_at);
    }

    public function test_published_cannot_archive_directly(): void
    {
        $this->createDraft();
        $this->fillAllSections();

        $passport = $this->freshPassport();

        $this->publish($passport, $this->revision);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Passport must be unpublished before archiving.');

        app(ArchiveProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );
    }

    public function test_archived_cannot_publish_directly(): void
    {
        $this->createDraft();

        app(ArchiveProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        $passport = $this->freshPassport();

        $this->expectException(ValidationException::class);

        $this->publish($passport, 1);
    }

    public function test_unpublish_when_no_published_version_rejected(): void
    {
        $this->createDraft();

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Passport has no published version to unpublish.');

        app(UnpublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );
    }

    public function test_restore_when_not_archived_rejected(): void
    {
        $this->createDraft();

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Only archived passports can be restored.');

        app(RestoreProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );
    }
}
