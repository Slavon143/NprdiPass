<?php

namespace Tests\Feature\Passports\Audit;

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

class DppAuditTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

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

        $this->product = new Product;
        $this->product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Audit Product '.fake()->unique()->word(),
            'slug' => 'audit-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'audit-product-'.fake()->unique()->slug(1),
            'status' => ProductStatus::Active,
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();
        $this->product->refresh();

        $this->passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );
    }

    private function currentDraftRevision(): int
    {
        return $this->passport->fresh()->currentDraftVersion->draft_revision;
    }

    public function test_passport_created_event_fired_on_creation(): void
    {
        $log = AuditLog::query()
            ->where('event', AuditEvent::PassportCreated->value)
            ->where('company_id', $this->company->getKey())
            ->first();

        $this->assertNotNull($log);
    }

    public function test_passport_draft_updated_event_fired_on_section_update(): void
    {
        $countBefore = AuditLog::query()
            ->where('event', AuditEvent::PassportDraftUpdated->value)
            ->count();

        app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'Audit test.'],
            $this->currentDraftRevision(),
        );

        $countAfter = AuditLog::query()
            ->where('event', AuditEvent::PassportDraftUpdated->value)
            ->count();

        $this->assertSame($countBefore + 1, $countAfter);
    }

    public function test_passport_draft_updated_event_fired_on_settings_update(): void
    {
        $countBefore = AuditLog::query()
            ->where('event', AuditEvent::PassportDraftUpdated->value)
            ->count();

        app(UpdateProductPassportSettingsAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->passport,
            ['enabled_sections' => array_map(fn ($s) => $s->value, DppSectionKey::cases())],
            $this->currentDraftRevision(),
        );

        $countAfter = AuditLog::query()
            ->where('event', AuditEvent::PassportDraftUpdated->value)
            ->count();

        $this->assertSame($countBefore + 1, $countAfter);
    }

    public function test_passport_draft_updated_event_fired_on_document_sync(): void
    {
        $document = ProductDocument::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
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
            'title' => 'Audit Doc',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
            'original_filename' => 'audit.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'storage_key' => 'test/'.fake()->uuid().'.pdf',
            'created_by_user_id' => $this->actor->getKey(),
        ])->save();

        $document->forceFill(['current_version_id' => $version->getKey()])->save();

        $countBefore = AuditLog::query()
            ->where('event', AuditEvent::PassportDraftUpdated->value)
            ->count();

        app(SyncProductPassportDocumentsAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->passport,
            [[
                'document_uuid' => $document->uuid,
                'role' => 'instruction',
            ]],
            $this->currentDraftRevision(),
        );

        $countAfter = AuditLog::query()
            ->where('event', AuditEvent::PassportDraftUpdated->value)
            ->count();

        $this->assertSame($countBefore + 1, $countAfter);
    }

    public function test_passport_draft_updated_event_fired_on_section_reset(): void
    {
        $revision = $this->currentDraftRevision();

        app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'Pre-reset.'],
            $revision,
        );

        $countBefore = AuditLog::query()
            ->where('event', AuditEvent::PassportDraftUpdated->value)
            ->count();

        app(ResetProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->passport->fresh(),
            DppSectionKey::UsageAndCare->value,
            $this->currentDraftRevision(),
        );

        $countAfter = AuditLog::query()
            ->where('event', AuditEvent::PassportDraftUpdated->value)
            ->count();

        $this->assertSame($countBefore + 1, $countAfter);
    }

    public function test_no_audit_event_on_failed_validation(): void
    {
        $countBefore = AuditLog::query()->count();

        try {
            app(UpdateProductPassportSectionAction::class)->handle(
                $this->actor,
                $this->company,
                $this->product,
                $this->passport,
                'nonexistent',
                ['field' => 'value'],
                $this->currentDraftRevision(),
            );
        } catch (ValidationException) {
        }

        $this->assertSame($countBefore, AuditLog::query()->count());
    }

    public function test_no_audit_event_on_conflict(): void
    {
        $revision = $this->currentDraftRevision();

        app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'First update.'],
            $revision,
        );

        $countBefore = AuditLog::query()->count();

        try {
            app(UpdateProductPassportSectionAction::class)->handle(
                $this->actor,
                $this->company,
                $this->product,
                $this->passport,
                DppSectionKey::UsageAndCare->value,
                ['usage_instructions' => 'Conflict update.'],
                $revision,
            );
        } catch (ConflictHttpException) {
        }

        $this->assertSame($countBefore, AuditLog::query()->count());
    }

    public function test_audit_metadata_contains_product_passport_and_draft_uuid(): void
    {
        $passport = $this->passport->fresh();

        $log = AuditLog::query()
            ->where('event', AuditEvent::PassportCreated->value)
            ->where('company_id', $this->company->getKey())
            ->first();

        $this->assertNotNull($log);

        $properties = $log->properties->toArray();
        $this->assertArrayHasKey('product_uuid', $properties);
        $this->assertArrayHasKey('passport_uuid', $properties);
        $this->assertArrayHasKey('draft_version_uuid', $properties);
        $this->assertSame($this->product->uuid, $properties['product_uuid']);
        $this->assertSame($passport->uuid, $properties['passport_uuid']);
        $this->assertSame($passport->currentDraftVersion->uuid, $properties['draft_version_uuid']);
    }

    public function test_audit_does_not_contain_full_payload_data(): void
    {
        app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'Sensitive content here.'],
            $this->currentDraftRevision(),
        );

        $log = AuditLog::query()
            ->where('event', AuditEvent::PassportDraftUpdated->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $properties = $log->properties->toArray();

        $this->assertArrayNotHasKey('payload', $properties);
        $this->assertArrayNotHasKey('section_data', $properties);
        $this->assertArrayNotHasKey('full_payload', $properties);
    }
}
