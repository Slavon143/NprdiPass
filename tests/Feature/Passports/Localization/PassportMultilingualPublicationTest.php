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
use App\Models\User;
use App\Services\Passports\DppPayloadNormalizer;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PassportMultilingualPublicationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    private Category $category;

    private ProductVariant $defaultVariant;

    private ProductMedia $primaryMedia;

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
        Storage::disk('catalog_media')->put('test/multilingual.jpg', 'fake content');

        $this->category = new Category;
        $this->category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Multilingual Pub Category',
            'slug' => 'multilingual-pub-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'multilingual-pub-cat-'.fake()->unique()->slug(1),
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
            'name' => 'Multilingual Pub Product '.fake()->unique()->word(),
            'slug' => 'multilingual-pub-'.fake()->unique()->slug(1),
            'slug_normalized' => 'multilingual-pub-'.fake()->unique()->slug(1),
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
            'sku' => 'SKU-ML-001',
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
            'original_filename' => 'multilingual.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/multilingual.jpg',
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

        $this->passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->passport->setAttribute('enabled_languages', ['sv', 'en']);
        $this->passport->save();

        $this->revision = $this->passport->currentDraftVersion->draft_revision;
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

    private function fillAllTranslations(string $locale, string $publicName): void
    {
        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => $publicName,
            'public_description' => "{$publicName} description.",
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

    public function test_publish_with_english_and_swedish(): void
    {
        $this->fillAllTranslations('sv', 'Svensk Produkt');
        $this->fillAllTranslations('en', 'English Product');
        $this->injectManufacturerContact();

        $result = $this->publish();

        $this->assertNotNull($result->publishedVersion);
        $this->assertNotNull($this->passport->first_published_at);
        $this->assertNotEmpty($this->passport->public_id);
    }

    public function test_snapshot_contains_both_locales(): void
    {
        $this->fillAllTranslations('sv', 'Svensk Produkt');
        $this->fillAllTranslations('en', 'English Product');
        $this->injectManufacturerContact();

        $result = $this->publish();
        $snapshot = $result->publishedVersion->payload;

        $this->assertArrayHasKey('translations', $snapshot);
        $this->assertArrayHasKey('sv', $snapshot['translations']);
        $this->assertArrayHasKey('en', $snapshot['translations']);

        $this->assertSame(
            'Svensk Produkt',
            $snapshot['translations']['sv'][DppSectionKey::Identity->value]['public_name'],
        );
        $this->assertSame(
            'English Product',
            $snapshot['translations']['en'][DppSectionKey::Identity->value]['public_name'],
        );
    }

    public function test_snapshot_contains_shared_data_once(): void
    {
        $this->fillAllTranslations('sv', 'Svensk Produkt');
        $this->fillAllTranslations('en', 'English Product');
        $this->injectManufacturerContact();

        $result = $this->publish();
        $snapshot = $result->publishedVersion->payload;

        $this->assertArrayHasKey('data', $snapshot);
        $this->assertArrayHasKey(DppSectionKey::ManufacturerAndOperator->value, $snapshot['data']);
        $this->assertSame(
            'contact@example.com',
            $snapshot['data'][DppSectionKey::ManufacturerAndOperator->value]['manufacturer_email'],
        );
    }

    public function test_one_public_id_one_qr(): void
    {
        $this->fillAllTranslations('sv', 'Svensk Produkt');
        $this->fillAllTranslations('en', 'English Product');
        $this->injectManufacturerContact();

        $result = $this->publish();

        $this->assertNotEmpty($this->passport->public_id);

        $this->get("/p/{$this->passport->public_id}")->assertOk();
    }
}
