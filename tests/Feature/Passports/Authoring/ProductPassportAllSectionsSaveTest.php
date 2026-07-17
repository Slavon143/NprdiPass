<?php

namespace Tests\Feature\Passports\Authoring;

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
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductPassportAllSectionsSaveTest extends TestCase
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

    private string $locale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locale = config('passports.default_language', 'sv');

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
        Storage::disk('catalog_media')->put('test/all-sections.jpg', 'fake content');

        $this->category = new Category;
        $this->category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'All Sections Category',
            'slug' => 'all-sections-category-'.fake()->unique()->slug(1),
            'slug_normalized' => 'all-sections-category-'.fake()->unique()->slug(1),
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
            'name' => 'All Sections Test Product '.fake()->unique()->word(),
            'slug' => 'all-sections-test-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'all-sections-test-product-'.fake()->unique()->slug(1),
            'brand' => 'All Sections Brand',
            'manufacturer' => 'All Sections Manufacturer',
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
            'sku' => 'SKU-AS-001',
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
            'original_filename' => 'all-sections.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/all-sections.jpg',
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

        $this->revision = $this->passport->currentDraftVersion->draft_revision;
    }

    private function saveSection(DppSectionKey $section, array $payload): ProductPassport
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

    private function freshPassport(): ProductPassport
    {
        return ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion', 'currentPublishedVersion']);
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

    private function saveAllSections(): void
    {
        $this->saveSection(DppSectionKey::Identity, [
            'public_name' => 'Test Product Name',
            'public_description' => 'Test description.',
        ]);

        $this->saveSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Test Mfr',
            'responsible_operator_display_name' => 'Test Op',
            'contact_notes' => 'Contact notes.',
            'manufacturer_email' => 'mfr@test.com',
            'manufacturer_website' => 'https://mfr.test.com',
            'responsible_operator_email' => 'op@test.com',
            'responsible_operator_website' => 'https://op.test.com',
            'manufacturer_country' => 'SE',
            'responsible_operator_country' => 'DE',
        ]);

        $this->saveSection(DppSectionKey::OriginAndTraceability, [
            'country_of_origin' => 'DE',
            'manufacturing_countries' => ['DE', 'PL'],
            'production_date' => '2026-01-15',
            'traceability_notes' => 'Trace notes.',
            'batch_identification_instructions' => 'Batch ID instructions.',
        ]);

        $this->saveSection(DppSectionKey::MaterialsAndComposition, [
            'materials' => [
                ['name' => 'Aluminium', 'percentage' => 60.0, 'recycled_content_percentage' => 30.0, 'hazardous' => false],
            ],
            'composition_notes' => 'Composition notes.',
        ]);

        $this->saveSection(DppSectionKey::Safety, [
            'warnings' => ['Warning A'],
            'hazards' => ['Hazard B'],
            'personal_protective_equipment' => ['Gloves'],
            'storage_instructions' => 'Store dry.',
            'emergency_instructions' => 'Call 112.',
            'age_restrictions' => '18+',
        ]);

        $this->saveSection(DppSectionKey::UsageAndCare, [
            'usage_instructions' => 'Use carefully.',
            'care_instructions' => 'Clean daily.',
            'maintenance_instructions' => 'Maintain monthly.',
            'storage_recommendations' => 'Room temperature.',
        ]);

        $this->saveSection(DppSectionKey::RepairAndSpareParts, [
            'repairable' => true,
            'spare_parts_available' => false,
            'spare_parts_url' => 'https://spares.test.com',
            'repair_instructions' => 'Repair guide.',
            'disassembly_instructions' => 'Dismantle carefully.',
            'spare_parts_notes' => 'Spares note.',
            'service_information' => 'Service info.',
        ]);

        $this->saveSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Recycle.',
            'disposal_instructions' => 'Dispose.',
            'take_back_program' => 'Take back.',
            'recycling_codes' => ['PET', 'ALU'],
        ]);

        $this->saveSection(DppSectionKey::EnvironmentalInformation, [
            'carbon_footprint_kg_co2e' => 12.5,
            'recycled_content_percentage' => 30.0,
            'expected_lifetime_years' => 5.0,
            'energy_consumption_kwh' => 100.0,
            'environmental_claims' => ['Eco-friendly', 'Low carbon'],
            'environmental_notes' => 'Environmental notes text.',
        ]);

        $this->saveSection(DppSectionKey::CertificationsAndDocuments, [
            'certification_notes' => 'Cert notes.',
            'compliance_summary' => 'Compliant.',
        ]);

        $this->saveSection(DppSectionKey::SupportAndContact, [
            'warranty_summary' => '2 year warranty.',
            'support_email' => 'support@test.com',
            'support_phone' => '+46-123-4567',
            'support_url' => 'https://support.test.com',
            'warranty_url' => 'https://warranty.test.com',
            'support_notes' => 'Support notes.',
        ]);
    }

    public function test_all_11_sections_save_and_reload(): void
    {
        $this->saveAllSections();

        $passport = $this->freshPassport();
        $payload = $passport->currentDraftVersion->payload;

        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey('translations', $payload);
        $this->assertArrayHasKey($this->locale, $payload['translations']);

        $data = $payload['data'];
        $translations = $payload['translations'][$this->locale];

        // ── Identity (all translatable) ──
        $this->assertArrayHasKey(DppSectionKey::Identity->value, $translations);
        $this->assertSame('Test Product Name', $translations[DppSectionKey::Identity->value]['public_name']);
        $this->assertSame('Test description.', $translations[DppSectionKey::Identity->value]['public_description']);

        // ── Manufacturer & Operator (mixed) ──
        // Translatable
        $this->assertArrayHasKey(DppSectionKey::ManufacturerAndOperator->value, $translations);
        $this->assertSame('Test Mfr', $translations[DppSectionKey::ManufacturerAndOperator->value]['manufacturer_display_name']);
        $this->assertSame('Test Op', $translations[DppSectionKey::ManufacturerAndOperator->value]['responsible_operator_display_name']);
        $this->assertSame('Contact notes.', $translations[DppSectionKey::ManufacturerAndOperator->value]['contact_notes']);
        // Non-translatable
        $this->assertArrayHasKey(DppSectionKey::ManufacturerAndOperator->value, $data);
        $this->assertSame('mfr@test.com', $data[DppSectionKey::ManufacturerAndOperator->value]['manufacturer_email']);
        $this->assertSame('https://mfr.test.com/', $data[DppSectionKey::ManufacturerAndOperator->value]['manufacturer_website']);
        $this->assertSame('op@test.com', $data[DppSectionKey::ManufacturerAndOperator->value]['responsible_operator_email']);
        $this->assertSame('https://op.test.com/', $data[DppSectionKey::ManufacturerAndOperator->value]['responsible_operator_website']);
        $this->assertSame('SE', $data[DppSectionKey::ManufacturerAndOperator->value]['manufacturer_country']);
        $this->assertSame('DE', $data[DppSectionKey::ManufacturerAndOperator->value]['responsible_operator_country']);

        // ── Origin & Traceability (mixed) ──
        // Non-translatable
        $this->assertArrayHasKey(DppSectionKey::OriginAndTraceability->value, $data);
        $this->assertSame('DE', $data[DppSectionKey::OriginAndTraceability->value]['country_of_origin']);
        $this->assertSame(['DE', 'PL'], $data[DppSectionKey::OriginAndTraceability->value]['manufacturing_countries']);
        $this->assertSame('2026-01-15', $data[DppSectionKey::OriginAndTraceability->value]['production_date']);
        // Translatable
        $this->assertArrayHasKey(DppSectionKey::OriginAndTraceability->value, $translations);
        $this->assertSame('Trace notes.', $translations[DppSectionKey::OriginAndTraceability->value]['traceability_notes']);
        $this->assertSame('Batch ID instructions.', $translations[DppSectionKey::OriginAndTraceability->value]['batch_identification_instructions']);

        // ── Materials & Composition (mixed) ──
        $this->assertArrayHasKey(DppSectionKey::MaterialsAndComposition->value, $data);
        $this->assertIsArray($data[DppSectionKey::MaterialsAndComposition->value]['materials']);
        $this->assertCount(1, $data[DppSectionKey::MaterialsAndComposition->value]['materials']);
        $this->assertSame('Aluminium', $data[DppSectionKey::MaterialsAndComposition->value]['materials'][0]['name']);
        $this->assertSame(60, $data[DppSectionKey::MaterialsAndComposition->value]['materials'][0]['percentage']);
        $this->assertSame(30, $data[DppSectionKey::MaterialsAndComposition->value]['materials'][0]['recycled_content_percentage']);
        $this->assertArrayHasKey(DppSectionKey::MaterialsAndComposition->value, $translations);
        $this->assertSame('Composition notes.', $translations[DppSectionKey::MaterialsAndComposition->value]['composition_notes']);

        // ── Safety (all translatable) ──
        $this->assertArrayHasKey(DppSectionKey::Safety->value, $translations);
        $this->assertSame(['Warning A'], $translations[DppSectionKey::Safety->value]['warnings']);
        $this->assertSame(['Hazard B'], $translations[DppSectionKey::Safety->value]['hazards']);
        $this->assertSame(['Gloves'], $translations[DppSectionKey::Safety->value]['personal_protective_equipment']);
        $this->assertSame('Store dry.', $translations[DppSectionKey::Safety->value]['storage_instructions']);
        $this->assertSame('Call 112.', $translations[DppSectionKey::Safety->value]['emergency_instructions']);
        $this->assertSame('18+', $translations[DppSectionKey::Safety->value]['age_restrictions']);

        // ── Usage & Care (all translatable) ──
        $this->assertArrayHasKey(DppSectionKey::UsageAndCare->value, $translations);
        $this->assertSame('Use carefully.', $translations[DppSectionKey::UsageAndCare->value]['usage_instructions']);
        $this->assertSame('Clean daily.', $translations[DppSectionKey::UsageAndCare->value]['care_instructions']);
        $this->assertSame('Maintain monthly.', $translations[DppSectionKey::UsageAndCare->value]['maintenance_instructions']);
        $this->assertSame('Room temperature.', $translations[DppSectionKey::UsageAndCare->value]['storage_recommendations']);

        // ── Repair & Spare Parts (mixed) ──
        // Non-translatable
        $this->assertArrayHasKey(DppSectionKey::RepairAndSpareParts->value, $data);
        $this->assertTrue($data[DppSectionKey::RepairAndSpareParts->value]['repairable']);
        $this->assertFalse($data[DppSectionKey::RepairAndSpareParts->value]['spare_parts_available']);
        $this->assertSame('https://spares.test.com/', $data[DppSectionKey::RepairAndSpareParts->value]['spare_parts_url']);
        // Translatable
        $this->assertArrayHasKey(DppSectionKey::RepairAndSpareParts->value, $translations);
        $this->assertSame('Repair guide.', $translations[DppSectionKey::RepairAndSpareParts->value]['repair_instructions']);
        $this->assertSame('Dismantle carefully.', $translations[DppSectionKey::RepairAndSpareParts->value]['disassembly_instructions']);
        $this->assertSame('Spares note.', $translations[DppSectionKey::RepairAndSpareParts->value]['spare_parts_notes']);
        $this->assertSame('Service info.', $translations[DppSectionKey::RepairAndSpareParts->value]['service_information']);

        // ── Recycling & Disposal (mixed) ──
        $this->assertArrayHasKey(DppSectionKey::RecyclingAndDisposal->value, $translations);
        $this->assertSame('Recycle.', $translations[DppSectionKey::RecyclingAndDisposal->value]['recycling_instructions']);
        $this->assertSame('Dispose.', $translations[DppSectionKey::RecyclingAndDisposal->value]['disposal_instructions']);
        $this->assertSame('Take back.', $translations[DppSectionKey::RecyclingAndDisposal->value]['take_back_program']);
        $this->assertArrayHasKey(DppSectionKey::RecyclingAndDisposal->value, $data);
        $this->assertSame(['PET', 'ALU'], $data[DppSectionKey::RecyclingAndDisposal->value]['recycling_codes']);

        // ── Environmental Information (mixed) ──
        $this->assertArrayHasKey(DppSectionKey::EnvironmentalInformation->value, $data);
        $this->assertSame(12.5, $data[DppSectionKey::EnvironmentalInformation->value]['carbon_footprint_kg_co2e']);
        $this->assertSame(30, $data[DppSectionKey::EnvironmentalInformation->value]['recycled_content_percentage']);
        $this->assertSame(5, $data[DppSectionKey::EnvironmentalInformation->value]['expected_lifetime_years']);
        $this->assertSame(100, $data[DppSectionKey::EnvironmentalInformation->value]['energy_consumption_kwh']);
        $this->assertArrayHasKey(DppSectionKey::EnvironmentalInformation->value, $translations);
        $this->assertSame(['Eco-friendly', 'Low carbon'], $translations[DppSectionKey::EnvironmentalInformation->value]['environmental_claims']);
        $this->assertSame('Environmental notes text.', $translations[DppSectionKey::EnvironmentalInformation->value]['environmental_notes']);

        // ── Certifications & Documents (all translatable) ──
        $this->assertArrayHasKey(DppSectionKey::CertificationsAndDocuments->value, $translations);
        $this->assertSame('Cert notes.', $translations[DppSectionKey::CertificationsAndDocuments->value]['certification_notes']);
        $this->assertSame('Compliant.', $translations[DppSectionKey::CertificationsAndDocuments->value]['compliance_summary']);

        // ── Support & Contact (mixed) ──
        $this->assertArrayHasKey(DppSectionKey::SupportAndContact->value, $data);
        $this->assertSame('support@test.com', $data[DppSectionKey::SupportAndContact->value]['support_email']);
        $this->assertSame('+46-123-4567', $data[DppSectionKey::SupportAndContact->value]['support_phone']);
        $this->assertSame('https://support.test.com/', $data[DppSectionKey::SupportAndContact->value]['support_url']);
        $this->assertSame('https://warranty.test.com/', $data[DppSectionKey::SupportAndContact->value]['warranty_url']);
        $this->assertArrayHasKey(DppSectionKey::SupportAndContact->value, $translations);
        $this->assertSame('2 year warranty.', $translations[DppSectionKey::SupportAndContact->value]['warranty_summary']);
        $this->assertSame('Support notes.', $translations[DppSectionKey::SupportAndContact->value]['support_notes']);

        // ── Boolean false is preserved ──
        $this->assertFalse($data[DppSectionKey::RepairAndSpareParts->value]['spare_parts_available']);

        // ── Environmental claims & notes are in translations, not data ──
        $this->assertArrayNotHasKey('environmental_claims', $data[DppSectionKey::EnvironmentalInformation->value] ?? []);
        $this->assertArrayNotHasKey('environmental_notes', $data[DppSectionKey::EnvironmentalInformation->value] ?? []);

        // ── Revision is correct after all 11 saves ──
        $this->assertSame(12, $passport->currentDraftVersion->draft_revision);

        // ── Publish and verify snapshot contains all values ──
        $result = $this->publish($passport, $this->revision);
        $snapshot = $result->publishedVersion->payload;

        $this->assertNotNull($snapshot);

        $this->assertArrayHasKey('data', $snapshot);
        $this->assertArrayHasKey('translations', $snapshot);

        $snapshotData = $snapshot['data'];
        $snapshotTranslations = $snapshot['translations'][$this->locale];

        $this->assertArrayHasKey(DppSectionKey::Identity->value, $snapshotTranslations);
        $this->assertSame('Test Product Name', $snapshotTranslations[DppSectionKey::Identity->value]['public_name'] ?? null);
        $this->assertArrayHasKey(DppSectionKey::ManufacturerAndOperator->value, $snapshotTranslations);
        $this->assertSame('Test Mfr', $snapshotTranslations[DppSectionKey::ManufacturerAndOperator->value]['manufacturer_display_name'] ?? null);
        $this->assertArrayHasKey(DppSectionKey::ManufacturerAndOperator->value, $snapshotData);
        $this->assertSame('mfr@test.com', $snapshotData[DppSectionKey::ManufacturerAndOperator->value]['manufacturer_email'] ?? null);
        $this->assertSame('https://mfr.test.com/', $snapshotData[DppSectionKey::ManufacturerAndOperator->value]['manufacturer_website'] ?? null);
        $this->assertArrayHasKey(DppSectionKey::EnvironmentalInformation->value, $snapshotData);
        $this->assertSame(12.5, $snapshotData[DppSectionKey::EnvironmentalInformation->value]['carbon_footprint_kg_co2e'] ?? null);
        $this->assertArrayHasKey(DppSectionKey::EnvironmentalInformation->value, $snapshotTranslations);
        $this->assertSame(['Eco-friendly', 'Low carbon'], $snapshotTranslations[DppSectionKey::EnvironmentalInformation->value]['environmental_claims'] ?? null);
        $this->assertSame('Environmental notes text.', $snapshotTranslations[DppSectionKey::EnvironmentalInformation->value]['environmental_notes'] ?? null);
        $this->assertArrayHasKey(DppSectionKey::RepairAndSpareParts->value, $snapshotData);
        $this->assertFalse($snapshotData[DppSectionKey::RepairAndSpareParts->value]['spare_parts_available'] ?? null);
        $this->assertArrayHasKey(DppSectionKey::SupportAndContact->value, $snapshotData);
        $this->assertSame('support@test.com', $snapshotData[DppSectionKey::SupportAndContact->value]['support_email'] ?? null);
        $this->assertSame('https://support.test.com/', $snapshotData[DppSectionKey::SupportAndContact->value]['support_url'] ?? null);
        $this->assertSame('https://warranty.test.com/', $snapshotData[DppSectionKey::SupportAndContact->value]['warranty_url'] ?? null);
        $this->assertArrayHasKey(DppSectionKey::SupportAndContact->value, $snapshotTranslations);
        $this->assertSame('2 year warranty.', $snapshotTranslations[DppSectionKey::SupportAndContact->value]['warranty_summary'] ?? null);
        $this->assertSame('Support notes.', $snapshotTranslations[DppSectionKey::SupportAndContact->value]['support_notes'] ?? null);
    }

    public function test_boolean_false_is_preserved(): void
    {
        $this->saveSection(DppSectionKey::Identity, [
            'public_name' => 'Test Product',
            'public_description' => 'Test description.',
        ]);

        $this->saveSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Test Mfr',
            'responsible_operator_display_name' => 'Test Op',
            'contact_notes' => 'Contact notes.',
            'manufacturer_email' => 'mfr@test.com',
            'manufacturer_country' => 'SE',
        ]);

        $this->saveSection(DppSectionKey::Safety, [
            'warnings' => ['Warning A'],
            'hazards' => ['Hazard B'],
            'personal_protective_equipment' => ['Gloves'],
            'storage_instructions' => 'Store dry.',
            'emergency_instructions' => 'Call 112.',
            'age_restrictions' => '18+',
        ]);

        $this->saveSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Recycle.',
            'disposal_instructions' => 'Dispose.',
            'take_back_program' => 'Take back.',
            'recycling_codes' => ['PET', 'ALU'],
        ]);

        $this->saveSection(DppSectionKey::MaterialsAndComposition, [
            'materials' => [
                ['name' => 'Plastic', 'percentage' => 100.0, 'recycled_content_percentage' => 30.0, 'hazardous' => false],
            ],
        ]);

        $this->saveSection(DppSectionKey::EnvironmentalInformation, [
            'carbon_footprint_kg_co2e' => 5.0,
            'environmental_claims' => ['Eco-friendly'],
            'environmental_notes' => 'Notes.',
        ]);

        $this->saveSection(DppSectionKey::UsageAndCare, [
            'usage_instructions' => 'Use carefully.',
            'care_instructions' => 'Clean daily.',
        ]);

        $this->saveSection(DppSectionKey::SupportAndContact, [
            'warranty_summary' => '2 year warranty.',
            'support_email' => 'support@test.com',
        ]);

        $this->saveSection(DppSectionKey::RepairAndSpareParts, [
            'repairable' => true,
            'spare_parts_available' => false,
            'spare_parts_url' => 'https://spares.test.com',
            'repair_instructions' => 'Repair guide.',
        ]);

        $passport = $this->freshPassport();
        $payload = $passport->currentDraftVersion->payload;

        $this->assertArrayHasKey(DppSectionKey::RepairAndSpareParts->value, $payload['data']);
        $this->assertFalse($payload['data'][DppSectionKey::RepairAndSpareParts->value]['spare_parts_available']);
        $this->assertSame('https://spares.test.com/', $payload['data'][DppSectionKey::RepairAndSpareParts->value]['spare_parts_url']);

        $result = $this->publish($passport, $this->revision);
        $snapshot = $result->publishedVersion->payload;

        $this->assertFalse($snapshot['data'][DppSectionKey::RepairAndSpareParts->value]['spare_parts_available'] ?? null);
    }

    public function test_environmental_claims_saved_as_translatable(): void
    {
        $this->saveSection(DppSectionKey::EnvironmentalInformation, [
            'carbon_footprint_kg_co2e' => 5.0,
            'environmental_claims' => ['Claim 1', 'Claim 2'],
        ]);

        $passport = $this->freshPassport();
        $payload = $passport->currentDraftVersion->payload;

        $this->assertArrayHasKey(
            DppSectionKey::EnvironmentalInformation->value,
            $payload['translations'][$this->locale],
        );
        $this->assertArrayHasKey(
            'environmental_claims',
            $payload['translations'][$this->locale][DppSectionKey::EnvironmentalInformation->value],
        );
        $this->assertSame(
            ['Claim 1', 'Claim 2'],
            $payload['translations'][$this->locale][DppSectionKey::EnvironmentalInformation->value]['environmental_claims'],
        );

        $this->assertArrayNotHasKey(
            'environmental_claims',
            $payload['data'][DppSectionKey::EnvironmentalInformation->value] ?? [],
        );
    }

    public function test_environmental_notes_saved_as_translatable(): void
    {
        $this->saveSection(DppSectionKey::EnvironmentalInformation, [
            'carbon_footprint_kg_co2e' => 5.0,
            'environmental_notes' => 'Test notes.',
        ]);

        $passport = $this->freshPassport();
        $payload = $passport->currentDraftVersion->payload;

        $this->assertArrayHasKey(
            DppSectionKey::EnvironmentalInformation->value,
            $payload['translations'][$this->locale],
        );
        $this->assertArrayHasKey(
            'environmental_notes',
            $payload['translations'][$this->locale][DppSectionKey::EnvironmentalInformation->value],
        );
        $this->assertSame(
            'Test notes.',
            $payload['translations'][$this->locale][DppSectionKey::EnvironmentalInformation->value]['environmental_notes'],
        );

        $this->assertArrayNotHasKey(
            'environmental_notes',
            $payload['data'][DppSectionKey::EnvironmentalInformation->value] ?? [],
        );
    }
}
