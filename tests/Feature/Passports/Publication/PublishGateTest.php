<?php

namespace Tests\Feature\Passports\Publication;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Enums\AuditEvent;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\AuditLog;
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
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class PublishGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    private Category $category;

    private ProductVariant $defaultVariant;

    private ProductMedia $primaryMedia;

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
        Storage::disk('catalog_media')->put('test/test-image.jpg', 'fake content');

        $this->category = new Category;
        $this->category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Test Category',
            'slug' => 'test-category-'.fake()->unique()->slug(1),
            'slug_normalized' => 'test-category-'.fake()->unique()->slug(1),
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
            'name' => 'Publish Gate Product '.fake()->unique()->word(),
            'slug' => 'publish-gate-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'publish-gate-product-'.fake()->unique()->slug(1),
            'brand' => 'Test Brand',
            'manufacturer' => 'Test Manufacturer',
            'description' => 'Test product description.',
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
            'sku' => 'SKU-TEST-001',
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
            'original_filename' => 'test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/test-image.jpg',
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
    }

    private function createDraftPassport(): ProductPassport
    {
        $passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $passport->currentDraftVersion->draft_revision;

        return $passport;
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

    private function fillCoreSections(): void
    {
        $this->createDraftPassport();

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Test Product Name',
            'public_description' => 'A test product description for publication.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Test Manufacturer Inc.',
            'responsible_operator_display_name' => 'Test Operator',
            'contact_notes' => 'Contact during business hours.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Keep away from water', 'Handle with care'],
            'storage_instructions' => 'Store in a dry place.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Disassemble and sort by material type.',
        ]);
    }

    private function fillAllSections(): void
    {
        $this->fillCoreSections();

        $this->fillSection(DppSectionKey::MaterialsAndComposition, [
            'materials' => [
                ['name' => 'Plastic', 'percentage' => 60.0, 'recycled_content_percentage' => 30.0, 'hazardous' => false],
                ['name' => 'Steel', 'percentage' => 40.0, 'recycled_content_percentage' => 50.0, 'hazardous' => false],
            ],
        ]);

        $this->fillSection(DppSectionKey::EnvironmentalInformation, [
            'carbon_footprint_kg_co2e' => 12.5,
            'expected_lifetime_years' => 5.0,
        ]);

        $this->fillSection(DppSectionKey::UsageAndCare, [
            'usage_instructions' => 'Plug in and press power button.',
            'care_instructions' => 'Clean with a dry cloth.',
        ]);

        $this->fillSection(DppSectionKey::RepairAndSpareParts, [
            'repair_instructions' => 'Contact authorized service center.',
            'spare_parts_notes' => 'Spare parts available through authorized dealers.',
        ]);

        $this->fillSection(DppSectionKey::SupportAndContact, [
            'warranty_summary' => '2-year limited warranty.',
            'support_notes' => 'Contact support for assistance.',
        ]);
    }

    private function injectManufacturerContact(): void
    {
        $draft = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->currentDraftVersion;

        $normalizer = app(DppPayloadNormalizer::class);
        $payload = $draft->payload;

        if (! isset($payload['data'])) {
            $payload['data'] = [];
        }
        if (! isset($payload['data']['manufacturer_and_operator'])) {
            $payload['data']['manufacturer_and_operator'] = [];
        }
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@test-mfg.example';

        $normalized = $normalizer->normalize($payload);

        $newRevision = $draft->draft_revision + 1;
        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $newRevision);
        $draft->setAttribute('updated_by', $this->actor->getKey());
        $draft->save();

        $this->revision = $newRevision;
    }

    private function publishAction(): PublishProductPassport
    {
        return app(PublishProductPassport::class);
    }

    private function draftPassport(): ProductPassport
    {
        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        return $passport->fresh(['currentDraftVersion']);
    }

    public function test_not_ready_passport_cannot_publish(): void
    {
        $passport = $this->createDraftPassport();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The passport is not ready for publication.');

        $this->publishAction()->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
        );
    }

    public function test_ready_with_warnings_without_acknowledgment_returns_422(): void
    {
        $this->fillCoreSections();
        $passport = $this->draftPassport();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The passport has warnings. Acknowledge them to proceed.');

        $this->publishAction()->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            false,
        );
    }

    public function test_ready_with_warnings_with_acknowledgment_publishes(): void
    {
        $this->fillCoreSections();
        $passport = $this->draftPassport();

        $result = $this->publishAction()->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $this->assertSame(ProductPassportStatus::Published, $result->passport->status);
        $this->assertSame(ProductPassportVersionStatus::Published, $result->publishedVersion->status);
        $this->assertNotNull($result->publishedVersion->version_number);
        $this->assertNotNull($result->publishedVersion->published_at);
        $this->assertNotNull($result->passport->current_published_version_id);
    }

    public function test_ready_passport_publishes(): void
    {
        $this->fillAllSections();
        $passport = $this->draftPassport();

        $result = $this->publishAction()->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $this->assertSame(ProductPassportStatus::Published, $result->passport->status);
        $this->assertSame(ProductPassportVersionStatus::Published, $result->publishedVersion->status);
        $this->assertNotNull($result->publishedVersion->version_number);
        $this->assertNotNull($result->passport->current_published_version_id);
    }

    public function test_readiness_is_recalculated_in_transaction(): void
    {
        $this->fillAllSections();
        $passport = $this->draftPassport();

        $revisionBeforeEdit = $this->revision;

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Updated after readiness was loaded'],
            'storage_instructions' => 'Updated storage instructions.',
        ]);

        $passport = $this->draftPassport();
        $result = $this->publishAction()->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $this->assertSame(ProductPassportStatus::Published, $result->passport->status);
        $this->assertGreaterThan($revisionBeforeEdit, $result->publishedVersion->draft_revision);
    }

    public function test_expected_revision_required(): void
    {
        $passport = $this->createDraftPassport();

        $this->expectException(ConflictHttpException::class);

        $this->publishAction()->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            0,
        );
    }

    public function test_stale_revision_returns_409(): void
    {
        $this->fillAllSections();
        $passport = $this->draftPassport();

        $this->expectException(ConflictHttpException::class);

        $this->publishAction()->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            999,
        );
    }

    public function test_rejected_publication_creates_no_version(): void
    {
        $passport = $this->createDraftPassport();

        $versionCountBefore = ProductPassportVersion::query()->count();

        try {
            $this->publishAction()->handle(
                $this->actor,
                $this->company,
                $this->product,
                $passport,
                $this->revision,
            );
        } catch (ValidationException) {
        }

        $versionCountAfter = ProductPassportVersion::query()->count();

        $this->assertSame($versionCountBefore, $versionCountAfter);
    }

    public function test_rejected_publication_creates_no_audit(): void
    {
        $passport = $this->createDraftPassport();

        $auditCountBefore = AuditLog::query()
            ->where('company_id', $this->company->getKey())
            ->count();

        try {
            $this->publishAction()->handle(
                $this->actor,
                $this->company,
                $this->product,
                $passport,
                $this->revision,
            );
        } catch (ValidationException) {
        }

        $auditCountAfter = AuditLog::query()
            ->where('company_id', $this->company->getKey())
            ->count();

        $this->assertSame($auditCountBefore, $auditCountAfter);

        $publishedEvent = AuditLog::query()
            ->where('event', AuditEvent::PassportPublished->value)
            ->where('company_id', $this->company->getKey())
            ->first();

        $this->assertNull($publishedEvent);
    }

    public function test_published_passport_creates_audit_event(): void
    {
        $this->fillAllSections();
        $passport = $this->draftPassport();

        $result = $this->publishAction()->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $log = AuditLog::query()
            ->where('event', AuditEvent::PassportPublished->value)
            ->where('company_id', $this->company->getKey())
            ->first();

        $this->assertNotNull($log);

        $properties = $log->properties->toArray();
        $this->assertArrayHasKey('product_uuid', $properties);
        $this->assertArrayHasKey('passport_uuid', $properties);
        $this->assertArrayHasKey('published_version_uuid', $properties);
        $this->assertArrayHasKey('version_number', $properties);
    }
}
