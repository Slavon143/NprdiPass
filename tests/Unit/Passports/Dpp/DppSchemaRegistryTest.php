<?php

namespace Tests\Unit\Passports\Dpp;

use App\Data\Passports\DppFieldDefinition;
use App\Data\Passports\DppSectionDefinition;
use App\Enums\Passports\DppFieldType;
use App\Enums\Passports\DppSectionKey;
use App\Services\Passports\DppSchemaRegistry;
use Tests\TestCase;

class DppSchemaRegistryTest extends TestCase
{
    private DppSchemaRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new DppSchemaRegistry;
    }

    public function test_all_11_sections_exist(): void
    {
        $sections = $this->registry->sections();

        $this->assertCount(11, $sections);
        $this->assertCount(11, DppSectionKey::cases());
    }

    public function test_4_core_sections(): void
    {
        $coreSections = array_filter(
            $this->registry->sections(),
            fn (DppSectionDefinition $s) => $s->core,
        );

        $this->assertCount(4, $coreSections);

        $coreKeys = array_map(fn (DppSectionDefinition $s) => $s->key->value, $coreSections);

        $this->assertContains('identity', $coreKeys);
        $this->assertContains('manufacturer_and_operator', $coreKeys);
        $this->assertContains('safety', $coreKeys);
        $this->assertContains('recycling_and_disposal', $coreKeys);
    }

    public function test_7_optional_sections(): void
    {
        $optionalSections = array_filter(
            $this->registry->sections(),
            fn (DppSectionDefinition $s) => ! $s->core,
        );

        $this->assertCount(7, $optionalSections);

        $optionalKeys = array_map(fn (DppSectionDefinition $s) => $s->key->value, $optionalSections);

        $this->assertContains('origin_and_traceability', $optionalKeys);
        $this->assertContains('materials_and_composition', $optionalKeys);
        $this->assertContains('usage_and_care', $optionalKeys);
        $this->assertContains('repair_and_spare_parts', $optionalKeys);
        $this->assertContains('environmental_information', $optionalKeys);
        $this->assertContains('certifications_and_documents', $optionalKeys);
        $this->assertContains('support_and_contact', $optionalKeys);
    }

    public function test_section_order_is_deterministic(): void
    {
        $first = array_keys($this->registry->sections());
        $second = array_keys($this->registry->sections());

        $this->assertSame($first, $second);
    }

    public function test_sections_are_in_enum_order(): void
    {
        $sectionKeys = array_keys($this->registry->sections());
        $enumValues = array_map(fn (DppSectionKey $k) => $k->value, DppSectionKey::cases());

        $this->assertSame($enumValues, $sectionKeys);
    }

    public function test_identity_section_has_public_name_field(): void
    {
        $section = $this->registry->sections()['identity'];

        $this->assertCount(2, $section->fields);

        $nameField = $section->fields[0];
        $this->assertSame('public_name', $nameField->key);
        $this->assertSame(DppFieldType::ShortText, $nameField->type);
        $this->assertTrue($nameField->translatable);
        $this->assertTrue($nameField->nullable);
        $this->assertSame(500, $nameField->maxLength);
        $this->assertSame(DppSectionKey::Identity, $nameField->section);
    }

    public function test_identity_section_has_public_description_field(): void
    {
        $section = $this->registry->sections()['identity'];
        $descField = $section->fields[1];

        $this->assertSame('public_description', $descField->key);
        $this->assertSame(DppFieldType::LongText, $descField->type);
        $this->assertTrue($descField->translatable);
        $this->assertTrue($descField->nullable);
        $this->assertSame(5000, $descField->maxLength);
    }

    public function test_manufacturer_and_operator_has_14_fields(): void
    {
        $section = $this->registry->sections()['manufacturer_and_operator'];

        $this->assertCount(14, $section->fields);
    }

    public function test_manufacturer_and_operator_translatable_split(): void
    {
        $section = $this->registry->sections()['manufacturer_and_operator'];

        $translatable = array_filter($section->fields, fn (DppFieldDefinition $f) => $f->translatable);
        $nonTranslatable = array_filter($section->fields, fn (DppFieldDefinition $f) => ! $f->translatable);

        $this->assertCount(4, $translatable);
        $this->assertCount(10, $nonTranslatable);

        $translatableKeys = array_map(fn (DppFieldDefinition $f) => $f->key, $translatable);
        $this->assertContains('manufacturer_display_name', $translatableKeys);
        $this->assertContains('responsible_operator_display_name', $translatableKeys);
        $this->assertContains('contact_notes', $translatableKeys);
    }

    public function test_origin_and_traceability_has_5_fields_section_translatable(): void
    {
        $section = $this->registry->sections()['origin_and_traceability'];

        $this->assertCount(5, $section->fields);
        $this->assertTrue($section->translatable);

        $fieldKeys = array_map(fn (DppFieldDefinition $f) => $f->key, $section->fields);
        $expected = ['country_of_origin', 'manufacturing_countries', 'production_date', 'traceability_notes', 'batch_identification_instructions'];
        $this->assertSame($expected, $fieldKeys);
    }

    public function test_materials_and_composition_has_materials_and_composition_notes(): void
    {
        $section = $this->registry->sections()['materials_and_composition'];

        $this->assertCount(2, $section->fields);

        $this->assertSame('materials', $section->fields[0]->key);
        $this->assertSame(DppFieldType::MaterialList, $section->fields[0]->type);
        $this->assertSame(100, $section->fields[0]->maxItems);

        $this->assertSame('composition_notes', $section->fields[1]->key);
        $this->assertSame(DppFieldType::LongText, $section->fields[1]->type);
    }

    public function test_safety_has_6_translatable_fields(): void
    {
        $section = $this->registry->sections()['safety'];

        $this->assertCount(6, $section->fields);

        foreach ($section->fields as $field) {
            $this->assertTrue($field->translatable, "Field {$field->key} should be translatable");
        }

        $fieldKeys = array_map(fn (DppFieldDefinition $f) => $f->key, $section->fields);
        $expected = ['warnings', 'hazards', 'personal_protective_equipment', 'storage_instructions', 'emergency_instructions', 'age_restrictions'];
        $this->assertSame($expected, $fieldKeys);
    }

    public function test_usage_and_care_has_8_translatable_fields(): void
    {
        $section = $this->registry->sections()['usage_and_care'];

        $this->assertCount(8, $section->fields);

        foreach ($section->fields as $field) {
            $this->assertTrue($field->translatable);
        }
    }

    public function test_repair_and_spare_parts_has_12_fields(): void
    {
        $section = $this->registry->sections()['repair_and_spare_parts'];

        $this->assertCount(12, $section->fields);
    }

    public function test_repair_and_spare_parts_translatable_split(): void
    {
        $section = $this->registry->sections()['repair_and_spare_parts'];

        $translatable = array_filter($section->fields, fn (DppFieldDefinition $f) => $f->translatable);
        $nonTranslatable = array_filter($section->fields, fn (DppFieldDefinition $f) => ! $f->translatable);

        $this->assertCount(5, $translatable);
        $this->assertCount(7, $nonTranslatable);

        $nonTranslatableKeys = array_map(fn (DppFieldDefinition $f) => $f->key, $nonTranslatable);
        $this->assertContains('repairable', $nonTranslatableKeys);
        $this->assertContains('spare_parts_available', $nonTranslatableKeys);
        $this->assertContains('spare_parts_url', $nonTranslatableKeys);
    }

    public function test_recycling_and_disposal_has_11_fields(): void
    {
        $section = $this->registry->sections()['recycling_and_disposal'];

        $this->assertCount(11, $section->fields);

        $fieldKeys = array_map(fn (DppFieldDefinition $f) => $f->key, $section->fields);
        $this->assertContains('recycling_instructions', $fieldKeys);
        $this->assertContains('disposal_instructions', $fieldKeys);
        $this->assertContains('take_back_program', $fieldKeys);
        $this->assertContains('recycling_codes', $fieldKeys);
    }

    public function test_environmental_information_has_10_fields(): void
    {
        $section = $this->registry->sections()['environmental_information'];

        $this->assertCount(10, $section->fields);
    }

    public function test_environmental_information_carbon_footprint_bounds(): void
    {
        $section = $this->registry->sections()['environmental_information'];

        $field = $section->fields[0];
        $this->assertSame('carbon_footprint_kg_co2e', $field->key);
        $this->assertSame(DppFieldType::Decimal, $field->type);
        $this->assertSame(0.0, $field->min);
        $this->assertNull($field->max);
    }

    public function test_environmental_information_recycled_content_percentage_bounds(): void
    {
        $section = $this->registry->sections()['environmental_information'];

        $field = $section->fields[1];
        $this->assertSame('recycled_content_percentage', $field->key);
        $this->assertSame(DppFieldType::Decimal, $field->type);
        $this->assertSame(0.0, $field->min);
        $this->assertSame(100.0, $field->max);
    }

    public function test_certifications_and_documents_has_3_fields(): void
    {
        $section = $this->registry->sections()['certifications_and_documents'];

        $this->assertCount(3, $section->fields);

        $this->assertSame('certification_notes', $section->fields[0]->key);
        $this->assertSame('compliance_summary', $section->fields[1]->key);
    }

    public function test_support_and_contact_has_13_fields(): void
    {
        $section = $this->registry->sections()['support_and_contact'];

        $this->assertCount(13, $section->fields);

        $fieldKeys = array_map(fn (DppFieldDefinition $f) => $f->key, $section->fields);
        $expected = [
            'support_email',
            'support_phone',
            'support_url',
            'support_channels',
            'warranty_available',
            'warranty_duration',
            'warranty_duration_unit',
            'warranty_url',
            'warranty_summary',
            'warranty_conditions',
            'warranty_exclusions',
            'warranty_claim_instructions',
            'support_notes',
        ];
        $this->assertSame($expected, $fieldKeys);
    }

    public function test_flat_fields_returns_all_fields_keyed_by_field_key(): void
    {
        $flat = $this->registry->flatFields();

        $expectedCount = 0;
        foreach ($this->registry->sections() as $section) {
            $expectedCount += count($section->fields);
        }

        $this->assertCount($expectedCount, $flat);

        $this->assertArrayHasKey('public_name', $flat);
        $this->assertArrayHasKey('manufacturer_email', $flat);
        $this->assertArrayHasKey('materials', $flat);
        $this->assertArrayHasKey('warnings', $flat);
        $this->assertArrayHasKey('repairable', $flat);
        $this->assertArrayHasKey('carbon_footprint_kg_co2e', $flat);

        foreach ($flat as $key => $field) {
            $this->assertSame($key, $field->key, "Field key '{$key}' must match its key in flatFields()");
        }
    }

    public function test_field_returns_correct_definition(): void
    {
        $field = $this->registry->field('public_name');

        $this->assertNotNull($field);
        $this->assertSame('public_name', $field->key);
        $this->assertSame(DppFieldType::ShortText, $field->type);
    }

    public function test_field_returns_null_for_unknown_field(): void
    {
        $field = $this->registry->field('nonexistent_field');

        $this->assertNull($field);
    }

    public function test_section_keys_in_order_returns_11_keys(): void
    {
        $keys = $this->registry->sectionKeysInOrder();

        $this->assertCount(11, $keys);
        $this->assertSame('identity', $keys[0]);
        $this->assertSame('manufacturer_and_operator', $keys[1]);
        $this->assertSame('support_and_contact', $keys[10]);
    }
}
