<?php

namespace Tests\Unit\Passports\Dpp;

use App\Services\Passports\DppPayloadNormalizer;
use App\Services\Passports\DppSchemaRegistry;
use Tests\TestCase;

class DppPayloadNormalizerTest extends TestCase
{
    private DppPayloadNormalizer $normalizer;

    private DppSchemaRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new DppSchemaRegistry;
        $this->normalizer = new DppPayloadNormalizer($this->registry);
    }

    public function test_normalize_preserves_structure_with_all_4_top_level_keys(): void
    {
        $payload = [
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal'],
            'data' => [],
            'translations' => [],
            'document_references' => [],
        ];

        $normalized = $this->normalizer->normalize($payload);

        $this->assertIsArray($normalized);
        $this->assertArrayHasKey('enabled_sections', $normalized);
        $this->assertArrayHasKey('data', $normalized);
        $this->assertArrayHasKey('translations', $normalized);
        $this->assertArrayHasKey('document_references', $normalized);
    }

    public function test_normalize_sorts_enabled_sections_in_canonical_order(): void
    {
        $payload = [
            'enabled_sections' => ['safety', 'identity', 'recycling_and_disposal', 'manufacturer_and_operator'],
            'data' => [],
            'translations' => [],
            'document_references' => [],
        ];

        $normalized = $this->normalizer->normalize($payload);

        $expected = ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal'];
        $this->assertSame($expected, $normalized['enabled_sections']);
    }

    public function test_normalize_removes_unknown_sections_from_enabled_sections(): void
    {
        $payload = [
            'enabled_sections' => ['identity', 'unknown_section', 'safety', 'manufacturer_and_operator', 'recycling_and_disposal'],
            'data' => [],
            'translations' => [],
            'document_references' => [],
        ];

        $normalized = $this->normalizer->normalize($payload);

        $this->assertNotContains('unknown_section', $normalized['enabled_sections']);
        $this->assertCount(4, $normalized['enabled_sections']);
    }

    public function test_normalize_does_not_auto_add_core_sections(): void
    {
        $payload = [
            'enabled_sections' => ['identity'],
            'data' => [],
            'translations' => [],
            'document_references' => [],
        ];

        $normalized = $this->normalizer->normalize($payload);

        $this->assertSame(['identity'], $normalized['enabled_sections']);
    }

    public function test_normalize_trims_strings(): void
    {
        $payload = [
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal'],
            'data' => [],
            'translations' => [
                'sv' => [
                    'identity' => [
                        'public_name' => '  Trimmed Name  ',
                    ],
                ],
            ],
            'document_references' => [],
        ];

        $normalized = $this->normalizer->normalize($payload);

        $this->assertSame('Trimmed Name', $normalized['translations']['sv']['identity']['public_name']);
    }

    public function test_normalize_converts_empty_strings_to_null_for_nullable_fields(): void
    {
        $normalized = $this->normalizer->normalize([
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal'],
            'data' => [],
            'translations' => [
                'sv' => [
                    'identity' => [
                        'public_name' => '',
                    ],
                ],
            ],
            'document_references' => [],
        ]);

        $this->assertArrayNotHasKey('sv', $normalized['translations']);
    }

    public function test_normalize_excludes_translatable_sections_from_data(): void
    {
        $normalized = $this->normalizer->normalize([
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal'],
            'data' => [
                'manufacturer_and_operator' => [
                    'manufacturer_email' => 'User@Example.COM',
                    'manufacturer_website' => 'https://example.com',
                ],
            ],
            'translations' => [],
            'document_references' => [],
        ]);

        $this->assertArrayNotHasKey('manufacturer_and_operator', $normalized['data']);
    }

    public function test_normalize_uppercases_country_codes(): void
    {
        $normalized = $this->normalizer->normalize([
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal', 'origin_and_traceability'],
            'data' => [
                'origin_and_traceability' => [
                    'country_of_origin' => 'se',
                ],
            ],
            'translations' => [],
            'document_references' => [],
        ]);

        $this->assertSame('SE', $normalized['data']['origin_and_traceability']['country_of_origin']);
    }

    public function test_normalize_excludes_non_translatable_fields_from_translatable_section_in_translations(): void
    {
        $normalized = $this->normalizer->normalize([
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal'],
            'data' => [],
            'translations' => [
                'sv' => [
                    'manufacturer_and_operator' => [
                        'manufacturer_display_name' => 'ACME Corp',
                        'manufacturer_email' => 'user@example.com',
                    ],
                ],
            ],
            'document_references' => [],
        ]);

        $fields = $normalized['translations']['sv']['manufacturer_and_operator'];
        $this->assertArrayHasKey('manufacturer_display_name', $fields);
        $this->assertArrayNotHasKey('manufacturer_email', $fields);
    }

    public function test_normalize_removes_duplicate_string_list_items(): void
    {
        $normalized = $this->normalizer->normalize([
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal'],
            'data' => [],
            'translations' => [
                'sv' => [
                    'safety' => [
                        'warnings' => ['Warning A', 'Warning B', 'warning a', '  Warning B  '],
                    ],
                ],
            ],
            'document_references' => [],
        ]);

        $warnings = $normalized['translations']['sv']['safety']['warnings'];
        $this->assertCount(2, $warnings);
        $this->assertContains('Warning A', $warnings);
        $this->assertContains('Warning B', $warnings);
    }

    public function test_normalize_sorts_document_references_by_display_order_then_uuid(): void
    {
        $payload = [
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal'],
            'data' => [],
            'translations' => [],
            'document_references' => [
                ['document_uuid' => 'ccc', 'role' => 'other', 'display_order' => 2],
                ['document_uuid' => 'aaa', 'role' => 'other', 'display_order' => 2],
                ['document_uuid' => 'bbb', 'role' => 'other', 'display_order' => 1],
            ],
        ];

        $normalized = $this->normalizer->normalize($payload);

        $this->assertSame('bbb', $normalized['document_references'][0]['document_uuid']);
        $this->assertSame('aaa', $normalized['document_references'][1]['document_uuid']);
        $this->assertSame('ccc', $normalized['document_references'][2]['document_uuid']);
    }

    public function test_normalize_removes_entries_with_empty_document_uuid(): void
    {
        $payload = [
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal'],
            'data' => [],
            'translations' => [],
            'document_references' => [
                ['document_uuid' => '', 'role' => 'other'],
                ['document_uuid' => '   ', 'role' => 'other'],
                ['document_uuid' => 'valid-uuid', 'role' => 'other'],
            ],
        ];

        $normalized = $this->normalizer->normalize($payload);

        $this->assertCount(1, $normalized['document_references']);
        $this->assertSame('valid-uuid', $normalized['document_references'][0]['document_uuid']);
    }

    public function test_normalize_removes_unknown_locales(): void
    {
        $normalized = $this->normalizer->normalize([
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal'],
            'data' => [],
            'translations' => [
                'sv' => ['identity' => ['public_name' => 'Name']],
                'invalid-locale' => ['identity' => ['public_name' => 'X']],
                '12' => ['identity' => ['public_name' => 'X']],
            ],
            'document_references' => [],
        ]);

        $this->assertArrayHasKey('sv', $normalized['translations']);
        $this->assertArrayNotHasKey('invalid-locale', $normalized['translations']);
        $this->assertArrayNotHasKey('12', $normalized['translations']);
    }

    public function test_normalize_removes_non_translatable_sections_from_translations(): void
    {
        $normalized = $this->normalizer->normalize([
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal', 'origin_and_traceability'],
            'data' => [
                'origin_and_traceability' => ['country_of_origin' => 'SE'],
            ],
            'translations' => [
                'sv' => [
                    'identity' => ['public_name' => 'Name'],
                    'origin_and_traceability' => ['country_of_origin' => 'SE'],
                ],
            ],
            'document_references' => [],
        ]);

        $this->assertArrayHasKey('identity', $normalized['translations']['sv']);
        $this->assertArrayNotHasKey('origin_and_traceability', $normalized['translations']['sv']);
    }

    public function test_normalize_removes_non_translatable_fields_from_translations(): void
    {
        $normalized = $this->normalizer->normalize([
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal', 'repair_and_spare_parts'],
            'data' => [
                'repair_and_spare_parts' => ['repairable' => true],
            ],
            'translations' => [
                'sv' => [
                    'repair_and_spare_parts' => [
                        'repair_instructions' => 'Instructions',
                        'repairable' => true,
                    ],
                ],
            ],
            'document_references' => [],
        ]);

        $fields = $normalized['translations']['sv']['repair_and_spare_parts'];
        $this->assertArrayHasKey('repair_instructions', $fields);
        $this->assertArrayNotHasKey('repairable', $fields, 'Non-translatable field should be removed from translations');
    }

    public function test_material_list_normalization_includes_all_material_fields(): void
    {
        $normalized = $this->normalizer->normalize([
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal', 'materials_and_composition'],
            'data' => [
                'materials_and_composition' => [
                    'materials' => [
                        [
                            'name' => 'Steel',
                            'percentage' => 70,
                            'recycled_content_percentage' => 30,
                            'hazardous' => false,
                            'country_of_origin' => 'SE',
                        ],
                    ],
                ],
            ],
            'translations' => [],
            'document_references' => [],
        ]);

        $materials = $normalized['data']['materials_and_composition']['materials'];
        $this->assertCount(1, $materials);

        $material = $materials[0];
        $this->assertSame('Steel', $material['name']);
        $this->assertSame(70.0, $material['percentage']);
        $this->assertSame(30.0, $material['recycled_content_percentage']);
        $this->assertFalse($material['hazardous']);
        $this->assertSame('SE', $material['country_of_origin']);
    }

    public function test_material_list_removes_entries_with_empty_name(): void
    {
        $normalized = $this->normalizer->normalize([
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal', 'materials_and_composition'],
            'data' => [
                'materials_and_composition' => [
                    'materials' => [
                        ['name' => '', 'hazardous' => false],
                        ['name' => '  ', 'hazardous' => false],
                        ['name' => 'Valid', 'hazardous' => false],
                    ],
                ],
            ],
            'translations' => [],
            'document_references' => [],
        ]);

        $materials = $normalized['data']['materials_and_composition']['materials'];
        $this->assertCount(1, $materials);
        $this->assertSame('Valid', $materials[0]['name']);
    }

    public function test_normalize_is_idempotent(): void
    {
        $payload = [
            'enabled_sections' => ['safety', 'identity', 'recycling_and_disposal', 'manufacturer_and_operator'],
            'data' => [],
            'translations' => [
                'sv' => [
                    'identity' => ['public_name' => '  Trimmed  '],
                ],
            ],
            'document_references' => [
                ['document_uuid' => 'zzz', 'role' => 'certificate'],
                ['document_uuid' => 'aaa', 'role' => 'instruction'],
            ],
        ];

        $first = $this->normalizer->normalize($payload);
        $second = $this->normalizer->normalize($first);

        $this->assertSame($first, $second);
    }

    public function test_empty_payload_normalization_produces_correct_empty_structure(): void
    {
        $empty = $this->normalizer->normalize([]);

        $this->assertSame([], $empty['enabled_sections']);
        $this->assertSame([], $empty['data']);
        $this->assertSame([], $empty['translations']);
        $this->assertSame([], $empty['document_references']);
    }

    public function test_normalize_removes_unknown_sections_from_data(): void
    {
        $normalized = $this->normalizer->normalize([
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal', 'origin_and_traceability'],
            'data' => [
                'origin_and_traceability' => ['country_of_origin' => 'SE'],
                'unknown_section' => ['foo' => 'bar'],
            ],
            'translations' => [],
            'document_references' => [],
        ]);

        $this->assertArrayHasKey('origin_and_traceability', $normalized['data']);
        $this->assertArrayNotHasKey('unknown_section', $normalized['data']);
    }

    public function test_normalize_removes_unknown_sections_from_translations(): void
    {
        $normalized = $this->normalizer->normalize([
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal'],
            'data' => [],
            'translations' => [
                'sv' => [
                    'identity' => ['public_name' => 'Name'],
                    'unknown_section' => ['foo' => 'bar'],
                ],
            ],
            'document_references' => [],
        ]);

        $this->assertArrayHasKey('identity', $normalized['translations']['sv']);
        $this->assertArrayNotHasKey('unknown_section', $normalized['translations']['sv']);
    }

    public function test_normalize_removes_unknown_fields_from_data(): void
    {
        $normalized = $this->normalizer->normalize([
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal', 'origin_and_traceability'],
            'data' => [
                'origin_and_traceability' => [
                    'country_of_origin' => 'SE',
                    'unknown_field' => 'value',
                ],
            ],
            'translations' => [],
            'document_references' => [],
        ]);

        $this->assertArrayHasKey('country_of_origin', $normalized['data']['origin_and_traceability']);
        $this->assertArrayNotHasKey('unknown_field', $normalized['data']['origin_and_traceability']);
    }

    public function test_normalize_removes_unknown_fields_from_translations(): void
    {
        $normalized = $this->normalizer->normalize([
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal'],
            'data' => [],
            'translations' => [
                'sv' => [
                    'identity' => [
                        'public_name' => 'Name',
                        'unknown_field' => 'value',
                    ],
                ],
            ],
            'document_references' => [],
        ]);

        $this->assertArrayHasKey('public_name', $normalized['translations']['sv']['identity']);
        $this->assertArrayNotHasKey('unknown_field', $normalized['translations']['sv']['identity']);
    }
}
