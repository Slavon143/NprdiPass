<?php

namespace Tests\Feature\Passports\Authoring;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Data\Passports\PublicationResult;
use App\Enums\ApiTokenAbility;
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
use App\Services\Passports\CanonicalJsonEncoder;
use App\Services\Passports\DppPayloadValidator;
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
            'responsible_operator_role' => 'manufacturer',
            'responsible_operator_address' => 'Operator Street 1',
            'responsible_operator_phone' => '+46-555-0100',
            'responsible_operator_registration_id' => 'SE-5566778899',
            'responsible_operator_source' => 'company_profile',
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
                [
                    'code' => 'ALU',
                    'type' => 'metal',
                    'name' => 'Aluminium',
                    'percentage' => '60.0',
                    'basis' => 'mass',
                    'recycled_content_percentage' => '30.0',
                    'renewable_content_percentage' => '0',
                    'hazardous' => false,
                    'country_of_origin' => 'SE',
                    'source' => 'supplier declaration',
                    'notes' => 'R3.3 material note.',
                    'sort_order' => 1,
                ],
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
            'usage_steps' => ['Open package', 'Wear visibly'],
            'usage_warnings' => ['Do not modify reflective panels'],
            'care_instructions' => 'Clean daily.',
            'care_steps' => ['Wipe clean', 'Air dry'],
            'care_warnings' => ['Do not bleach'],
            'maintenance_instructions' => 'Maintain monthly.',
            'storage_recommendations' => 'Room temperature.',
        ]);

        $this->saveSection(DppSectionKey::RepairAndSpareParts, [
            'repairable' => true,
            'repairability_declaration' => 'Manufacturer-provided repair information',
            'repair_skill_level' => 'basic',
            'required_tools' => ['Screwdriver'],
            'estimated_repair_time_minutes' => 30,
            'spare_parts_available' => false,
            'spare_parts_url' => 'https://spares.test.com',
            'spare_parts' => [
                ['name' => 'Reflector kit', 'code' => 'SP-R3-01', 'availability_status' => 'available'],
            ],
            'repair_instructions' => 'Repair guide.',
            'disassembly_instructions' => 'Dismantle carefully.',
            'spare_parts_notes' => 'Spares note.',
            'service_information' => 'Service info.',
        ]);

        $this->saveSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Recycle.',
            'disposal_instructions' => 'Dispose.',
            'take_back_program' => 'Take back.',
            'take_back_program_available' => true,
            'take_back_program_url' => 'https://takeback.test.com',
            'take_back_program_scope' => 'EU',
            'disassembly_guidance' => 'Remove straps before recycling.',
            'sorting_guidance' => 'Sort metal separately.',
            'hazard_notes' => 'No hazardous waste declared.',
            'waste_material_codes' => ['ALU'],
            'recycling_codes' => ['PET', 'ALU'],
        ]);

        $this->saveSection(DppSectionKey::EnvironmentalInformation, [
            'carbon_footprint_kg_co2e' => 12.5,
            'recycled_content_percentage' => 30.0,
            'recycled_content_calculation_method' => 'declared',
            'recycled_content_source' => 'supplier declaration',
            'expected_lifetime_years' => 5.0,
            'energy_consumption_kwh' => 100.0,
            'environmental_metrics' => [
                [
                    'metric_code' => 'energy_use',
                    'label' => 'Energy use',
                    'value' => '100',
                    'unit' => 'kwh',
                    'scope' => 'use_phase',
                    'verification_status' => 'provided',
                ],
            ],
            'environmental_claims' => ['Eco-friendly', 'Low carbon'],
            'environmental_claim_records' => [
                ['claim_text' => 'Low carbon', 'claim_type' => 'manufacturer_statement', 'review_state' => 'provided'],
            ],
            'environmental_notes' => 'Environmental notes text.',
        ]);

        $this->saveSection(DppSectionKey::CertificationsAndDocuments, [
            'certification_notes' => 'Cert notes.',
            'compliance_summary' => 'Compliant.',
            'compliance_metadata' => [
                ['topic_code' => 'general_safety', 'statement' => 'Manufacturer supplied metadata'],
            ],
        ]);

        $this->saveSection(DppSectionKey::SupportAndContact, [
            'warranty_summary' => '2 year warranty.',
            'support_email' => 'support@test.com',
            'support_phone' => '+46-123-4567',
            'support_url' => 'https://support.test.com',
            'support_channels' => [
                ['type' => 'email', 'label' => 'Support', 'value' => 'support@test.com'],
            ],
            'warranty_available' => true,
            'warranty_duration' => 24,
            'warranty_duration_unit' => 'months',
            'warranty_url' => 'https://warranty.test.com',
            'warranty_conditions' => 'Warranty conditions.',
            'warranty_exclusions' => 'Warranty exclusions.',
            'warranty_claim_instructions' => 'Contact support.',
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
        $this->assertSame('manufacturer', $data[DppSectionKey::ManufacturerAndOperator->value]['responsible_operator_role']);
        $this->assertSame('Operator Street 1', $translations[DppSectionKey::ManufacturerAndOperator->value]['responsible_operator_address']);

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
        $this->assertSame('ALU', $data[DppSectionKey::MaterialsAndComposition->value]['materials'][0]['code']);
        $this->assertSame('60', $data[DppSectionKey::MaterialsAndComposition->value]['materials'][0]['percentage']);
        $this->assertSame('30', $data[DppSectionKey::MaterialsAndComposition->value]['materials'][0]['recycled_content_percentage']);
        $this->assertSame('supplier declaration', $data[DppSectionKey::MaterialsAndComposition->value]['materials'][0]['source']);
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
        $this->assertSame(['Open package', 'Wear visibly'], $translations[DppSectionKey::UsageAndCare->value]['usage_steps']);
        $this->assertSame('Clean daily.', $translations[DppSectionKey::UsageAndCare->value]['care_instructions']);
        $this->assertSame(['Wipe clean', 'Air dry'], $translations[DppSectionKey::UsageAndCare->value]['care_steps']);
        $this->assertSame('Maintain monthly.', $translations[DppSectionKey::UsageAndCare->value]['maintenance_instructions']);
        $this->assertSame('Room temperature.', $translations[DppSectionKey::UsageAndCare->value]['storage_recommendations']);

        // ── Repair & Spare Parts (mixed) ──
        // Non-translatable
        $this->assertArrayHasKey(DppSectionKey::RepairAndSpareParts->value, $data);
        $this->assertTrue($data[DppSectionKey::RepairAndSpareParts->value]['repairable']);
        $this->assertSame('Manufacturer-provided repair information', $data[DppSectionKey::RepairAndSpareParts->value]['repairability_declaration']);
        $this->assertSame(30, $data[DppSectionKey::RepairAndSpareParts->value]['estimated_repair_time_minutes']);
        $this->assertSame('Reflector kit', $data[DppSectionKey::RepairAndSpareParts->value]['spare_parts'][0]['name']);
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
        $this->assertSame('EU', $translations[DppSectionKey::RecyclingAndDisposal->value]['take_back_program_scope']);
        $this->assertSame('Remove straps before recycling.', $translations[DppSectionKey::RecyclingAndDisposal->value]['disassembly_guidance']);
        $this->assertArrayHasKey(DppSectionKey::RecyclingAndDisposal->value, $data);
        $this->assertTrue($data[DppSectionKey::RecyclingAndDisposal->value]['take_back_program_available']);
        $this->assertSame('https://takeback.test.com/', $data[DppSectionKey::RecyclingAndDisposal->value]['take_back_program_url']);
        $this->assertSame(['ALU'], $data[DppSectionKey::RecyclingAndDisposal->value]['waste_material_codes']);
        $this->assertSame(['PET', 'ALU'], $data[DppSectionKey::RecyclingAndDisposal->value]['recycling_codes']);

        // ── Environmental Information (mixed) ──
        $this->assertArrayHasKey(DppSectionKey::EnvironmentalInformation->value, $data);
        $this->assertSame('12.5', $data[DppSectionKey::EnvironmentalInformation->value]['carbon_footprint_kg_co2e']);
        $this->assertSame('30', $data[DppSectionKey::EnvironmentalInformation->value]['recycled_content_percentage']);
        $this->assertSame('declared', $data[DppSectionKey::EnvironmentalInformation->value]['recycled_content_calculation_method']);
        $this->assertSame('energy_use', $data[DppSectionKey::EnvironmentalInformation->value]['environmental_metrics'][0]['metric_code']);
        $this->assertSame('5', $data[DppSectionKey::EnvironmentalInformation->value]['expected_lifetime_years']);
        $this->assertSame('100', $data[DppSectionKey::EnvironmentalInformation->value]['energy_consumption_kwh']);
        $this->assertArrayHasKey(DppSectionKey::EnvironmentalInformation->value, $translations);
        $this->assertSame(['Eco-friendly', 'Low carbon'], $translations[DppSectionKey::EnvironmentalInformation->value]['environmental_claims']);
        $this->assertSame('Low carbon', $translations[DppSectionKey::EnvironmentalInformation->value]['environmental_claim_records'][0]['claim_text']);
        $this->assertSame('Environmental notes text.', $translations[DppSectionKey::EnvironmentalInformation->value]['environmental_notes']);

        // ── Certifications & Documents (all translatable) ──
        $this->assertArrayHasKey(DppSectionKey::CertificationsAndDocuments->value, $translations);
        $this->assertSame('Cert notes.', $translations[DppSectionKey::CertificationsAndDocuments->value]['certification_notes']);
        $this->assertSame('Compliant.', $translations[DppSectionKey::CertificationsAndDocuments->value]['compliance_summary']);
        $this->assertSame('general_safety', $data[DppSectionKey::CertificationsAndDocuments->value]['compliance_metadata'][0]['topic_code']);

        // ── Support & Contact (mixed) ──
        $this->assertArrayHasKey(DppSectionKey::SupportAndContact->value, $data);
        $this->assertSame('support@test.com', $data[DppSectionKey::SupportAndContact->value]['support_email']);
        $this->assertSame('+46-123-4567', $data[DppSectionKey::SupportAndContact->value]['support_phone']);
        $this->assertSame('https://support.test.com/', $data[DppSectionKey::SupportAndContact->value]['support_url']);
        $this->assertSame('email', $data[DppSectionKey::SupportAndContact->value]['support_channels'][0]['type']);
        $this->assertTrue($data[DppSectionKey::SupportAndContact->value]['warranty_available']);
        $this->assertSame(24, $data[DppSectionKey::SupportAndContact->value]['warranty_duration']);
        $this->assertSame('https://warranty.test.com/', $data[DppSectionKey::SupportAndContact->value]['warranty_url']);
        $this->assertArrayHasKey(DppSectionKey::SupportAndContact->value, $translations);
        $this->assertSame('2 year warranty.', $translations[DppSectionKey::SupportAndContact->value]['warranty_summary']);
        $this->assertSame('Warranty conditions.', $translations[DppSectionKey::SupportAndContact->value]['warranty_conditions']);
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
        $this->assertSame('12.5', $snapshotData[DppSectionKey::EnvironmentalInformation->value]['carbon_footprint_kg_co2e'] ?? null);
        $this->assertSame('energy_use', $snapshotData[DppSectionKey::EnvironmentalInformation->value]['environmental_metrics'][0]['metric_code'] ?? null);
        $this->assertArrayHasKey(DppSectionKey::EnvironmentalInformation->value, $snapshotTranslations);
        $this->assertSame(['Eco-friendly', 'Low carbon'], $snapshotTranslations[DppSectionKey::EnvironmentalInformation->value]['environmental_claims'] ?? null);
        $this->assertSame('Environmental notes text.', $snapshotTranslations[DppSectionKey::EnvironmentalInformation->value]['environmental_notes'] ?? null);
        $this->assertArrayHasKey(DppSectionKey::RepairAndSpareParts->value, $snapshotData);
        $this->assertSame('Reflector kit', $snapshotData[DppSectionKey::RepairAndSpareParts->value]['spare_parts'][0]['name'] ?? null);
        $this->assertFalse($snapshotData[DppSectionKey::RepairAndSpareParts->value]['spare_parts_available'] ?? null);
        $this->assertArrayHasKey(DppSectionKey::SupportAndContact->value, $snapshotData);
        $this->assertSame('support@test.com', $snapshotData[DppSectionKey::SupportAndContact->value]['support_email'] ?? null);
        $this->assertSame('https://support.test.com/', $snapshotData[DppSectionKey::SupportAndContact->value]['support_url'] ?? null);
        $this->assertSame('https://warranty.test.com/', $snapshotData[DppSectionKey::SupportAndContact->value]['warranty_url'] ?? null);
        $this->assertTrue($snapshotData[DppSectionKey::SupportAndContact->value]['warranty_available'] ?? false);
        $this->assertArrayHasKey(DppSectionKey::SupportAndContact->value, $snapshotTranslations);
        $this->assertSame('2 year warranty.', $snapshotTranslations[DppSectionKey::SupportAndContact->value]['warranty_summary'] ?? null);
        $this->assertSame('Support notes.', $snapshotTranslations[DppSectionKey::SupportAndContact->value]['support_notes'] ?? null);

        $publicResponse = $this->get(route('public.passports.show', $this->freshPassport()->public_id));
        $publicResponse->assertOk();
        $publicResponse->assertSee('Environmental Metrics');
        $publicResponse->assertSee('Reflector kit');
        $publicResponse->assertSee('Warranty Duration');
        $publicResponse->assertSee('Compliance Metadata');

        $token = issueCompanyApiToken($this->actor, $this->company, [ApiTokenAbility::PassportsRead->value])->plainTextToken;
        $apiResponse = $this->withToken($token)
            ->getJson("/api/v1/catalog/products/{$this->product->uuid}/passport/versions/{$result->publishedVersion->uuid}");

        $apiResponse->assertOk();
        $apiResponse->assertJsonPath('data.payload.data.environmental_information.environmental_metrics.0.metric_code', 'energy_use');
        $apiResponse->assertJsonPath('data.payload.data.repair_and_spare_parts.spare_parts.0.name', 'Reflector kit');
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

    public function test_r3_2_payload_shape_remains_valid_and_backfill_is_not_applicable(): void
    {
        $r32Payload = [
            'enabled_sections' => [
                DppSectionKey::Identity->value,
                DppSectionKey::ManufacturerAndOperator->value,
                DppSectionKey::Safety->value,
                DppSectionKey::RecyclingAndDisposal->value,
                DppSectionKey::EnvironmentalInformation->value,
            ],
            'data' => [
                DppSectionKey::ManufacturerAndOperator->value => [
                    'manufacturer_email' => 'legacy@example.com',
                    'manufacturer_country' => 'SE',
                ],
                DppSectionKey::EnvironmentalInformation->value => [
                    'carbon_footprint_kg_co2e' => 12.5,
                    'recycled_content_percentage' => 30,
                ],
            ],
            'translations' => [
                $this->locale => [
                    DppSectionKey::Identity->value => [
                        'public_name' => 'R3.2 Legacy Product',
                        'public_description' => 'Legacy shape without advanced optional fields.',
                    ],
                    DppSectionKey::ManufacturerAndOperator->value => [
                        'manufacturer_display_name' => 'Legacy Manufacturer',
                    ],
                    DppSectionKey::Safety->value => [
                        'warnings' => ['Legacy warning'],
                    ],
                    DppSectionKey::RecyclingAndDisposal->value => [
                        'recycling_instructions' => 'Legacy recycling.',
                    ],
                ],
            ],
            'document_references' => [],
        ];

        $normalized = app(DppPayloadValidator::class)->validateFullPayload($r32Payload, $this->company, $this->passport);

        $this->assertSame('12.5', $normalized['data'][DppSectionKey::EnvironmentalInformation->value]['carbon_footprint_kg_co2e']);
        $this->assertArrayNotHasKey('environmental_metrics', $normalized['data'][DppSectionKey::EnvironmentalInformation->value]);
        $this->assertArrayNotHasKey('environmental_claim_records', $normalized['translations'][$this->locale][DppSectionKey::EnvironmentalInformation->value] ?? []);
        $this->assertArrayNotHasKey('support_channels', $normalized['data'][DppSectionKey::SupportAndContact->value] ?? []);
        $this->assertArrayNotHasKey('compliance_metadata', $normalized['data'][DppSectionKey::CertificationsAndDocuments->value] ?? []);

        $draft = $this->passport->currentDraftVersion;
        $beforeRevision = $draft->draft_revision;

        $draft->forceFill(['payload' => $normalized])->save();
        $draft->refresh();

        $this->assertSame($beforeRevision, $draft->draft_revision);
        $this->assertArrayNotHasKey('environmental_metrics', $draft->payload['data'][DppSectionKey::EnvironmentalInformation->value]);
    }

    public function test_advanced_version_one_snapshot_remains_immutable_after_version_two(): void
    {
        $this->saveAllSections();

        $versionOneResult = $this->publish($this->freshPassport(), $this->revision);
        $versionOne = $versionOneResult->publishedVersion->fresh();
        $versionOnePayload = $versionOne->payload;
        $versionOneHash = app(CanonicalJsonEncoder::class)->hash($versionOnePayload);
        $this->revision = $versionOneResult->newDraftVersion->draft_revision;

        $this->saveSection(DppSectionKey::MaterialsAndComposition, [
            'materials' => [
                ['name' => 'Recycled Polyester', 'percentage' => '100', 'recycled_content_percentage' => '80', 'hazardous' => false],
            ],
            'composition_notes' => 'Version two material.',
        ]);
        $this->saveSection(DppSectionKey::EnvironmentalInformation, [
            'environmental_metrics' => [
                ['metric_code' => 'water_use', 'value' => '8', 'unit' => 'l'],
            ],
        ]);
        $this->saveSection(DppSectionKey::RepairAndSpareParts, [
            'repairable' => false,
            'repairability_declaration' => 'Manufacturer-provided repair information',
        ]);
        $this->saveSection(DppSectionKey::SupportAndContact, [
            'warranty_available' => false,
            'support_email' => 'v2-support@test.com',
        ]);
        $this->saveSection(DppSectionKey::ManufacturerAndOperator, [
            'responsible_operator_display_name' => 'Version Two Operator',
            'responsible_operator_email' => 'v2-operator@test.com',
        ]);

        $versionTwoResult = $this->publish($this->freshPassport(), $this->revision);
        $versionOneAfter = $versionOne->fresh();
        $versionTwo = $versionTwoResult->publishedVersion->fresh();

        $this->assertSame($versionOnePayload, $versionOneAfter->payload);
        $this->assertSame($versionOneHash, app(CanonicalJsonEncoder::class)->hash($versionOneAfter->payload));
        $this->assertNotSame($versionOneHash, app(CanonicalJsonEncoder::class)->hash($versionTwo->payload));
        $this->assertSame('Aluminium', $versionOneAfter->payload['data'][DppSectionKey::MaterialsAndComposition->value]['materials'][0]['name']);
        $this->assertSame('Recycled Polyester', $versionTwo->payload['data'][DppSectionKey::MaterialsAndComposition->value]['materials'][0]['name']);
        $this->assertSame('Test Op', $versionOneAfter->payload['translations'][$this->locale][DppSectionKey::ManufacturerAndOperator->value]['responsible_operator_display_name']);
        $this->assertSame('Version Two Operator', $versionTwo->payload['translations'][$this->locale][DppSectionKey::ManufacturerAndOperator->value]['responsible_operator_display_name']);
    }
}
