<?php

namespace Tests\Unit\Passports\Dpp;

use App\Data\Passports\DppFieldDefinition;
use App\Enums\Passports\DppFieldType;
use App\Enums\Passports\DppSectionKey;
use App\Services\Passports\DppPayloadNormalizer;
use App\Services\Passports\DppSchemaRegistry;
use Tests\TestCase;

class DppEditorSchemaParityTest extends TestCase
{
    private DppSchemaRegistry $registry;

    private DppPayloadNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new DppSchemaRegistry;
        $this->normalizer = new DppPayloadNormalizer($this->registry);
    }

    public function test_all_sections_have_correct_field_classification(): void
    {
        $sections = $this->registry->sections();

        foreach ($sections as $sectionKey => $section) {
            $this->assertNotEmpty($sectionKey, 'Section key must not be empty');
            $this->assertInstanceOf(DppSectionKey::class, $section->key);
            $this->assertIsBool($section->translatable);

            foreach ($section->fields as $field) {
                $this->assertNotEmpty($field->key, "Field key must not be empty in section {$sectionKey}");
                $this->assertInstanceOf(DppFieldType::class, $field->type);
                $this->assertInstanceOf(DppSectionKey::class, $field->section);
                $this->assertIsBool($field->translatable, "Field {$field->key} translatable must be a boolean");
            }
        }
    }

    public function test_environmental_information_section_is_translatable(): void
    {
        $section = $this->registry->sections()['environmental_information'];

        $this->assertTrue($section->translatable);
    }

    public function test_origin_and_traceability_is_translatable(): void
    {
        $section = $this->registry->sections()['origin_and_traceability'];

        $this->assertTrue($section->translatable);
    }

    public function test_materials_and_composition_is_translatable(): void
    {
        $section = $this->registry->sections()['materials_and_composition'];

        $this->assertTrue($section->translatable);
    }

    public function test_environmental_claims_and_notes_are_translatable(): void
    {
        $section = $this->registry->sections()['environmental_information'];

        $claimsField = $this->findField($section->fields, 'environmental_claims');
        $notesField = $this->findField($section->fields, 'environmental_notes');

        $this->assertTrue($claimsField->translatable, 'environmental_claims should be translatable');
        $this->assertTrue($notesField->translatable, 'environmental_notes should be translatable');
    }

    public function test_no_duplicate_field_keys_across_sections(): void
    {
        $sections = $this->registry->sections();
        $flat = $this->registry->flatFields();

        $totalFieldCount = 0;
        foreach ($sections as $section) {
            $totalFieldCount += count($section->fields);
        }

        $this->assertCount(
            $totalFieldCount,
            $flat,
            'flatFields() should contain the same number of fields as all sections combined, indicating no duplicate keys',
        );
    }

    public function test_all_registered_fields_normalize_correctly(): void
    {
        $enabledSections = $this->registry->sectionKeysInOrder();

        $data = [
            'manufacturer_and_operator' => [
                'manufacturer_email' => 'mfr@example.com',
                'manufacturer_website' => 'https://example.com',
                'responsible_operator_email' => 'op@example.com',
                'responsible_operator_website' => 'https://operator.example.com',
                'manufacturer_country' => 'se',
                'responsible_operator_country' => 'de',
                'responsible_operator_role' => 'manufacturer',
                'responsible_operator_phone' => '+461234567',
                'responsible_operator_registration_id' => 'SE-5566778899',
                'responsible_operator_source' => 'company',
            ],
            'origin_and_traceability' => [
                'country_of_origin' => 'se',
                'manufacturing_countries' => ['SE', 'DE'],
                'production_date' => '2024-01-01',
            ],
            'materials_and_composition' => [
                'materials' => [
                    ['name' => 'Steel', 'hazardous' => false],
                ],
            ],
            'repair_and_spare_parts' => [
                'repairable' => false,
                'repairability_declaration' => 'Manufacturer-provided repair information',
                'repair_skill_level' => 'basic',
                'estimated_repair_time_minutes' => 30,
                'spare_parts_available' => false,
                'spare_parts_url' => 'https://spares.example.com',
                'spare_parts' => [
                    ['name' => 'Reflector kit', 'availability_status' => 'available'],
                ],
            ],
            'recycling_and_disposal' => [
                'take_back_program_available' => true,
                'take_back_program_url' => 'https://takeback.example.com',
                'waste_material_codes' => ['PET'],
                'recycling_codes' => ['PET', 'PP'],
            ],
            'environmental_information' => [
                'carbon_footprint_kg_co2e' => 0,
                'recycled_content_percentage' => 50.5,
                'recycled_content_calculation_method' => 'declared',
                'recycled_content_source' => 'supplier',
                'expected_lifetime_years' => 0,
                'energy_consumption_kwh' => 0,
                'environmental_metrics' => [
                    ['metric_code' => 'energy_use', 'value' => '12', 'unit' => 'kwh'],
                ],
            ],
            'support_and_contact' => [
                'support_email' => 'support@example.com',
                'support_phone' => '123456789',
                'support_url' => 'https://support.example.com',
                'support_channels' => [
                    ['type' => 'email', 'value' => 'support@example.com'],
                ],
                'warranty_available' => true,
                'warranty_duration' => 24,
                'warranty_duration_unit' => 'months',
                'warranty_url' => 'https://warranty.example.com',
            ],
            'certifications_and_documents' => [
                'compliance_metadata' => [
                    ['topic_code' => 'general_safety', 'statement' => 'Provided by manufacturer'],
                ],
            ],
        ];

        $translations = [
            'sv' => [
                'identity' => [
                    'public_name' => 'Testprodukt',
                    'public_description' => 'Testbeskrivning',
                ],
                'manufacturer_and_operator' => [
                    'manufacturer_display_name' => 'ACME AB',
                    'responsible_operator_display_name' => 'Operator AB',
                    'contact_notes' => 'Kontaktinfo',
                    'responsible_operator_address' => 'Storgatan 1',
                ],
                'origin_and_traceability' => [
                    'traceability_notes' => 'Spårbarhetsnoteringar',
                    'batch_identification_instructions' => 'Batch ID instruktioner',
                ],
                'materials_and_composition' => [
                    'composition_notes' => 'Sammansättningsnoteringar',
                ],
                'safety' => [
                    'warnings' => ['Varning 1', 'Varning 2'],
                    'hazards' => ['Risk 1'],
                    'personal_protective_equipment' => ['Handskar'],
                    'storage_instructions' => 'Förvaringsinstruktioner',
                    'emergency_instructions' => 'Nödinstruktioner',
                    'age_restrictions' => '18+',
                ],
                'usage_and_care' => [
                    'usage_instructions' => 'Användningsinstruktioner',
                    'usage_steps' => ['Steg 1'],
                    'usage_warnings' => ['Varning'],
                    'care_instructions' => 'Skötselråd',
                    'care_steps' => ['Tvätta varsamt'],
                    'care_warnings' => ['Använd inte blekmedel'],
                    'maintenance_instructions' => 'Underhållsinstruktioner',
                    'storage_recommendations' => 'Förvaringsrekommendationer',
                ],
                'repair_and_spare_parts' => [
                    'required_tools' => ['Skruvmejsel'],
                    'repair_instructions' => 'Reparationsinstruktioner',
                    'disassembly_instructions' => 'Demonteringsinstruktioner',
                    'spare_parts_notes' => 'Reservdelsinfo',
                    'service_information' => 'Serviceinfo',
                ],
                'recycling_and_disposal' => [
                    'recycling_instructions' => 'Återvinningsinstruktioner',
                    'disposal_instructions' => 'Avfallshanteringsinstruktioner',
                    'take_back_program' => 'Returprogram',
                    'take_back_program_scope' => 'EU',
                    'disassembly_guidance' => 'Demontera innan sortering',
                    'sorting_guidance' => 'Sortera enligt lokala regler',
                    'hazard_notes' => 'Inga kända faror',
                ],
                'environmental_information' => [
                    'environmental_claims' => ['Miljöpåstående 1'],
                    'environmental_claim_records' => [
                        ['claim_text' => 'Tillverkardeklarerat', 'review_state' => 'provided'],
                    ],
                    'environmental_notes' => 'Miljönoteringar',
                ],
                'certifications_and_documents' => [
                    'certification_notes' => 'Certifieringsnoteringar',
                    'compliance_summary' => 'Regelefterlevnadssammanfattning',
                ],
                'support_and_contact' => [
                    'warranty_summary' => 'Garantisammanfattning',
                    'warranty_conditions' => 'Villkor',
                    'warranty_exclusions' => 'Undantag',
                    'warranty_claim_instructions' => 'Kontakta support',
                    'support_notes' => 'Supportnoteringar',
                ],
            ],
        ];

        $payload = [
            'enabled_sections' => $enabledSections,
            'data' => $data,
            'translations' => $translations,
            'document_references' => [],
        ];

        $normalized = $this->normalizer->normalize($payload);

        $this->assertNonTranslatableFieldsInData($normalized['data']);
        $this->assertTranslatableFieldsInTranslations($normalized['translations']['sv']);
        $this->assertBooleanFalseValuesPreserved($normalized['data']);
        $this->assertZeroNumericValuesPreserved($normalized['data']);
        $this->assertNoFieldsAreLost($normalized);
    }

    private function assertNonTranslatableFieldsInData(array $data): void
    {
        $this->assertSame('mfr@example.com', $data['manufacturer_and_operator']['manufacturer_email']);
        $this->assertSame('https://example.com/', $data['manufacturer_and_operator']['manufacturer_website']);
        $this->assertSame('SE', $data['origin_and_traceability']['country_of_origin']);
        $this->assertSame(['SE', 'DE'], $data['origin_and_traceability']['manufacturing_countries']);
        $this->assertSame('2024-01-01', $data['origin_and_traceability']['production_date']);
        $this->assertCount(1, $data['materials_and_composition']['materials']);
        $this->assertSame('Steel', $data['materials_and_composition']['materials'][0]['name']);
        $this->assertSame('https://spares.example.com/', $data['repair_and_spare_parts']['spare_parts_url']);
        $this->assertSame(['PET', 'PP'], $data['recycling_and_disposal']['recycling_codes']);
        $this->assertSame('50.5', $data['environmental_information']['recycled_content_percentage']);
        $this->assertSame('support@example.com', $data['support_and_contact']['support_email']);
        $this->assertSame('123456789', $data['support_and_contact']['support_phone']);
    }

    private function assertTranslatableFieldsInTranslations(array $translations): void
    {
        $this->assertSame('Testprodukt', $translations['identity']['public_name']);
        $this->assertSame('Testbeskrivning', $translations['identity']['public_description']);
        $this->assertSame('ACME AB', $translations['manufacturer_and_operator']['manufacturer_display_name']);
        $this->assertSame('Kontaktinfo', $translations['manufacturer_and_operator']['contact_notes']);
        $this->assertSame('Spårbarhetsnoteringar', $translations['origin_and_traceability']['traceability_notes']);
        $this->assertSame('Sammansättningsnoteringar', $translations['materials_and_composition']['composition_notes']);
        $this->assertSame(['Varning 1', 'Varning 2'], $translations['safety']['warnings']);
        $this->assertSame(['Risk 1'], $translations['safety']['hazards']);
        $this->assertSame('Förvaringsinstruktioner', $translations['safety']['storage_instructions']);
        $this->assertSame('18+', $translations['safety']['age_restrictions']);
        $this->assertSame('Användningsinstruktioner', $translations['usage_and_care']['usage_instructions']);
        $this->assertSame(['Steg 1'], $translations['usage_and_care']['usage_steps']);
        $this->assertSame('Skötselråd', $translations['usage_and_care']['care_instructions']);
        $this->assertSame('Reparationsinstruktioner', $translations['repair_and_spare_parts']['repair_instructions']);
        $this->assertSame('Reservdelsinfo', $translations['repair_and_spare_parts']['spare_parts_notes']);
        $this->assertSame('Återvinningsinstruktioner', $translations['recycling_and_disposal']['recycling_instructions']);
        $this->assertSame('Avfallshanteringsinstruktioner', $translations['recycling_and_disposal']['disposal_instructions']);
        $this->assertSame(['Miljöpåstående 1'], $translations['environmental_information']['environmental_claims']);
        $this->assertSame('Miljönoteringar', $translations['environmental_information']['environmental_notes']);
        $this->assertSame('Certifieringsnoteringar', $translations['certifications_and_documents']['certification_notes']);
        $this->assertSame('Regelefterlevnadssammanfattning', $translations['certifications_and_documents']['compliance_summary']);
        $this->assertSame('Garantisammanfattning', $translations['support_and_contact']['warranty_summary']);
        $this->assertSame('Supportnoteringar', $translations['support_and_contact']['support_notes']);
    }

    private function assertBooleanFalseValuesPreserved(array $data): void
    {
        $this->assertFalse($data['repair_and_spare_parts']['repairable']);
        $this->assertFalse($data['repair_and_spare_parts']['spare_parts_available']);
    }

    private function assertZeroNumericValuesPreserved(array $data): void
    {
        $this->assertSame('0', $data['environmental_information']['carbon_footprint_kg_co2e']);
        $this->assertSame('0', $data['environmental_information']['expected_lifetime_years']);
        $this->assertSame('0', $data['environmental_information']['energy_consumption_kwh']);
    }

    private function assertNoFieldsAreLost(array $normalized): void
    {
        $sections = $this->registry->sections();

        foreach ($sections as $sectionKey => $section) {
            foreach ($section->fields as $field) {
                $found = false;

                if (isset($normalized['data'][$sectionKey][$field->key])) {
                    $found = true;
                }

                if (
                    ! $found
                    && $section->translatable
                    && $field->translatable
                    && isset($normalized['translations']['sv'][$sectionKey][$field->key])
                ) {
                    $found = true;
                }

                $this->assertTrue(
                    $found,
                    "Field '{$field->key}' in section '{$sectionKey}' was not found in normalized output",
                );
            }
        }
    }

    /**
     * @param  DppFieldDefinition[]  $fields
     */
    private function findField(array $fields, string $key): DppFieldDefinition
    {
        foreach ($fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        $this->fail("Field '{$key}' not found in section fields");
    }
}
