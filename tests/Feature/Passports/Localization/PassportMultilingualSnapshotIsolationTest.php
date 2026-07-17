<?php

namespace Tests\Feature\Passports\Localization;

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

class PassportMultilingualSnapshotIsolationTest extends TestCase
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
        Storage::disk('catalog_media')->put('test/snapshot-iso.jpg', 'fake content');

        $this->category = new Category;
        $this->category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Snapshot Iso Category',
            'slug' => 'snapshot-iso-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'snapshot-iso-cat-'.fake()->unique()->slug(1),
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
            'name' => 'Snapshot Iso Product '.fake()->unique()->word(),
            'slug' => 'snapshot-iso-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'snapshot-iso-product-'.fake()->unique()->slug(1),
            'brand' => 'Test Brand',
            'manufacturer' => 'Test Manufacturer',
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
            'sku' => 'SKU-ISO-001',
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
            'original_filename' => 'snapshot-iso.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/snapshot-iso.jpg',
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

    private function fillSection(DppSectionKey $section, array $payload, string $locale): void
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
            $locale,
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
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@example.com';

        $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

        $newRevision = $draft->draft_revision + 1;
        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $newRevision);
        $draft->setAttribute('updated_by', $this->actor->getKey());
        $draft->save();

        $this->revision = $newRevision;
    }

    private function fillIdentity(string $locale, string $publicName): void
    {
        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => $publicName,
            'public_description' => "{$publicName} description.",
        ], $locale);
    }

    private function fillSafety(string $locale, string $warning): void
    {
        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => [$warning],
            'hazards' => ['Hazard'],
            'personal_protective_equipment' => ['PPE'],
            'storage_instructions' => 'Store safely.',
            'emergency_instructions' => 'Emergency.',
            'age_restrictions' => '18+',
        ], $locale);
    }

    private function fillMinimalSections(string $locale, string $name): void
    {
        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => $name,
            'public_description' => "{$name} description.",
        ], $locale);

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ["{$locale} Warning"],
            'hazards' => ["{$locale} Hazard"],
            'personal_protective_equipment' => ["{$locale} PPE"],
            'storage_instructions' => "{$locale} storage.",
            'emergency_instructions' => "{$locale} emergency.",
            'age_restrictions' => '18+',
        ], $locale);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => "{$locale} recycling.",
            'disposal_instructions' => "{$locale} disposal.",
            'take_back_program' => "{$locale} take back.",
        ], $locale);

        $this->fillSection(DppSectionKey::UsageAndCare, [
            'usage_instructions' => "{$locale} usage.",
            'care_instructions' => "{$locale} care.",
            'maintenance_instructions' => "{$locale} maint.",
            'storage_recommendations' => "{$locale} storage rec.",
        ], $locale);

        $this->fillSection(DppSectionKey::RepairAndSpareParts, [
            'repair_instructions' => "{$locale} repair.",
            'disassembly_instructions' => "{$locale} disassembly.",
            'spare_parts_notes' => "{$locale} spares.",
            'service_information' => "{$locale} service.",
        ], $locale);

        $this->fillSection(DppSectionKey::CertificationsAndDocuments, [
            'certification_notes' => "{$locale} cert.",
            'compliance_summary' => "{$locale} compliant.",
        ], $locale);

        $this->fillSection(DppSectionKey::SupportAndContact, [
            'warranty_summary' => "{$locale} warranty.",
            'support_notes' => "{$locale} support.",
        ], $locale);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => "{$locale} Mfr",
            'responsible_operator_display_name' => "{$locale} Op",
            'contact_notes' => "{$locale} contact.",
        ], $locale);

        $this->fillSection(DppSectionKey::OriginAndTraceability, [
            'traceability_notes' => "{$locale} trace.",
            'batch_identification_instructions' => "{$locale} batch.",
        ], $locale);

        $this->fillSection(DppSectionKey::MaterialsAndComposition, [
            'composition_notes' => "{$locale} composition.",
        ], $locale);

        $this->fillSection(DppSectionKey::EnvironmentalInformation, [
            'environmental_claims' => ["{$locale} claim"],
            'environmental_notes' => "{$locale} env notes.",
        ], $locale);
    }

    private function createAndPublishV1(): void
    {
        $this->passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->passport->setAttribute('enabled_languages', ['sv', 'en']);
        $this->passport->save();

        $this->revision = $this->passport->currentDraftVersion->draft_revision;

        $this->fillMinimalSections('sv', 'Version 1 Swedish');
        $this->fillMinimalSections('en', 'Version 1 English');
        $this->injectManufacturerContact();

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion']);

        $this->revision = $passport->currentDraftVersion->draft_revision;

        $result = app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $this->passport = $result->passport->fresh(['currentDraftVersion', 'currentPublishedVersion']);
        $this->publishedV1 = $this->passport->currentPublishedVersion;
        $this->v1Payload = $this->publishedV1->payload;
        $this->revision = $this->passport->currentDraftVersion->draft_revision;
    }

    private function publish(): PublicationResult
    {
        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion']);

        $this->revision = $passport->currentDraftVersion->draft_revision;

        $result = app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $this->passport = $result->passport->fresh(['currentDraftVersion', 'currentPublishedVersion']);

        return $result;
    }

    public function test_change_english_draft_after_publish_does_not_affect_version_1(): void
    {
        $v1SvName = $this->v1Payload['translations']['sv'][DppSectionKey::Identity->value]['public_name'];
        $v1EnName = $this->v1Payload['translations']['en'][DppSectionKey::Identity->value]['public_name'];

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Changed English Name',
            'public_description' => 'Changed English description.',
        ], 'en');

        $v1 = ProductPassportVersion::query()->find($this->publishedV1->getKey());
        $v1Payload = $v1->payload;

        $this->assertSame(
            $v1SvName,
            $v1Payload['translations']['sv'][DppSectionKey::Identity->value]['public_name'],
        );
        $this->assertSame(
            $v1EnName,
            $v1Payload['translations']['en'][DppSectionKey::Identity->value]['public_name'],
        );
    }

    public function test_change_swedish_draft_after_publish_does_not_affect_version_1(): void
    {
        $v1SvName = $this->v1Payload['translations']['sv'][DppSectionKey::Identity->value]['public_name'];
        $v1EnName = $this->v1Payload['translations']['en'][DppSectionKey::Identity->value]['public_name'];

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Ändrat Svenskt Namn',
            'public_description' => 'Ändrad svensk beskrivning.',
        ], 'sv');

        $v1 = ProductPassportVersion::query()->find($this->publishedV1->getKey());
        $v1Payload = $v1->payload;

        $this->assertSame(
            $v1SvName,
            $v1Payload['translations']['sv'][DppSectionKey::Identity->value]['public_name'],
        );
        $this->assertSame(
            $v1EnName,
            $v1Payload['translations']['en'][DppSectionKey::Identity->value]['public_name'],
        );
    }

    public function test_version_2_with_updated_locale_data(): void
    {
        $this->fillIdentity('en', 'Version 2 English');
        $this->fillIdentity('sv', 'Version 2 Swedish');

        $result = $this->publish();

        $v2Snapshot = $result->publishedVersion->payload;

        $this->assertSame(2, $result->publishedVersion->version_number);

        $this->assertSame(
            'Version 2 English',
            $v2Snapshot['translations']['en'][DppSectionKey::Identity->value]['public_name'],
        );
        $this->assertSame(
            'Version 2 Swedish',
            $v2Snapshot['translations']['sv'][DppSectionKey::Identity->value]['public_name'],
        );

        $v1 = ProductPassportVersion::query()->find($this->publishedV1->getKey());
        $v1Payload = $v1->payload;

        $this->assertSame(
            'Version 1 English',
            $v1Payload['translations']['en'][DppSectionKey::Identity->value]['public_name'],
        );
        $this->assertSame(
            'Version 1 Swedish',
            $v1Payload['translations']['sv'][DppSectionKey::Identity->value]['public_name'],
        );
    }
}
