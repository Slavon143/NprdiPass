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
use App\Services\Passports\CanonicalJsonEncoder;
use App\Services\Passports\DppPayloadNormalizer;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StorageFailureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

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

        View::share('currentCompany', $this->company);
        View::share('availableCompanies', new Collection([$this->company]));
        View::share('currentMembership', $this->actor->memberships()
            ->where('company_id', $this->company->getKey())
            ->first());
        View::share('slot', '');

        Storage::fake('catalog_media');
        Storage::disk('catalog_media')->put('test/storage-failure.jpg', 'fake content');

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Storage Failure Category',
            'slug' => 'storage-fail-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'storage-fail-cat-'.fake()->unique()->slug(1),
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
            'name' => 'Storage Failure Product '.fake()->unique()->word(),
            'slug' => 'storage-fail-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'storage-fail-product-'.fake()->unique()->slug(1),
            'brand' => 'Storage Failure Brand',
            'manufacturer' => 'Storage Failure Manufacturer',
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
            'sku' => 'SKU-SF-001',
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
            'original_filename' => 'storage-failure.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/storage-failure.jpg',
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

    private function fillAllSections(): void
    {
        $passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $passport->currentDraftVersion->draft_revision;

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Storage Failure Product Name',
            'public_description' => 'Storage failure test description.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Storage Failure Mfg Inc.',
            'responsible_operator_display_name' => 'Storage Failure Operator',
            'contact_notes' => 'Storage failure contact.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Storage failure warning'],
            'storage_instructions' => 'Storage failure storage.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Storage failure recycling.',
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
            'usage_instructions' => 'Usage.',
        ]);

        $this->fillSection(DppSectionKey::RepairAndSpareParts, [
            'repair_instructions' => 'Repair.',
        ]);

        $this->fillSection(DppSectionKey::SupportAndContact, [
            'warranty_summary' => 'Warranty.',
        ]);
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

    private function injectManufacturerContact(): void
    {
        $draft = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->currentDraftVersion;

        $payload = $draft->payload;
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@storage-failure.example';

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

    public function test_missing_media_source_blocks_publication(): void
    {
        $this->fillAllSections();

        Storage::disk('catalog_media')->delete('test/storage-failure.jpg');

        $passport = $this->freshPassport();

        $versionCountBefore = ProductPassportVersion::query()->count();

        try {
            app(PublishProductPassport::class)->handle(
                $this->actor,
                $this->company,
                $this->product,
                $passport,
                $this->revision,
                true,
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'The passport is not ready for publication.',
                $e->getMessage(),
            );
        }

        $versionCountAfter = ProductPassportVersion::query()->count();
        $this->assertSame(
            $versionCountBefore,
            $versionCountAfter,
            'No version should be created when publication is blocked.',
        );
    }

    public function test_exception_during_staging_rolls_back(): void
    {
        $this->fillAllSections();

        $mockEncoder = $this->createMock(CanonicalJsonEncoder::class);
        $mockEncoder->method('hash')
            ->willThrowException(new \RuntimeException('Simulated storage failure during checksum'));

        $this->instance(CanonicalJsonEncoder::class, $mockEncoder);

        $passport = $this->freshPassport();

        $versionCountBefore = ProductPassportVersion::query()->count();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Simulated storage failure during checksum');

        app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $versionCountAfter = ProductPassportVersion::query()->count();
        $this->assertSame(
            $versionCountBefore,
            $versionCountAfter,
            'DB must roll back after exception during staging.',
        );
    }

    public function test_no_orphan_staging_files_after_rollback(): void
    {
        $this->fillAllSections();

        Storage::disk('catalog_media')->delete('test/storage-failure.jpg');

        $passport = $this->freshPassport();

        $versionCountBefore = ProductPassportVersion::query()->count();
        $publishedCountBefore = ProductPassportVersion::query()
            ->where('status', 'published')
            ->count();

        try {
            app(PublishProductPassport::class)->handle(
                $this->actor,
                $this->company,
                $this->product,
                $passport,
                $this->revision,
                true,
            );
        } catch (ValidationException) {
        }

        $versionCountAfter = ProductPassportVersion::query()->count();
        $publishedCountAfter = ProductPassportVersion::query()
            ->where('status', 'published')
            ->count();

        $this->assertSame($versionCountBefore, $versionCountAfter);
        $this->assertSame($publishedCountBefore, $publishedCountAfter);

        $passport = $this->freshPassport();
        $this->assertNull(
            $passport->current_published_version_id,
            'No published version should be assigned after failed publish.',
        );
    }

    public function test_no_audit_event_on_failed_publication(): void
    {
        $this->fillAllSections();

        Storage::disk('catalog_media')->delete('test/storage-failure.jpg');

        $passport = $this->freshPassport();

        $auditCountBefore = AuditLog::query()
            ->where('company_id', $this->company->getKey())
            ->count();

        try {
            app(PublishProductPassport::class)->handle(
                $this->actor,
                $this->company,
                $this->product,
                $passport,
                $this->revision,
                true,
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

        $this->assertNull($publishedEvent, 'No PassportPublished audit event should exist after failed publication.');
    }
}
