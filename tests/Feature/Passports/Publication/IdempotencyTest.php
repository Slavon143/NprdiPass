<?php

namespace Tests\Feature\Passports\Publication;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Data\Passports\PublicationResult;
use App\Enums\AuditEvent;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Events\Passports\ProductPassportPublished;
use App\Models\AuditLog;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\Passports\PublicationIdempotencyRecord;
use App\Models\User;
use App\Services\Passports\DppPayloadNormalizer;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class IdempotencyTest extends TestCase
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

        View::share('currentCompany', $this->company);
        View::share('availableCompanies', new Collection([$this->company]));
        View::share('currentMembership', $this->actor->memberships()
            ->where('company_id', $this->company->getKey())
            ->first());
        View::share('slot', '');

        Storage::fake('catalog_media');
        Storage::disk('catalog_media')->put('test/idempotency.jpg', 'fake content');

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Idempotency Category',
            'slug' => 'idempotency-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'idempotency-cat-'.fake()->unique()->slug(1),
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
            'name' => 'Idempotency Product '.fake()->unique()->word(),
            'slug' => 'idempotency-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'idempotency-product-'.fake()->unique()->slug(1),
            'brand' => 'Idempotency Brand',
            'manufacturer' => 'Idempotency Manufacturer',
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
            'sku' => 'SKU-ID-001',
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
            'original_filename' => 'idempotency.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/idempotency.jpg',
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
        $this->passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $this->passport->currentDraftVersion->draft_revision;

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Idempotency Product Name',
            'public_description' => 'Idempotency test description.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Idempotency Manufacturer Inc.',
            'responsible_operator_display_name' => 'Idempotency Operator',
            'contact_notes' => 'Idempotency contact.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Idempotency warning'],
            'storage_instructions' => 'Idempotency storage.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Idempotency recycling.',
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
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@idempotency.example';

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

    private function publish(ProductPassport $passport, int $revision, ?string $idempotencyKey = null): PublicationResult
    {
        return app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $revision,
            true,
            $idempotencyKey,
        );
    }

    public function test_same_idempotency_key_returns_same_result(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();
        $revision = $this->revision;

        $first = $this->publish($passport, $revision, 'key-return-same');

        $this->assertNotNull($first->publishedVersion->uuid);

        $passport = $this->freshPassport();

        $second = $this->publish($passport, $revision, 'key-return-same');

        $this->assertSame(
            $first->publishedVersion->uuid,
            $second->publishedVersion->uuid,
            'Idempotent retry must return the same published version UUID.',
        );
    }

    public function test_different_key_creates_new_version(): void
    {
        $this->fillAllSections();

        $passport = $this->freshPassport();
        $v1 = $this->publish($passport, $this->revision, 'key-different-v1');

        $passport = $this->freshPassport();
        $revision2 = $passport->currentDraftVersion->draft_revision;

        $v2 = $this->publish($passport, $revision2, 'key-different-v2');

        $this->assertNotSame(
            $v1->publishedVersion->uuid,
            $v2->publishedVersion->uuid,
            'Different idempotency keys must create different versions.',
        );

        $this->assertSame(2, $v2->publishedVersion->version_number);
    }

    public function test_same_key_different_revision_returns_409(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();
        $revision = $this->revision;

        $this->publish($passport, $revision, 'key-revision-409');

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Idempotency key reused with a different revision.');

        $this->publish($passport, 999, 'key-revision-409');
    }

    public function test_same_key_different_passport_returns_409(): void
    {
        $this->fillAllSections();

        $passportA = $this->freshPassport();
        $this->publish($passportA, $this->revision, 'key-cross-passport');

        $otherProduct = new Product;
        $otherProduct->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Other Product '.fake()->unique()->word(),
            'slug' => 'other-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'other-product-'.fake()->unique()->slug(1),
            'brand' => 'Other Brand',
            'manufacturer' => 'Other Manufacturer',
            'status' => ProductStatus::Active,
            'primary_category_id' => $this->product->primary_category_id,
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $otherVariant = new ProductVariant;
        $otherVariant->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $otherProduct->getKey(),
            'name' => 'Other Variant',
            'sku' => 'SKU-OTHER-001',
            'status' => ProductVariantStatus::Active,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $otherMedia = new ProductMedia;
        $otherMedia->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $otherProduct->getKey(),
            'original_filename' => 'other.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/idempotency.jpg',
            'checksum_sha256' => str_repeat('b', 64),
            'sort_order' => 0,
            'uploaded_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $otherProduct->forceFill([
            'default_variant_id' => $otherVariant->getKey(),
            'primary_media_id' => $otherMedia->getKey(),
        ])->save();

        $otherPassport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $otherProduct,
        );

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Idempotency key already used for a different passport.');

        $this->publish($otherPassport, 1, 'key-cross-passport');
    }

    public function test_idempotency_prevents_duplicate_versions(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();
        $revision = $this->revision;

        $versionCountBefore = ProductPassportVersion::query()
            ->where('passport_id', $passport->getKey())
            ->count();

        $this->publish($passport, $revision, 'key-no-dup');

        $versionCountAfter = ProductPassportVersion::query()
            ->where('passport_id', $passport->getKey())
            ->count();

        $expectedCount = $versionCountBefore + 1;

        $this->assertSame(
            $expectedCount,
            $versionCountAfter,
            'Publish transitions draft to published + new draft. Expected '.$expectedCount.' but got '.$versionCountAfter.'.',
        );
    }

    public function test_idempotency_prevents_duplicate_audit(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();
        $revision = $this->revision;

        $auditCountBefore = AuditLog::query()
            ->where('company_id', $this->company->getKey())
            ->where('event', AuditEvent::PassportPublished->value)
            ->count();

        $this->publish($passport, $revision, 'key-no-dup-audit');

        $passport = $this->freshPassport();

        $this->publish($passport, $revision, 'key-no-dup-audit');

        $auditCountAfter = AuditLog::query()
            ->where('company_id', $this->company->getKey())
            ->where('event', AuditEvent::PassportPublished->value)
            ->count();

        $this->assertSame(
            $auditCountBefore + 1,
            $auditCountAfter,
            'Idempotent retry must not create duplicate audit events.',
        );
    }

    public function test_same_key_different_company_returns_409(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();

        $this->publish($passport, $this->revision, 'key-cross-company');

        $otherCompany = Company::factory()->create(['status' => CompanyStatus::Active]);
        $otherActor = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'company_id' => $otherCompany->getKey(),
            'user_id' => $otherActor->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $this->actingAs($otherActor);
        app(CurrentCompany::class)->set($otherCompany);

        $this->expectException(ConflictHttpException::class);

        app(PublishProductPassport::class)->handle(
            $otherActor,
            $otherCompany,
            $this->product,
            $passport,
            $this->revision,
            true,
            'key-cross-company',
        );
    }

    public function test_same_key_after_cache_clear_uses_mysql_source_of_truth(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();
        $revision = $this->revision;

        $first = $this->publish($passport, $revision, 'key-cache-clear');

        $this->assertNotNull($first->publishedVersion->uuid);

        $mysqlRecord = PublicationIdempotencyRecord::query()
            ->where('company_id', $this->company->getKey())
            ->where('operation', 'publish')
            ->where('idempotency_key', 'key-cache-clear')
            ->first();

        $this->assertNotNull($mysqlRecord, 'MySQL idempotency record must exist after publish.');
        $this->assertSame('completed', $mysqlRecord->status);

        Cache::flush();

        $passport = $this->freshPassport();

        $second = $this->publish($passport, $revision, 'key-cache-clear');

        $this->assertSame(
            $first->publishedVersion->uuid,
            $second->publishedVersion->uuid,
            'After cache clear, MySQL must serve as source of truth and return the same version.',
        );

        $versionCount = ProductPassportVersion::query()
            ->where('passport_id', $passport->getKey())
            ->count();

        $this->assertSame(2, $versionCount, 'No additional versions created after idempotent retry with cache clear.');
    }

    public function test_same_key_restart_simulation_returns_same_result(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();
        $revision = $this->revision;

        $first = $this->publish($passport, $revision, 'key-restart');

        Cache::flush();

        $passport = $this->freshPassport();

        $second = app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $revision,
            true,
            'key-restart',
        );

        $this->assertSame(
            $first->publishedVersion->uuid,
            $second->publishedVersion->uuid,
            'After restart simulation, same key must return same published version.',
        );

        $this->assertNotNull($second->publishedVersion->uuid);
    }

    public function test_idempotency_prevents_duplicate_domain_events(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();
        $revision = $this->revision;

        $this->publish($passport, $revision, 'key-domain-events');

        Event::fake([ProductPassportPublished::class]);

        $passport = $this->freshPassport();

        $this->publish($passport, $revision, 'key-domain-events');

        Event::assertNotDispatched(ProductPassportPublished::class);
    }

    public function test_failure_followed_by_safe_retry(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();

        $versionCountBefore = ProductPassportVersion::query()
            ->where('passport_id', $passport->getKey())
            ->count();

        try {
            app(PublishProductPassport::class)->handle(
                $this->actor,
                $this->company,
                $this->product,
                $passport,
                999,
                true,
                'key-failure-retry',
            );
            $this->fail('Expected revision conflict exception.');
        } catch (ConflictHttpException) {
        }

        $mysqlRecord = PublicationIdempotencyRecord::query()
            ->where('company_id', $this->company->getKey())
            ->where('operation', 'publish')
            ->where('idempotency_key', 'key-failure-retry')
            ->first();

        $this->assertNull($mysqlRecord, 'Failed publication must not leave an idempotency record.');

        $versionCountAfter = ProductPassportVersion::query()
            ->where('passport_id', $passport->getKey())
            ->count();

        $this->assertSame($versionCountBefore, $versionCountAfter, 'Failed publication must not create versions.');

        $passport = $this->freshPassport();
        $correctRevision = $passport->currentDraftVersion->draft_revision;

        $result = app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $correctRevision,
            true,
            'key-failure-retry',
        );

        $this->assertNotNull($result->publishedVersion->uuid, 'Retry after failure must succeed.');

        $versionCountFinal = ProductPassportVersion::query()
            ->where('passport_id', $passport->getKey())
            ->count();

        $this->assertSame($versionCountBefore + 1, $versionCountFinal, 'Retry must create exactly one new version.');
    }

    public function test_success_followed_by_repeated_retry_is_idempotent(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();
        $revision = $this->revision;

        $first = $this->publish($passport, $revision, 'key-repeat');

        $passport = $this->freshPassport();
        $second = $this->publish($passport, $revision, 'key-repeat');

        $passport = $this->freshPassport();
        $third = $this->publish($passport, $revision, 'key-repeat');

        $this->assertSame($first->publishedVersion->uuid, $second->publishedVersion->uuid);
        $this->assertSame($first->publishedVersion->uuid, $third->publishedVersion->uuid);

        $publishedVersions = ProductPassportVersion::query()
            ->where('passport_id', $passport->getKey())
            ->where('status', ProductPassportVersionStatus::Published->value)
            ->count();

        $this->assertSame(1, $publishedVersions, 'Only one published version must exist after repeated retries.');

        $draftCount = ProductPassportVersion::query()
            ->where('passport_id', $passport->getKey())
            ->where('status', ProductPassportVersionStatus::Draft->value)
            ->count();

        $this->assertSame(1, $draftCount, 'Only one active draft must exist after repeated retries.');
    }

    public function test_idempotency_record_has_correct_fingerprint(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();
        $revision = $this->revision;

        $this->publish($passport, $revision, 'key-fingerprint');

        $record = PublicationIdempotencyRecord::query()
            ->where('company_id', $this->company->getKey())
            ->where('operation', 'publish')
            ->where('idempotency_key', 'key-fingerprint')
            ->first();

        $this->assertNotNull($record, 'Idempotency record must exist.');
        $this->assertSame('completed', $record->status);
        $this->assertNotNull($record->request_fingerprint);
        $this->assertSame(64, strlen($record->request_fingerprint), 'Fingerprint must be a 64-char SHA-256 hex.');
        $this->assertNotNull($record->published_version_id);
        $this->assertNotNull($record->started_at);
        $this->assertNotNull($record->completed_at);
    }
}
