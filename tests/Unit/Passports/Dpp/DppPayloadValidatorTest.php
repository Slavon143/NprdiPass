<?php

namespace Tests\Unit\Passports\Dpp;

use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\DppPayloadValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class DppPayloadValidatorTest extends TestCase
{
    use RefreshDatabase;

    private DppPayloadValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = app(DppPayloadValidator::class);
    }

    private function createCompany(): Company
    {
        return Company::factory()->create();
    }

    private function createProduct(Company $company): Product
    {
        return Product::query()->forceCreate([
            'uuid' => Uuid::uuid7()->toString(),
            'company_id' => $company->id,
            'name' => 'Test Product '.str()->random(5),
            'slug' => 'test-product-'.str()->random(5),
            'slug_normalized' => str()->random(10),
            'status' => ProductStatus::Active->value,
            'created_by' => User::factory()->create()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPassport(Company $company, Product $product): ProductPassport
    {
        return ProductPassport::query()->forceCreate([
            'uuid' => Uuid::uuid7()->toString(),
            'public_id' => Uuid::uuid7()->toString(),
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => 'draft',
            'default_language' => 'sv',
            'enabled_languages' => ['sv', 'en'],
            'created_by' => User::factory()->create()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createDocument(Company $company, Product $product): ProductDocument
    {
        return ProductDocument::query()->forceCreate([
            'uuid' => Uuid::uuid7()->toString(),
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => 'active',
            'created_by_user_id' => User::factory()->create()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validPayload(): array
    {
        return [
            'enabled_sections' => ['identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal'],
            'data' => [],
            'translations' => [],
            'document_references' => [],
        ];
    }

    private function expectValidationError(callable $callback, string $expectedKey): void
    {
        try {
            $callback();
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($expectedKey, $e->errors(), "Expected error key '{$expectedKey}'. Got: ".json_encode(array_keys($e->errors())));
        }
    }

    // ─── validateFullPayload ──────────────────────────────────────

    public function test_validate_full_payload_accepts_valid_payload(): void
    {
        $company = $this->createCompany();

        $result = $this->validator->validateFullPayload($this->validPayload(), $company);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('enabled_sections', $result);
    }

    public function test_rejects_unknown_top_level_keys(): void
    {
        $company = $this->createCompany();
        $payload = $this->validPayload();
        $payload['unknown_key'] = 'value';

        $this->expectValidationError(
            fn () => $this->validator->validateFullPayload($payload, $company),
            'payload',
        );
    }

    public function test_rejects_unknown_sections_in_enabled_sections(): void
    {
        $company = $this->createCompany();
        $payload = $this->validPayload();
        $payload['enabled_sections'] = ['identity', 'unknown_section', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal'];

        $this->expectValidationError(
            fn () => $this->validator->validateFullPayload($payload, $company),
            'enabled_sections',
        );
    }

    public function test_rejects_duplicate_sections(): void
    {
        $company = $this->createCompany();
        $payload = $this->validPayload();
        $payload['enabled_sections'] = ['identity', 'identity', 'manufacturer_and_operator', 'safety', 'recycling_and_disposal'];

        $this->expectValidationError(
            fn () => $this->validator->validateFullPayload($payload, $company),
            'enabled_sections',
        );
    }

    public function test_rejects_disabling_core_sections(): void
    {
        $company = $this->createCompany();
        $payload = $this->validPayload();
        $payload['enabled_sections'] = ['identity', 'manufacturer_and_operator'];

        $this->expectValidationError(
            fn () => $this->validator->validateFullPayload($payload, $company),
            'enabled_sections',
        );
    }

    public function test_rejects_unknown_sections_in_data(): void
    {
        $company = $this->createCompany();
        $payload = $this->validPayload();
        $payload['data'] = ['unknown_section' => ['foo' => 'bar']];

        $this->expectValidationError(
            fn () => $this->validator->validateFullPayload($payload, $company),
            'data',
        );
    }

    public function test_rejects_more_than_100_document_references(): void
    {
        $company = $this->createCompany();
        $payload = $this->validPayload();
        $payload['document_references'] = array_fill(0, 101, [
            'document_uuid' => Uuid::uuid7()->toString(),
            'role' => 'other',
        ]);

        $this->expectValidationError(
            fn () => $this->validator->validateFullPayload($payload, $company),
            'document_references',
        );
    }

    public function test_rejects_invalid_document_role(): void
    {
        $company = $this->createCompany();
        $payload = $this->validPayload();
        $payload['document_references'] = [
            ['document_uuid' => Uuid::uuid7()->toString(), 'role' => 'invalid_role'],
        ];

        $this->expectValidationError(
            fn () => $this->validator->validateFullPayload($payload, $company),
            'document_references',
        );
    }

    public function test_rejects_duplicate_document_uuids(): void
    {
        $company = $this->createCompany();
        $uuid = Uuid::uuid7()->toString();
        $payload = $this->validPayload();
        $payload['document_references'] = [
            ['document_uuid' => $uuid, 'role' => 'other'],
            ['document_uuid' => $uuid, 'role' => 'instruction'],
        ];

        $this->expectValidationError(
            fn () => $this->validator->validateFullPayload($payload, $company),
            'document_references',
        );
    }

    public function test_rejects_payload_exceeding_1_mib(): void
    {
        $company = $this->createCompany();
        $payload = $this->validPayload();
        $payload['translations'] = [
            'sv' => [
                'identity' => [
                    'public_name' => str_repeat('x', 600000),
                    'public_description' => str_repeat('y', 600000),
                ],
            ],
        ];

        $this->expectValidationError(
            fn () => $this->validator->validateFullPayload($payload, $company),
            'payload',
        );
    }

    public function test_rejects_translation_locale_count_over_limit(): void
    {
        $company = $this->createCompany();
        $product = $this->createProduct($company);
        $passport = $this->createPassport($company, $product);

        $payload = $this->validPayload();
        $payload['translations'] = [];

        foreach (range('a', 'z') as $letter) {
            $payload['translations']["a{$letter}"] = [
                'identity' => ['public_name' => "Name {$letter}"],
            ];

            if (count($payload['translations']) > DppPayloadValidator::MAX_LOCALE_COUNT) {
                break;
            }
        }

        $this->expectValidationError(
            fn () => $this->validator->validateFullPayload($payload, $company, $passport),
            'translations',
        );
    }

    public function test_validates_document_belongs_to_product_when_passport_provided(): void
    {
        $company = $this->createCompany();
        $product = $this->createProduct($company);
        $passport = $this->createPassport($company, $product);
        $document = $this->createDocument($company, $product);

        $payload = $this->validPayload();
        $payload['document_references'] = [
            ['document_uuid' => $document->uuid, 'role' => 'other'],
        ];

        $result = $this->validator->validateFullPayload($payload, $company, $passport);

        $this->assertIsArray($result);
    }

    public function test_rejects_document_not_found_when_passport_provided(): void
    {
        $company = $this->createCompany();
        $product = $this->createProduct($company);
        $passport = $this->createPassport($company, $product);

        $payload = $this->validPayload();
        $payload['document_references'] = [
            ['document_uuid' => Uuid::uuid7()->toString(), 'role' => 'other'],
        ];

        $this->expectValidationError(
            fn () => $this->validator->validateFullPayload($payload, $company, $passport),
            'document_references',
        );
    }

    public function test_rejects_document_from_wrong_product_when_passport_provided(): void
    {
        $company = $this->createCompany();
        $productA = $this->createProduct($company);
        $productB = $this->createProduct($company);
        $passport = $this->createPassport($company, $productA);
        $document = $this->createDocument($company, $productB);

        $payload = $this->validPayload();
        $payload['document_references'] = [
            ['document_uuid' => $document->uuid, 'role' => 'other'],
        ];

        $this->expectValidationError(
            fn () => $this->validator->validateFullPayload($payload, $company, $passport),
            'document_references',
        );
    }

    // ─── validateSectionPayload (translatable) ────────────────────

    public function test_validate_section_payload_accepts_valid_translatable_section(): void
    {
        $sectionPayload = [
            'public_name' => 'Test Product',
            'public_description' => 'A test product description.',
        ];

        $result = $this->validator->validateSectionPayload('identity', $sectionPayload, true);

        $this->assertIsArray($result);
        $this->assertSame('Test Product', $result['public_name']);
    }

    public function test_validate_section_payload_accepts_valid_non_translatable_section(): void
    {
        $sectionPayload = [
            'country_of_origin' => 'SE',
            'production_date' => '2024-01-15',
        ];

        $result = $this->validator->validateSectionPayload('origin_and_traceability', $sectionPayload, false);

        $this->assertIsArray($result);
        $this->assertSame('SE', $result['country_of_origin']);
    }

    public function test_validate_section_payload_rejects_unknown_section(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload('nonexistent_section', ['foo' => 'bar'], true),
            'section',
        );
    }

    public function test_validate_section_payload_rejects_unknown_field(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload('identity', ['unknown_field' => 'value'], true),
            'fields',
        );
    }

    public function test_validate_section_payload_accepts_non_translatable_field_in_translatable_mode(): void
    {
        $result = $this->validator->validateSectionPayload('repair_and_spare_parts', ['repairable' => true], true);

        $this->assertArrayHasKey('repairable', $result);
        $this->assertTrue($result['repairable']);
    }

    public function test_validate_section_payload_rejects_wrong_type_for_boolean(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload('repair_and_spare_parts', ['repairable' => 'true'], false),
            'repairable',
        );
    }

    public function test_validate_section_payload_rejects_wrong_type_for_decimal(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload('environmental_information', ['carbon_footprint_kg_co2e' => 'not-a-number'], false),
            'carbon_footprint_kg_co2e',
        );
    }

    public function test_validate_section_payload_rejects_too_long_string(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload('identity', ['public_name' => str_repeat('x', 501)], true),
            'public_name',
        );
    }

    public function test_validate_section_payload_rejects_too_many_string_list_items(): void
    {
        $items = array_fill(0, 101, 'warning text');

        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload('safety', ['warnings' => $items], true),
            'warnings',
        );
    }

    public function test_validate_section_payload_rejects_invalid_email(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload(
                'manufacturer_and_operator',
                ['manufacturer_email' => 'not-an-email'],
                false,
            ),
            'manufacturer_email',
        );
    }

    public function test_validate_section_payload_rejects_invalid_url(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload(
                'manufacturer_and_operator',
                ['manufacturer_website' => 'not-a-url'],
                false,
            ),
            'manufacturer_website',
        );
    }

    public function test_validate_section_payload_rejects_url_with_credentials(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload(
                'manufacturer_and_operator',
                ['manufacturer_website' => 'https://user:pass@example.com'],
                false,
            ),
            'manufacturer_website',
        );
    }

    public function test_validate_section_payload_rejects_invalid_country_code(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload(
                'origin_and_traceability',
                ['country_of_origin' => 'xyz'],
                false,
            ),
            'country_of_origin',
        );
    }

    public function test_validate_section_payload_accepts_valid_country_code(): void
    {
        $result = $this->validator->validateSectionPayload(
            'origin_and_traceability',
            ['country_of_origin' => 'SE'],
            false,
        );

        $this->assertSame('SE', $result['country_of_origin']);
    }

    public function test_validate_section_payload_rejects_invalid_date_format(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload(
                'origin_and_traceability',
                ['production_date' => '15-01-2024'],
                false,
            ),
            'production_date',
        );
    }

    public function test_validate_section_payload_accepts_valid_date(): void
    {
        $result = $this->validator->validateSectionPayload(
            'origin_and_traceability',
            ['production_date' => '2024-01-15'],
            false,
        );

        $this->assertSame('2024-01-15', $result['production_date']);
    }

    public function test_validate_section_payload_rejects_decimal_out_of_range(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload(
                'environmental_information',
                ['recycled_content_percentage' => 150],
                false,
            ),
            'recycled_content_percentage',
        );
    }

    public function test_validate_section_payload_rejects_material_list_with_duplicate_names(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload(
                'materials_and_composition',
                [
                    'materials' => [
                        ['name' => 'Steel', 'hazardous' => false],
                        ['name' => 'Steel', 'hazardous' => false],
                    ],
                ],
                false,
            ),
            'materials',
        );
    }

    public function test_validate_section_payload_rejects_material_list_exceeding_100(): void
    {
        $materials = [];
        for ($i = 0; $i < 101; $i++) {
            $materials[] = ['name' => "Material {$i}", 'hazardous' => false];
        }

        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload(
                'materials_and_composition',
                ['materials' => $materials],
                false,
            ),
            'materials',
        );
    }

    public function test_validate_section_payload_rejects_material_percentages_sum_exceeding_100(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload(
                'materials_and_composition',
                [
                    'materials' => [
                        ['name' => 'A', 'percentage' => 60, 'hazardous' => false],
                        ['name' => 'B', 'percentage' => 50, 'hazardous' => false],
                    ],
                ],
                false,
            ),
            'materials',
        );
    }

    public function test_validate_section_payload_accepts_material_percentages_sum_100(): void
    {
        $result = $this->validator->validateSectionPayload(
            'materials_and_composition',
            [
                'materials' => [
                    ['name' => 'A', 'percentage' => 60, 'hazardous' => false],
                    ['name' => 'B', 'percentage' => 40, 'hazardous' => false],
                ],
            ],
            false,
        );

        $this->assertIsArray($result);
        $this->assertCount(2, $result['materials']);
    }

    public function test_validate_section_payload_accepts_decimal_strings_without_float_requirement(): void
    {
        $result = $this->validator->validateSectionPayload(
            'environmental_information',
            ['recycled_content_percentage' => '50.125'],
            false,
        );

        $this->assertSame('50.125', $result['recycled_content_percentage']);
    }

    public function test_validate_section_payload_accepts_recycled_percentage_as_share_of_material(): void
    {
        $result = $this->validator->validateSectionPayload(
            'materials_and_composition',
            [
                'materials' => [
                    ['name' => 'Steel', 'percentage' => '20', 'recycled_content_percentage' => '30', 'hazardous' => false],
                ],
            ],
            false,
        );

        $this->assertSame('30', $result['materials'][0]['recycled_content_percentage']);
    }

    public function test_validate_section_payload_accepts_structured_environmental_metrics(): void
    {
        $result = $this->validator->validateSectionPayload(
            'environmental_information',
            [
                'environmental_metrics' => [
                    [
                        'metric_code' => 'energy_use',
                        'label' => 'Energy use',
                        'value' => '12.5',
                        'unit' => 'kwh',
                        'scope' => 'use_phase',
                        'verification_status' => 'provided',
                    ],
                ],
            ],
            false,
        );

        $this->assertSame('energy_use', $result['environmental_metrics'][0]['metric_code']);
    }

    public function test_validate_section_payload_rejects_overlong_structured_list_string(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload(
                'environmental_information',
                [
                    'environmental_metrics' => [
                        [
                            'metric_code' => 'energy_use',
                            'label' => str_repeat('x', DppPayloadValidator::MAX_JSON_LIST_STRING_LENGTH + 1),
                            'value' => '12.5',
                            'unit' => 'kwh',
                        ],
                    ],
                ],
                false,
            ),
            'environmental_metrics.0.label',
        );
    }

    public function test_validate_section_payload_rejects_unsupported_environmental_metric_unit(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload(
                'environmental_information',
                [
                    'environmental_metrics' => [
                        ['metric_code' => 'energy_use', 'value' => '12.5', 'unit' => 'bananas'],
                    ],
                ],
                false,
            ),
            'environmental_metrics',
        );
    }

    public function test_validate_section_payload_rejects_unsafe_urls_inside_structured_lists(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload(
                'support_and_contact',
                [
                    'support_channels' => [
                        ['type' => 'web', 'value' => 'https://support.example.com', 'url' => 'https://localhost/catalog/products'],
                    ],
                ],
                false,
            ),
            'support_channels.0.url',
        );
    }

    public function test_validate_section_payload_rejects_string_list_non_string_item(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload(
                'safety',
                ['warnings' => ['valid', 123]],
                true,
            ),
            'warnings',
        );
    }

    public function test_validate_section_payload_rejects_carbon_footprint_negative(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload(
                'environmental_information',
                ['carbon_footprint_kg_co2e' => -1.0],
                false,
            ),
            'carbon_footprint_kg_co2e',
        );
    }

    public function test_validate_section_payload_accepts_carbon_footprint_zero(): void
    {
        $result = $this->validator->validateSectionPayload(
            'environmental_information',
            ['carbon_footprint_kg_co2e' => 0],
            false,
        );

        $this->assertSame(0, $result['carbon_footprint_kg_co2e']);
    }

    public function test_validate_section_payload_rejects_material_with_non_string_name(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload(
                'materials_and_composition',
                [
                    'materials' => [
                        ['name' => 123, 'hazardous' => false],
                    ],
                ],
                false,
            ),
            'materials',
        );
    }

    public function test_validate_section_payload_rejects_material_with_invalid_country_code(): void
    {
        $this->expectValidationError(
            fn () => $this->validator->validateSectionPayload(
                'materials_and_composition',
                [
                    'materials' => [
                        ['name' => 'Steel', 'hazardous' => false, 'country_of_origin' => 'xyz'],
                    ],
                ],
                false,
            ),
            'materials',
        );
    }
}
