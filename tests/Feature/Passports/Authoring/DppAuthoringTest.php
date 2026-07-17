<?php

namespace Tests\Feature\Passports\Authoring;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\ResetProductPassportSectionAction;
use App\Actions\Passports\SyncProductPassportDocumentsAction;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Actions\Passports\UpdateProductPassportSettingsAction;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\AuditLog;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class DppAuthoringTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private ProductPassport $passport;

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
    }

    private function createProduct(): Product
    {
        $product = new Product;
        $product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Test Product '.fake()->unique()->word(),
            'slug' => 'test-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'test-product-'.fake()->unique()->slug(1),
            'status' => ProductStatus::Active,
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        return $product->refresh();
    }

    private function createArchivedProduct(): Product
    {
        $product = new Product;
        $product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Archived Product '.fake()->unique()->word(),
            'slug' => 'archived-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'archived-product-'.fake()->unique()->slug(1),
            'status' => ProductStatus::Archived,
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        return $product->refresh();
    }

    private function createDraftPassport(Product $product): ProductPassport
    {
        $action = app(CreateProductPassportDraftAction::class);

        return $action->handle($this->actor, $this->company, $product);
    }

    // ── Create draft ──────────────────────────────────────────────

    public function test_create_passport_sets_draft_status(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);

        $this->assertSame(ProductPassportStatus::Draft, $passport->status);
        $this->assertEquals($this->company->getKey(), $passport->company_id);
        $this->assertEquals($product->getKey(), $passport->product_id);
    }

    public function test_create_passport_creates_draft_version_with_revision_1(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);

        $draft = $passport->currentDraftVersion;
        $this->assertNotNull($draft);
        $this->assertSame(1, $draft->draft_revision);
        $this->assertSame(ProductPassportVersionStatus::Draft, $draft->status);
    }

    public function test_create_passport_sets_current_draft_version_id(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);

        $this->assertNotNull($passport->current_draft_version_id);
        $this->assertEquals(
            $passport->currentDraftVersion->getKey(),
            $passport->current_draft_version_id,
        );
    }

    public function test_create_passport_uses_default_language_from_config(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);

        $defaultLanguage = config('passports.default_language', 'sv');
        $this->assertSame($defaultLanguage, $passport->default_language);
    }

    public function test_create_passport_emits_exactly_one_audit_event(): void
    {
        $product = $this->createProduct();
        $countBefore = AuditLog::query()->count();

        $passport = $this->createDraftPassport($product);

        $countAfter = AuditLog::query()->count();
        $this->assertSame($countBefore + 1, $countAfter);

        $log = AuditLog::query()
            ->where('event', AuditEvent::PassportCreated->value)
            ->where('company_id', $this->company->getKey())
            ->sole();
        $this->assertNotNull($log);
    }

    public function test_create_passport_rejects_archived_product(): void
    {
        $product = $this->createArchivedProduct();
        $action = app(CreateProductPassportDraftAction::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create passport for archived product.');

        $action->handle($this->actor, $this->company, $product);
    }

    public function test_create_passport_returns_existing_draft_idempotent(): void
    {
        $product = $this->createProduct();
        $action = app(CreateProductPassportDraftAction::class);

        $first = $action->handle($this->actor, $this->company, $product);
        $second = $action->handle($this->actor, $this->company, $product);

        $this->assertEquals($first->getKey(), $second->getKey());
        $this->assertSame(1, ProductPassport::query()->where('product_id', $product->getKey())->count());
    }

    // ── Update section ────────────────────────────────────────────

    public function test_update_translatable_section_stores_in_translations(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);
        $action = app(UpdateProductPassportSectionAction::class);

        $result = $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            DppSectionKey::UsageAndCare->value,
            [
                'usage_instructions' => 'Handle with care.',
            ],
            1,
        );

        $payload = $result->currentDraftVersion->payload;
        $locale = config('passports.default_language', 'sv');
        $this->assertArrayHasKey('translations', $payload);
        $this->assertArrayHasKey($locale, $payload['translations']);
        $this->assertArrayHasKey(DppSectionKey::UsageAndCare->value, $payload['translations'][$locale]);
        $this->assertSame(
            'Handle with care.',
            $payload['translations'][$locale][DppSectionKey::UsageAndCare->value]['usage_instructions'],
        );
    }

    public function test_update_non_translatable_section_stores_in_data(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);
        $action = app(UpdateProductPassportSectionAction::class);

        $result = $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            DppSectionKey::OriginAndTraceability->value,
            [
                'country_of_origin' => 'SE',
            ],
            1,
        );

        $payload = $result->currentDraftVersion->payload;
        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey(DppSectionKey::OriginAndTraceability->value, $payload['data']);
        $this->assertSame(
            'SE',
            $payload['data'][DppSectionKey::OriginAndTraceability->value]['country_of_origin'],
        );
    }

    public function test_update_section_increments_revision_by_1(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);
        $action = app(UpdateProductPassportSectionAction::class);

        $result = $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'Step 1.'],
            1,
        );

        $this->assertSame(2, $result->currentDraftVersion->draft_revision);
    }

    public function test_update_section_keeps_other_sections_unchanged(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);
        $action = app(UpdateProductPassportSectionAction::class);

        $locale = $passport->default_language;

        $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            DppSectionKey::Safety->value,
            ['warnings' => ['Warning A']],
            1,
        );

        $result = $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport->fresh(),
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'Step 2.'],
            2,
        );

        $payload = $result->currentDraftVersion->payload;
        $this->assertArrayHasKey(DppSectionKey::Safety->value, $payload['translations'][$locale]);
        $this->assertArrayHasKey(DppSectionKey::UsageAndCare->value, $payload['translations'][$locale]);
    }

    public function test_update_section_emits_exactly_one_audit_event(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);
        $action = app(UpdateProductPassportSectionAction::class);
        $countBefore = AuditLog::query()->count();

        $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'Step 1.'],
            1,
        );

        $countAfter = AuditLog::query()->count();
        $this->assertSame($countBefore + 1, $countAfter);

        $log = AuditLog::query()
            ->where('event', AuditEvent::PassportDraftUpdated->value)
            ->where('company_id', $this->company->getKey())
            ->latest('id')
            ->first();
        $this->assertNotNull($log);
    }

    public function test_update_section_rejects_unknown_section(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);
        $action = app(UpdateProductPassportSectionAction::class);

        $this->expectException(ValidationException::class);

        $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            'nonexistent_section',
            ['field' => 'value'],
            1,
        );
    }

    public function test_update_section_rejects_invalid_field_data(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);
        $action = app(UpdateProductPassportSectionAction::class);

        $this->expectException(ValidationException::class);

        $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            DppSectionKey::UsageAndCare->value,
            ['unknown_field' => 'bad'],
            1,
        );
    }

    public function test_update_section_returns_409_on_stale_revision(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);
        $action = app(UpdateProductPassportSectionAction::class);

        $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'Step 1.'],
            1,
        );

        $this->expectException(ConflictHttpException::class);

        $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'Step 2.'],
            1,
        );
    }

    // ── Update settings ───────────────────────────────────────────

    public function test_settings_can_disable_optional_sections(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);
        $action = app(UpdateProductPassportSettingsAction::class);

        $optionalKeys = array_values(array_map(
            fn ($s) => $s->value,
            array_filter(DppSectionKey::cases(), fn ($s) => $s->isOptional()),
        ));

        $result = $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            ['enabled_sections' => array_values(array_diff(
                array_map(fn ($s) => $s->value, DppSectionKey::cases()),
                [$optionalKeys[0]],
            ))],
            1,
        );

        $payload = $result->currentDraftVersion->payload;
        $this->assertNotContains($optionalKeys[0], $payload['enabled_sections']);
    }

    public function test_settings_cannot_disable_core_sections(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);
        $action = app(UpdateProductPassportSettingsAction::class);

        $coreKeys = array_map(
            fn ($s) => $s->value,
            array_filter(DppSectionKey::cases(), fn ($s) => $s->isCore()),
        );

        $nonCoreOnly = array_diff(
            array_map(fn ($s) => $s->value, DppSectionKey::cases()),
            $coreKeys,
        );

        $this->expectException(ValidationException::class);

        $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            ['enabled_sections' => array_values($nonCoreOnly)],
            1,
        );
    }

    public function test_settings_revision_increments(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);
        $action = app(UpdateProductPassportSettingsAction::class);

        $result = $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            ['enabled_sections' => array_map(fn ($s) => $s->value, DppSectionKey::cases())],
            1,
        );

        $this->assertSame(2, $result->currentDraftVersion->draft_revision);
    }

    // ── Sync documents ────────────────────────────────────────────

    public function test_sync_documents_accepts_valid_document_references(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);

        $document = ProductDocument::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $product->getKey(),
            'status' => ProductDocumentStatus::Active->value,
            'created_by_user_id' => $this->actor->getKey(),
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
            'title' => 'Test',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'storage_key' => 'test/'.fake()->uuid().'.pdf',
            'created_by_user_id' => $this->actor->getKey(),
        ])->save();

        $document->forceFill(['current_version_id' => $version->getKey()])->save();

        $action = app(SyncProductPassportDocumentsAction::class);
        $result = $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            [
                [
                    'document_uuid' => $document->uuid,
                    'role' => 'instruction',
                ],
            ],
            1,
        );

        $payload = $result->currentDraftVersion->payload;
        $this->assertCount(1, $payload['document_references']);
        $this->assertSame($document->uuid, $payload['document_references'][0]['document_uuid']);
    }

    public function test_sync_documents_rejects_foreign_product_documents(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);

        $otherProduct = $this->createProduct();

        $document = ProductDocument::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $otherProduct->getKey(),
            'status' => ProductDocumentStatus::Active->value,
            'created_by_user_id' => $this->actor->getKey(),
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
            'title' => 'Test',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'storage_key' => 'test/'.fake()->uuid().'.pdf',
            'created_by_user_id' => $this->actor->getKey(),
        ])->save();

        $document->forceFill(['current_version_id' => $version->getKey()])->save();

        $action = app(SyncProductPassportDocumentsAction::class);

        $this->expectException(ValidationException::class);

        $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            [
                [
                    'document_uuid' => $document->uuid,
                    'role' => 'instruction',
                ],
            ],
            1,
        );
    }

    public function test_sync_documents_rejects_inactive_documents(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);

        $document = ProductDocument::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $product->getKey(),
            'status' => ProductDocumentStatus::Archived->value,
            'created_by_user_id' => $this->actor->getKey(),
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
            'title' => 'Test',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'storage_key' => 'test/'.fake()->uuid().'.pdf',
            'created_by_user_id' => $this->actor->getKey(),
        ])->save();

        $document->forceFill(['current_version_id' => $version->getKey()])->save();

        $action = app(SyncProductPassportDocumentsAction::class);

        $this->expectException(ValidationException::class);

        $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            [
                [
                    'document_uuid' => $document->uuid,
                    'role' => 'instruction',
                ],
            ],
            1,
        );
    }

    // ── Reset section ─────────────────────────────────────────────

    public function test_reset_section_clears_non_translatable_section_data(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);

        $updateAction = app(UpdateProductPassportSectionAction::class);
        $updateAction->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            DppSectionKey::OriginAndTraceability->value,
            ['country_of_origin' => 'SE'],
            1,
        );

        $resetAction = app(ResetProductPassportSectionAction::class);
        $result = $resetAction->handle(
            $this->actor,
            $this->company,
            $product,
            $passport->fresh(),
            DppSectionKey::OriginAndTraceability->value,
            2,
        );

        $payload = $result->currentDraftVersion->payload;
        $this->assertArrayNotHasKey(
            DppSectionKey::OriginAndTraceability->value,
            $payload['data'] ?? [],
        );
    }

    public function test_reset_section_clears_translatable_section_data(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);
        $locale = $passport->default_language;

        $updateAction = app(UpdateProductPassportSectionAction::class);
        $updateAction->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'Step 1.'],
            1,
        );

        $resetAction = app(ResetProductPassportSectionAction::class);
        $result = $resetAction->handle(
            $this->actor,
            $this->company,
            $product,
            $passport->fresh(),
            DppSectionKey::UsageAndCare->value,
            2,
        );

        $payload = $result->currentDraftVersion->payload;
        $this->assertArrayNotHasKey(
            DppSectionKey::UsageAndCare->value,
            $payload['translations'][$locale] ?? [],
        );
    }

    public function test_reset_section_clears_document_references_when_resetting_certifications(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);

        $document = ProductDocument::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $product->getKey(),
            'status' => ProductDocumentStatus::Active->value,
            'created_by_user_id' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $version = new ProductDocumentVersion;
        $version->forceFill([
            'uuid' => fake()->uuid(),
            'company_id' => $this->company->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'document_type' => ProductDocumentType::Certificate->value,
            'title' => 'Test Cert',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
            'original_filename' => 'cert.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'storage_key' => 'test/'.fake()->uuid().'.pdf',
            'created_by_user_id' => $this->actor->getKey(),
        ])->save();

        $document->forceFill(['current_version_id' => $version->getKey()])->save();

        $syncAction = app(SyncProductPassportDocumentsAction::class);
        $passport = $syncAction->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            [[
                'document_uuid' => $document->uuid,
                'role' => 'certificate',
            ]],
            1,
        );

        $resetAction = app(ResetProductPassportSectionAction::class);
        $result = $resetAction->handle(
            $this->actor,
            $this->company,
            $product,
            $passport->fresh(),
            DppSectionKey::CertificationsAndDocuments->value,
            2,
        );

        $payload = $result->currentDraftVersion->payload;
        $this->assertEmpty($payload['document_references']);
    }

    public function test_reset_section_cannot_reset_core_sections(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);
        $action = app(ResetProductPassportSectionAction::class);

        $this->expectException(ValidationException::class);

        $action->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            DppSectionKey::Identity->value,
            1,
        );
    }

    public function test_reset_section_revision_increments(): void
    {
        $product = $this->createProduct();
        $passport = $this->createDraftPassport($product);

        $updateAction = app(UpdateProductPassportSectionAction::class);
        $updateAction->handle(
            $this->actor,
            $this->company,
            $product,
            $passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'Step 1.'],
            1,
        );

        $resetAction = app(ResetProductPassportSectionAction::class);
        $result = $resetAction->handle(
            $this->actor,
            $this->company,
            $product,
            $passport->fresh(),
            DppSectionKey::UsageAndCare->value,
            2,
        );

        $this->assertSame(3, $result->currentDraftVersion->draft_revision);
    }
}
