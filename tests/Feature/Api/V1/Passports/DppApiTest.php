<?php

namespace Tests\Feature\Api\V1\Passports;

use App\Enums\ApiTokenAbility;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Enums\Passports\DppSectionKey;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DppApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['status' => CompanyStatus::Active]);
        $this->user = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'company_id' => $this->company->getKey(),
            'user_id' => $this->user->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $this->product = new Product;
        $this->product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'API Product '.fake()->unique()->word(),
            'slug' => 'api-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'api-product-'.fake()->unique()->slug(1),
            'status' => ProductStatus::Active,
            'created_by' => $this->user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();
        $this->product->refresh();
    }

    private function issueToken(array $abilities): string
    {
        $token = issueCompanyApiToken($this->user, $this->company, $abilities);

        return $token->plainTextToken;
    }

    private function createDraftViaApi(): array
    {
        $token = $this->issueToken([ApiTokenAbility::PassportsWrite->value, ApiTokenAbility::PassportsRead->value]);
        $res = $this->withToken($token)
            ->postJson("/api/v1/catalog/products/{$this->product->uuid}/passport");

        $res->assertStatus(201);

        return $res->json('data', []);
    }

    // ── GET passport ──────────────────────────────────────────────

    public function test_get_passport_returns_editor_data(): void
    {
        $editorData = $this->createDraftViaApi();

        $token = $this->issueToken([ApiTokenAbility::PassportsRead->value]);
        $res = $this->withToken($token)
            ->getJson("/api/v1/catalog/products/{$this->product->uuid}/passport");

        $res->assertOk();
        $data = $res->json('data', []);

        $this->assertArrayHasKey('passport_uuid', $data);
        $this->assertArrayHasKey('public_id', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('default_language', $data);
        $this->assertArrayHasKey('draft_version_uuid', $data);
        $this->assertArrayHasKey('draft_revision', $data);
        $this->assertArrayHasKey('payload', $data);
        $this->assertArrayHasKey('catalog_context', $data);
    }

    public function test_get_passport_without_token_returns_401(): void
    {
        $this->getJson("/api/v1/catalog/products/{$this->product->uuid}/passport")
            ->assertUnauthorized();
    }

    // ── POST create draft ─────────────────────────────────────────

    public function test_create_draft_via_api(): void
    {
        $token = $this->issueToken([ApiTokenAbility::PassportsWrite->value, ApiTokenAbility::PassportsRead->value]);

        $res = $this->withToken($token)
            ->postJson("/api/v1/catalog/products/{$this->product->uuid}/passport");

        $res->assertStatus(201);
        $data = $res->json('data', []);

        $this->assertSame('draft', $data['status']);
        $this->assertSame(1, $data['draft_revision']);
        $this->assertNotNull($data['draft_version_uuid']);
    }

    // ── GET schema ─────────────────────────────────────────────────

    public function test_get_schema_returns_sections(): void
    {
        $token = $this->issueToken([ApiTokenAbility::PassportsRead->value]);

        $res = $this->withToken($token)
            ->getJson('/api/v1/catalog/passports/schema');

        $res->assertOk();
        $data = $res->json('data', []);

        $this->assertArrayHasKey('sections', $data);
        $this->assertIsArray($data['sections']);
        $this->assertNotEmpty($data['sections']);
    }

    // ── PUT update section ────────────────────────────────────────

    public function test_update_section_via_api(): void
    {
        $editorData = $this->createDraftViaApi();

        $token = $this->issueToken([ApiTokenAbility::PassportsWrite->value, ApiTokenAbility::PassportsRead->value]);

        $res = $this->withToken($token)
            ->putJson(
                "/api/v1/catalog/products/{$this->product->uuid}/passport/sections/".DppSectionKey::UsageAndCare->value,
                [
                    'section_payload' => ['usage_instructions' => 'API update.'],
                    'expected_revision' => 1,
                ],
            );

        $res->assertOk();
        $data = $res->json('data', []);

        $this->assertSame(2, $data['draft_revision']);

        $locale = $data['default_language'];
        $this->assertSame(
            'API update.',
            $data['payload']['translations'][$locale][DppSectionKey::UsageAndCare->value]['usage_instructions'],
        );
    }

    // ── PUT update settings ───────────────────────────────────────

    public function test_update_settings_via_api(): void
    {
        $this->createDraftViaApi();

        $token = $this->issueToken([ApiTokenAbility::PassportsWrite->value, ApiTokenAbility::PassportsRead->value]);

        $res = $this->withToken($token)
            ->putJson(
                "/api/v1/catalog/products/{$this->product->uuid}/passport/settings",
                [
                    'settings' => [
                        'enabled_sections' => array_map(fn ($s) => $s->value, DppSectionKey::cases()),
                    ],
                    'expected_revision' => 1,
                ],
            );

        $res->assertOk();
        $data = $res->json('data', []);

        $this->assertSame(2, $data['draft_revision']);
    }

    // ── PUT sync documents ────────────────────────────────────────

    public function test_sync_documents_via_api(): void
    {
        $editorData = $this->createDraftViaApi();

        $document = ProductDocument::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'status' => ProductDocumentStatus::Active->value,
            'created_by_user_id' => $this->user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $version = new ProductDocumentVersion;
        $version->forceFill([
            'uuid' => fake()->uuid(),
            'company_id' => $this->company->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'document_type' => ProductDocumentType::Instruction->value,
            'title' => 'API Doc',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
            'original_filename' => 'api-doc.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'storage_key' => 'test/'.fake()->uuid().'.pdf',
            'created_by_user_id' => $this->user->getKey(),
        ])->save();

        $document->forceFill(['current_version_id' => $version->getKey()])->save();

        $token = $this->issueToken([ApiTokenAbility::PassportsWrite->value, ApiTokenAbility::PassportsRead->value]);

        $res = $this->withToken($token)
            ->putJson(
                "/api/v1/catalog/products/{$this->product->uuid}/passport/documents",
                [
                    'document_references' => [[
                        'document_uuid' => $document->uuid,
                        'role' => 'instruction',
                    ]],
                    'expected_revision' => $editorData['draft_revision'],
                ],
            );

        $res->assertOk();
        $data = $res->json('data', []);

        $this->assertSame($editorData['draft_revision'] + 1, $data['draft_revision']);
        $this->assertCount(1, $data['payload']['document_references']);
    }

    // ── POST reset section ────────────────────────────────────────

    public function test_reset_section_via_api(): void
    {
        $this->createDraftViaApi();

        $token = $this->issueToken([ApiTokenAbility::PassportsWrite->value, ApiTokenAbility::PassportsRead->value]);

        $updateRes = $this->withToken($token)
            ->putJson(
                "/api/v1/catalog/products/{$this->product->uuid}/passport/sections/".DppSectionKey::UsageAndCare->value,
                [
                    'section_payload' => ['usage_instructions' => 'Will be reset.'],
                    'expected_revision' => 1,
                ],
            );
        $updateRes->assertOk();

        $res = $this->withToken($token)
            ->postJson(
                "/api/v1/catalog/products/{$this->product->uuid}/passport/sections/".DppSectionKey::UsageAndCare->value.'/reset',
                [
                    'expected_revision' => 2,
                ],
            );

        $res->assertOk();
        $data = $res->json('data', []);

        $this->assertSame(3, $data['draft_revision']);

        $locale = $data['default_language'];
        $this->assertArrayNotHasKey(
            DppSectionKey::UsageAndCare->value,
            $data['payload']['translations'][$locale] ?? [],
        );
    }

    // ── Authorization checks ──────────────────────────────────────

    public function test_requires_passports_read_ability_to_get(): void
    {
        $catalogOnlyToken = $this->issueToken([ApiTokenAbility::CatalogRead->value]);

        $this->withToken($catalogOnlyToken)
            ->getJson("/api/v1/catalog/products/{$this->product->uuid}/passport")
            ->assertStatus(403);
    }

    public function test_requires_passports_write_ability_to_create(): void
    {
        $token = $this->issueToken([ApiTokenAbility::PassportsRead->value]);

        $this->withToken($token)
            ->postJson("/api/v1/catalog/products/{$this->product->uuid}/passport")
            ->assertStatus(403);
    }

    public function test_returns_404_for_wrong_tenant(): void
    {
        $otherCompany = Company::factory()->create(['status' => CompanyStatus::Active]);
        $otherUser = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'company_id' => $otherCompany->getKey(),
            'user_id' => $otherUser->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $token = issueCompanyApiToken($otherUser, $otherCompany, [
            ApiTokenAbility::PassportsRead->value,
        ]);

        $this->withToken($token->plainTextToken)
            ->getJson("/api/v1/catalog/products/{$this->product->uuid}/passport")
            ->assertNotFound();
    }

    public function test_returns_409_on_revision_conflict(): void
    {
        $editorData = $this->createDraftViaApi();

        $token = $this->issueToken([ApiTokenAbility::PassportsWrite->value, ApiTokenAbility::PassportsRead->value]);

        $this->withToken($token)
            ->putJson(
                "/api/v1/catalog/products/{$this->product->uuid}/passport/sections/".DppSectionKey::UsageAndCare->value,
                [
                    'section_payload' => ['usage_instructions' => 'First.'],
                    'expected_revision' => 1,
                ],
            )
            ->assertOk();

        $this->withToken($token)
            ->putJson(
                "/api/v1/catalog/products/{$this->product->uuid}/passport/sections/".DppSectionKey::UsageAndCare->value,
                [
                    'section_payload' => ['usage_instructions' => 'Conflict.'],
                    'expected_revision' => 1,
                ],
            )
            ->assertStatus(409);
    }

    public function test_returns_422_on_validation_error(): void
    {
        $this->createDraftViaApi();

        $token = $this->issueToken([ApiTokenAbility::PassportsWrite->value, ApiTokenAbility::PassportsRead->value]);

        $this->withToken($token)
            ->putJson(
                "/api/v1/catalog/products/{$this->product->uuid}/passport/sections/".DppSectionKey::UsageAndCare->value,
                [
                    'section_payload' => ['unknown_field' => 'bad'],
                    'expected_revision' => 1,
                ],
            )
            ->assertStatus(422);
    }

    public function test_returns_422_on_unknown_section(): void
    {
        $this->createDraftViaApi();

        $token = $this->issueToken([ApiTokenAbility::PassportsWrite->value, ApiTokenAbility::PassportsRead->value]);

        $this->withToken($token)
            ->putJson(
                "/api/v1/catalog/products/{$this->product->uuid}/passport/sections/nonexistent",
                [
                    'section_payload' => ['field' => 'value'],
                    'expected_revision' => 1,
                ],
            )
            ->assertStatus(422);
    }

    public function test_get_passport_returns_404_when_no_passport_exists(): void
    {
        $token = $this->issueToken([ApiTokenAbility::PassportsRead->value]);

        $this->withToken($token)
            ->getJson("/api/v1/catalog/products/{$this->product->uuid}/passport")
            ->assertNotFound();
    }

    public function test_can_update_non_translatable_section_via_api(): void
    {
        $this->createDraftViaApi();

        $token = $this->issueToken([ApiTokenAbility::PassportsWrite->value, ApiTokenAbility::PassportsRead->value]);

        $res = $this->withToken($token)
            ->putJson(
                "/api/v1/catalog/products/{$this->product->uuid}/passport/sections/".DppSectionKey::OriginAndTraceability->value,
                [
                    'section_payload' => ['country_of_origin' => 'SE'],
                    'expected_revision' => 1,
                ],
            );

        $res->assertOk();
        $data = $res->json('data', []);

        $this->assertSame(
            'SE',
            $data['payload']['data'][DppSectionKey::OriginAndTraceability->value]['country_of_origin'],
        );
    }
}
