<?php

namespace Tests\Feature\Passports\Publication;

use App\Actions\Approvals\ApprovePassportPublicationApprovalAction;
use App\Actions\Approvals\RequestPassportPublicationApprovalAction;
use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\RecordPassportValidationRun;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Actions\Passports\UpdateProductPassportSettingsAction;
use App\Audit\AuditLogger;
use App\Enums\Approvals\PassportPublicationApprovalStatus;
use App\Enums\AuditEvent;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Models\Approvals\PassportPublicationApproval;
use App\Models\AuditLog;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\DppProfile;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Passports\DppPayloadNormalizer;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Tenancy\Contracts\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PublishGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    private Category $category;

    private ProductVariant $defaultVariant;

    private ProductMedia $primaryMedia;

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
        Storage::disk('catalog_media')->put('test/test-image.jpg', 'fake content');

        $this->category = new Category;
        $this->category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Test Category',
            'slug' => 'test-category-'.fake()->unique()->slug(1),
            'slug_normalized' => 'test-category-'.fake()->unique()->slug(1),
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
            'name' => 'Publish Gate Product '.fake()->unique()->word(),
            'slug' => 'publish-gate-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'publish-gate-product-'.fake()->unique()->slug(1),
            'brand' => 'Test Brand',
            'manufacturer' => 'Test Manufacturer',
            'description' => 'Test product description.',
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
            'sku' => 'SKU-TEST-001',
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
            'original_filename' => 'test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/test-image.jpg',
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
    }

    private function createDraftPassport(): ProductPassport
    {
        $passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $passport->currentDraftVersion->draft_revision;

        return $passport;
    }

    private function fillSection(DppSectionKey $section, array $payload): ProductPassport
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

    private function fillCoreSections(): void
    {
        $this->createDraftPassport();

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Test Product Name',
            'public_description' => 'A test product description for publication.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Test Manufacturer Inc.',
            'responsible_operator_display_name' => 'Test Operator',
            'contact_notes' => 'Contact during business hours.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Keep away from water', 'Handle with care'],
            'storage_instructions' => 'Store in a dry place.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Disassemble and sort by material type.',
        ]);
    }

    private function fillAllSections(): void
    {
        $this->fillCoreSections();

        $this->fillSection(DppSectionKey::MaterialsAndComposition, [
            'materials' => [
                ['name' => 'Plastic', 'percentage' => 60.0, 'recycled_content_percentage' => 30.0, 'hazardous' => false],
                ['name' => 'Steel', 'percentage' => 40.0, 'recycled_content_percentage' => 50.0, 'hazardous' => false],
            ],
        ]);

        $this->fillSection(DppSectionKey::EnvironmentalInformation, [
            'carbon_footprint_kg_co2e' => 12.5,
            'expected_lifetime_years' => 5.0,
        ]);

        $this->fillSection(DppSectionKey::UsageAndCare, [
            'usage_instructions' => 'Plug in and press power button.',
            'care_instructions' => 'Clean with a dry cloth.',
        ]);

        $this->fillSection(DppSectionKey::RepairAndSpareParts, [
            'repair_instructions' => 'Contact authorized service center.',
            'spare_parts_notes' => 'Spare parts available through authorized dealers.',
        ]);

        $this->fillSection(DppSectionKey::SupportAndContact, [
            'warranty_summary' => '2-year limited warranty.',
            'support_notes' => 'Contact support for assistance.',
        ]);
    }

    private function injectManufacturerContact(): void
    {
        $draft = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->currentDraftVersion;

        $normalizer = app(DppPayloadNormalizer::class);
        $payload = $draft->payload;

        if (! isset($payload['data'])) {
            $payload['data'] = [];
        }
        if (! isset($payload['data']['manufacturer_and_operator'])) {
            $payload['data']['manufacturer_and_operator'] = [];
        }
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@test-mfg.example';

        $normalized = $normalizer->normalize($payload);

        $newRevision = $draft->draft_revision + 1;
        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $newRevision);
        $draft->setAttribute('updated_by', $this->actor->getKey());
        $draft->save();

        $this->revision = $newRevision;
    }

    private function publishAction(): PublishProductPassport
    {
        return app(PublishProductPassport::class);
    }

    private function draftPassport(): ProductPassport
    {
        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        return $passport->fresh(['currentDraftVersion']);
    }

    public function test_not_ready_passport_cannot_publish(): void
    {
        $passport = $this->createDraftPassport();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The passport is not ready for publication.');

        $this->publishAction()->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
        );
    }

    public function test_ready_with_warnings_without_acknowledgment_returns_422(): void
    {
        $this->fillCoreSections();
        $passport = $this->draftPassport();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The passport has warnings. Acknowledge them to proceed.');

        $this->publishAction()->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            false,
        );
    }

    public function test_ready_with_warnings_with_acknowledgment_publishes(): void
    {
        $this->fillCoreSections();
        $passport = $this->draftPassport();

        $result = $this->publishAction()->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $this->assertSame(ProductPassportStatus::Published, $result->passport->status);
        $this->assertSame(ProductPassportVersionStatus::Published, $result->publishedVersion->status);
        $this->assertNotNull($result->publishedVersion->version_number);
        $this->assertNotNull($result->publishedVersion->published_at);
        $this->assertNotNull($result->passport->current_published_version_id);
    }

    public function test_ready_passport_publishes(): void
    {
        $this->fillAllSections();
        $passport = $this->draftPassport();

        $result = $this->publishAction()->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $this->assertSame(ProductPassportStatus::Published, $result->passport->status);
        $this->assertSame(ProductPassportVersionStatus::Published, $result->publishedVersion->status);
        $this->assertNotNull($result->publishedVersion->version_number);
        $this->assertNotNull($result->passport->current_published_version_id);
    }

    public function test_editor_requests_publication_approval_and_owner_approves(): void
    {
        $this->fillCoreSections();
        $passport = $this->draftPassport();

        CompanyMembership::query()
            ->where('company_id', $this->company->getKey())
            ->where('user_id', $this->actor->getKey())
            ->update(['role' => CompanyRole::Editor->value, 'is_owner' => false]);

        $this->expectException(AuthorizationException::class);

        try {
            $this->publishAction()->handle(
                $this->actor,
                $this->company,
                $this->product,
                $passport,
                $this->revision,
                true,
            );
        } finally {
            $approval = app(RequestPassportPublicationApprovalAction::class)->handle(
                $this->actor,
                $this->company,
                $this->product,
                $passport,
                $this->revision,
                true,
            );

            $this->assertSame(PassportPublicationApprovalStatus::Pending, $approval->status);
            $this->assertSame($this->actor->getKey(), $approval->requested_by);

            $owner = User::factory()->create(['email_verified_at' => now()]);
            CompanyMembership::factory()->create([
                'company_id' => $this->company->getKey(),
                'user_id' => $owner->getKey(),
                'role' => CompanyRole::Owner,
            ]);

            $result = app(ApprovePassportPublicationApprovalAction::class)->handle(
                $owner,
                $this->company,
                $this->product,
                $passport->fresh(['currentDraftVersion']),
                $approval,
            );

            $approval = $approval->fresh();

            $this->assertSame(ProductPassportStatus::Published, $result->passport->status);
            $this->assertSame(PassportPublicationApprovalStatus::Approved, $approval->status);
            $this->assertSame($owner->getKey(), $approval->approved_by);
            $this->assertSame($result->publishedVersion->getKey(), $approval->published_version_id);
        }
    }

    public function test_requester_cannot_approve_their_own_publication_request(): void
    {
        $this->fillCoreSections();
        $passport = $this->draftPassport();

        $approval = app(RequestPassportPublicationApprovalAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Publication must be approved by a different company member.');

        app(ApprovePassportPublicationApprovalAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $approval,
        );
    }

    public function test_readiness_is_recalculated_in_transaction(): void
    {
        $this->fillAllSections();
        $passport = $this->draftPassport();

        $revisionBeforeEdit = $this->revision;

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Updated after readiness was loaded'],
            'storage_instructions' => 'Updated storage instructions.',
        ]);

        $passport = $this->draftPassport();
        $result = $this->publishAction()->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $this->assertSame(ProductPassportStatus::Published, $result->passport->status);
        $this->assertGreaterThan($revisionBeforeEdit, $result->publishedVersion->draft_revision);
    }

    public function test_expected_revision_required(): void
    {
        $passport = $this->createDraftPassport();

        $this->expectException(ConflictHttpException::class);

        $this->publishAction()->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            0,
        );
    }

    public function test_stale_revision_returns_409(): void
    {
        $this->fillAllSections();
        $passport = $this->draftPassport();

        $this->expectException(ConflictHttpException::class);

        $this->publishAction()->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            999,
        );
    }

    public function test_rejected_publication_creates_no_version(): void
    {
        $passport = $this->createDraftPassport();

        $versionCountBefore = ProductPassportVersion::query()->count();

        try {
            $this->publishAction()->handle(
                $this->actor,
                $this->company,
                $this->product,
                $passport,
                $this->revision,
            );
        } catch (ValidationException) {
        }

        $versionCountAfter = ProductPassportVersion::query()->count();

        $this->assertSame($versionCountBefore, $versionCountAfter);
    }

    public function test_rejected_publication_creates_no_audit(): void
    {
        $passport = $this->createDraftPassport();

        $auditCountBefore = AuditLog::query()
            ->where('company_id', $this->company->getKey())
            ->count();

        try {
            $this->publishAction()->handle(
                $this->actor,
                $this->company,
                $this->product,
                $passport,
                $this->revision,
            );
        } catch (ValidationException) {
        }

        $auditCountAfter = AuditLog::query()
            ->where('company_id', $this->company->getKey())
            ->count();

        $this->assertSame($auditCountBefore, $auditCountAfter);

        $publishedEvent = AuditLog::query()
            ->where('event', AuditEvent::PassportPublished->value)
            ->where('company_id', $this->company->getKey())
            ->first();

        $this->assertNull($publishedEvent);
    }

    public function test_published_passport_creates_audit_event(): void
    {
        $this->fillAllSections();
        $passport = $this->draftPassport();

        $result = $this->publishAction()->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $log = AuditLog::query()
            ->where('event', AuditEvent::PassportPublished->value)
            ->where('company_id', $this->company->getKey())
            ->first();

        $this->assertNotNull($log);

        $properties = $log->properties->toArray();
        $this->assertArrayHasKey('product_uuid', $properties);
        $this->assertArrayHasKey('passport_uuid', $properties);
        $this->assertArrayHasKey('published_version_uuid', $properties);
        $this->assertArrayHasKey('version_number', $properties);
    }

    public function test_approval_write_failure_rolls_back_publication_and_assets(): void
    {
        $this->fillCoreSections();
        $passport = $this->draftPassport();
        $approval = app(RequestPassportPublicationApprovalAction::class)->handle(
            $this->actor, $this->company, $this->product, $passport, $this->revision, true,
        );
        $owner = User::factory()->create(['email_verified_at' => now()]);
        CompanyMembership::factory()->create([
            'company_id' => $this->company->getKey(), 'user_id' => $owner->getKey(), 'role' => CompanyRole::Owner,
        ]);
        PassportPublicationApproval::updating(function ($approval): void {
            if ($approval->status === PassportPublicationApprovalStatus::Approved) {
                throw new \RuntimeException('approval storage failure');
            }
        });
        $filesBefore = Storage::disk('passport_assets')->allFiles();
        try {
            app(ApprovePassportPublicationApprovalAction::class)->handle($owner, $this->company, $this->product, $passport, $approval);
            $this->fail('Approval write failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('approval storage failure', $exception->getMessage());
            $this->assertSame(PassportPublicationApprovalStatus::Pending, $approval->fresh()->status);
            $this->assertNull($passport->fresh()->current_published_version_id);
            $this->assertSame($filesBefore, Storage::disk('passport_assets')->allFiles());
        }
    }

    public function test_changed_regulatory_requirements_block_until_explicit_refresh_without_rewriting_history(): void
    {
        $profile = DppProfile::query()->where('key', 'generic-espr')->where('version', 1)->sole();
        $this->product->dppProfiles()->attach($profile);
        $this->fillAllSections();
        $passport = $this->draftPassport();
        $builder = app(ReadinessContextBuilder::class);
        $evaluator = app(PassportReadinessEvaluator::class);
        $before = $evaluator->evaluate($builder->build($this->company, $this->product->fresh()));
        $run = app(RecordPassportValidationRun::class)->handle(
            $this->company, $passport, $passport->currentDraftVersion, $before, $this->actor,
        );
        $history = $run->fresh()->profile_snapshot;
        $profile->requirements()->create(['key' => 'future.unknown', 'title' => 'Future evidence',
            'level' => 'required', 'rule_code' => 'future.unknown', 'effective_from' => now()->addYear()->toDateString(),
            'source' => 'Synthetic test requirement', 'source_version' => '1']);

        $files = Storage::disk('passport_assets')->allFiles();
        try {
            $this->publishAction()->handle($this->actor, $this->company, $this->product, $passport, $this->revision, true);
            $this->fail('Changed regulatory requirements must block publication.');
        } catch (ValidationException $exception) {
            $this->assertContains('regulatory.profile.current', $exception->errors()['blockers']);
        }
        $this->assertSame($files, Storage::disk('passport_assets')->allFiles());
        $this->assertNull($passport->fresh()->current_published_version_id);
        $this->get(route('catalog.products.passport.readiness', $this->product->uuid))->assertOk()
            ->assertSee(__('dpp.refresh'));
        $this->post(route('catalog.products.passport.readiness.context', $this->product->uuid), [
            'expected_revision' => $this->revision, 'refresh_profile' => '1',
        ])->assertRedirect(route('catalog.products.passport.readiness', $this->product->uuid));
        $updated = $passport->fresh(['currentDraftVersion']);
        $this->assertSame($this->revision + 1, $updated->currentDraftVersion->draft_revision);
        $this->assertSame($history, $run->fresh()->profile_snapshot);
        $result = $this->publishAction()->handle($this->actor, $this->company, $this->product,
            $updated, $updated->currentDraftVersion->draft_revision, true);
        $future = collect($result->readinessResult->rules)->firstWhere('requirementKey', 'future.unknown');
        $this->assertSame(ReadinessRuleStatus::NotApplicable, $future->status);
        $this->assertSame('1', $future->safeContext['requirement_source_version']);
    }

    public function test_profile_refresh_audit_failure_rolls_back_classification_revision_and_snapshot(): void
    {
        $passport = $this->createDraftPassport();
        $before = $passport->fresh()->currentDraftVersion->getAttributes();
        $logger = \Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('logTenant')->once()->andThrow(new \RuntimeException('audit storage unavailable'));
        $this->app->instance(AuditLogger::class, $logger);
        try {
            app(UpdateProductPassportSettingsAction::class)->handle($this->actor, $this->company, $this->product, $passport, [
                'refresh_profile' => true, 'regulatory_context' => [
                    'market' => 'EU', 'product_type' => 'electrical', 'passport_level' => 'model', 'assessment_source' => 'Test evidence',
                ],
            ], $this->revision);
            $this->fail('Audit failure must roll back the refresh.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit storage unavailable', $exception->getMessage());
        }
        $this->assertSame($before, $passport->fresh()->currentDraftVersion->getAttributes());
    }

    public function test_profile_settings_reject_a_foreign_company_passport_before_mutation(): void
    {
        $passport = $this->createDraftPassport();
        $other = Company::factory()->create();
        $forged = (new ProductPassport)->forceFill(['id' => $passport->id,
            'company_id' => $other->id, 'product_id' => $this->product->id]);
        try {
            app(UpdateProductPassportSettingsAction::class)->handle($this->actor, $this->company, $this->product,
                $forged, ['refresh_profile' => true], $this->revision);
            $this->fail('A foreign-company passport must be rejected.');
        } catch (NotFoundHttpException) {
            $this->assertSame($this->revision, $passport->fresh()->currentDraftVersion->draft_revision);
        }
    }

    public function test_a_frozen_future_requirement_becomes_a_blocker_on_its_application_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-02-17 12:00:00'));
        $profile = DppProfile::query()->where('key', 'generic-espr')->where('version', 1)->sole();
        $profile->requirements()->create(['key' => 'dated.missing', 'title' => 'Dated evidence',
            'level' => 'required', 'rule_code' => 'dated.missing', 'effective_from' => '2027-02-18']);
        $this->product->dppProfiles()->attach($profile);
        $this->fillAllSections();
        $passport = $this->draftPassport();
        $first = $this->publishAction()->handle($this->actor, $this->company, $this->product, $passport, $this->revision, true);
        $snapshot = $first->publishedVersion->fresh()->payload;
        $this->travelTo(CarbonImmutable::parse('2027-02-18 00:00:00'));
        try {
            $this->publishAction()->handle($this->actor, $this->company, $this->product,
                $first->passport->fresh(), $first->newDraftVersion->draft_revision, true);
            $this->fail('The newly applicable mandatory requirement must block.');
        } catch (ValidationException $exception) {
            $this->assertContains('dated.missing', $exception->errors()['blockers']);
        }
        $this->assertSame($snapshot, $first->publishedVersion->fresh()->payload);
    }

    public function test_battery_preparation_can_publish_before_deadline_but_incomplete_mandatory_coverage_cannot(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-02-17'));
        $this->fillAllSections();
        $passport = app(UpdateProductPassportSettingsAction::class)->handle(
            $this->actor, $this->company, $this->product, $this->draftPassport(), ['regulatory_context' => [
                'market' => 'EU', 'product_type' => 'lmt_battery', 'battery_capacity_kwh' => 0.5,
                'passport_level' => 'item', 'model_identifier' => 'TEST-MODEL',
                'item_identifier' => 'TEST-ONLY-INSTANCE', 'assessment_source' => 'Synthetic battery acceptance only',
                'market_event_date' => '2027-02-18',
            ]], $this->revision,
        );
        $first = $this->publishAction()->handle($this->actor, $this->company, $this->product,
            $passport, $passport->currentDraftVersion->draft_revision, true);
        $this->assertSame('upcoming', $first->readinessResult->profileDetails['regulatory_scope']['status']);
        $snapshot = $first->publishedVersion->fresh()->payload;
        $this->travelTo(CarbonImmutable::parse('2027-02-18'));
        try {
            $this->publishAction()->handle($this->actor, $this->company, $this->product,
                $first->passport->fresh(), $first->newDraftVersion->draft_revision, true);
            $this->fail('Warning acknowledgement must not bypass battery coverage.');
        } catch (ValidationException $exception) {
            $this->assertContains('regulatory.battery.coverage', $exception->errors()['blockers']);
        }
        $this->assertSame($snapshot, $first->publishedVersion->fresh()->payload);
        $this->assertFalse($first->readinessResult->profileDetails['regulatory_scope']['registry_required']);
    }

    public function test_classification_form_uses_revision_and_current_company_authorization(): void
    {
        $passport = $this->createDraftPassport();
        $url = route('catalog.products.passport.readiness.context', $this->product->uuid);
        $data = ['expected_revision' => $this->revision, 'regulatory_context' => [
            'market' => 'EU', 'product_type' => 'electrical', 'passport_level' => 'model',
            'assessment_source' => 'Manufacturer catalogue',
        ]];
        $this->post($url, $data)->assertRedirect(route('catalog.products.passport.readiness', $this->product->uuid));
        $this->assertSame('electrical', $passport->fresh()->currentDraftVersion->payload['regulatory_context']['product_type']);
        $this->get(route('catalog.products.passport.readiness', $this->product->uuid))->assertOk()
            ->assertSee('data-testid="regulatory-context"', false)
            ->assertSee('regulatory_context[market_event_date]', false);
        $revision = $passport->fresh()->currentDraftVersion->draft_revision;
        $this->post($url, $data)->assertSessionHas('error');
        $this->assertSame($revision, $passport->fresh()->currentDraftVersion->draft_revision);
        CompanyMembership::query()->where('company_id', $this->company->id)->where('user_id', $this->actor->id)
            ->update(['role' => CompanyRole::Viewer]);
        $this->post($url, array_replace($data, ['expected_revision' => $revision]))->assertForbidden();
        $this->assertSame($revision, $passport->fresh()->currentDraftVersion->draft_revision);
    }

    public function test_classification_payload_cannot_forge_tenant_and_is_not_logged_in_audit(): void
    {
        $passport = $this->createDraftPassport();
        $action = app(UpdateProductPassportSettingsAction::class);
        $facts = ['market' => 'EU', 'product_type' => 'electrical', 'passport_level' => 'model',
            'assessment_source' => 'PRIVATE SOURCE TEXT'];
        try {
            $action->handle($this->actor, $this->company, $this->product, $passport,
                ['regulatory_context' => $facts + ['company_id' => 999]], $this->revision);
            $this->fail('Unknown regulatory context fields must be rejected.');
        } catch (ValidationException) {
            $this->assertSame($this->revision, $passport->fresh()->currentDraftVersion->draft_revision);
        }
        $action->handle($this->actor, $this->company, $this->product, $passport, ['regulatory_context' => $facts], $this->revision);
        $log = AuditLog::query()->where('company_id', $this->company->id)->where('event', AuditEvent::PassportDraftUpdated->value)->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('PRIVATE SOURCE TEXT', json_encode($log->properties));
    }

    public function test_public_json_uses_only_published_public_fields_and_distinct_cache_variants(): void
    {
        $this->fillAllSections();
        $passport = app(UpdateProductPassportSettingsAction::class)->handle(
            $this->actor, $this->company, $this->product, $this->draftPassport(), ['regulatory_context' => [
                'market' => 'EU', 'product_type' => 'electrical', 'passport_level' => 'model',
                'assessment_source' => 'PRIVATE CLASSIFICATION EVIDENCE',
            ]], $this->revision,
        );
        $result = $this->publishAction()->handle($this->actor, $this->company, $this->product,
            $passport, $passport->currentDraftVersion->draft_revision, true);
        $url = '/p/'.$result->passport->public_id;
        $html = $this->get($url)->assertOk();
        $json = $this->getJson($url)->assertOk()->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Vary', 'Accept, Accept-Encoding')
            ->assertJsonPath('format', 'nordipass-public-passport-v1')
            ->assertJsonPath('sections.identity.public_name', 'Test Product Name');
        $body = $json->getContent();
        foreach (['PRIVATE CLASSIFICATION EVIDENCE', 'regulatory_context', 'readiness_evidence', 'company_id', 'storage_path', 'created_by'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }
        $this->assertNotSame($html->headers->get('ETag'), $json->headers->get('ETag'));
        $etag = $json->headers->get('ETag');
        $this->withHeaders(['If-None-Match' => $etag])->getJson($url)->assertStatus(304);
        $this->flushHeaders();
        $this->revision = $result->newDraftVersion->draft_revision;
        $this->fillSection(DppSectionKey::Identity, ['public_name' => 'PRIVATE NEW DRAFT', 'public_description' => 'Private draft']);
        $this->getJson($url)->assertOk()->assertHeader('ETag', $etag)
            ->assertJsonPath('sections.identity.public_name', 'Test Product Name');
    }
}
