<?php

namespace Tests\Feature\Passports\Readiness;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Data\Passports\Readiness\PassportReadinessResult;
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
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Passports\DppPayloadNormalizer;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Services\Passports\Readiness\ReadinessProfileRepository;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class ReadinessProfilesHistoricalReproducibilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

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

        View::share('currentCompany', $this->company);
        View::share('availableCompanies', new Collection([$this->company]));
        View::share('currentMembership', $this->actor->memberships()
            ->where('company_id', $this->company->getKey())
            ->first());
        View::share('slot', '');

        Storage::fake('catalog_media');
        Storage::disk('catalog_media')->put('test/historical.jpg', 'historical image content');

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Historical Readiness Category',
            'slug' => 'historical-readiness-'.fake()->unique()->slug(1),
            'slug_normalized' => 'historical-readiness-'.fake()->unique()->slug(1),
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
            'name' => 'Historical Readiness Product',
            'slug' => 'historical-readiness-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'historical-readiness-product-'.fake()->unique()->slug(1),
            'brand' => 'Historical Brand',
            'manufacturer' => 'Historical Manufacturer',
            'status' => ProductStatus::Active,
            'primary_category_id' => $category->getKey(),
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $variant = new ProductVariant;
        $variant->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'name' => 'Historical Default Variant',
            'sku' => 'SKU-HIST-001',
            'status' => ProductVariantStatus::Active,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $media = new ProductMedia;
        $media->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'original_filename' => 'historical.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/historical.jpg',
            'checksum_sha256' => str_repeat('b', 64),
            'sort_order' => 0,
            'uploaded_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->product->forceFill([
            'default_variant_id' => $variant->getKey(),
            'primary_media_id' => $media->getKey(),
        ])->save();

        $this->product->categories()->attach($category->getKey(), [
            'company_id' => $this->company->getKey(),
            'created_at' => now(),
        ]);
    }

    public function test_historical_v1_publication_evidence_survives_active_v2_profile_change(): void
    {
        $this->createReadyPassportDraft();

        $passport = $this->passport();
        $v1Draft = $passport->currentDraftVersion;
        $v1Result = $this->evaluate();

        $this->assertSame('nordipass-pilot', $v1Result->profile);
        $this->assertSame(1, $v1Result->profileVersion);
        $this->assertSame('f668cbb32defc4b23420a129970ec9233c8cb330905898ce2206e37583611569', $v1Result->ruleSetFingerprint);

        $v1Publication = app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $v1Published = $v1Publication->publishedVersion->fresh(['validationRun.results']);
        $v1Run = $v1Published->validationRun;
        $v1Evidence = $v1Published->readiness_evidence;
        $v1ResultSnapshot = [
            'fingerprint' => $v1Run->rule_set_fingerprint,
            'earned_points' => $v1Run->earned_points,
            'applicable_points' => $v1Run->applicable_points,
            'score' => $v1Run->score,
            'passed_count' => $v1Run->passed_count,
            'blocker_count' => $v1Run->blocker_count,
            'warning_count' => $v1Run->warning_count,
            'recommendation_count' => $v1Run->recommendation_count,
            'not_applicable_count' => $v1Run->not_applicable_count,
        ];

        $this->activateSemanticV2Profile();

        $v1PublishedAfterV2 = ProductPassportVersion::query()
            ->with('validationRun')
            ->findOrFail($v1Published->getKey());

        $this->assertSame($v1Evidence, $v1PublishedAfterV2->readiness_evidence);
        $this->assertSame($v1ResultSnapshot['fingerprint'], $v1PublishedAfterV2->validationRun->rule_set_fingerprint);
        $this->assertSame($v1ResultSnapshot['earned_points'], $v1PublishedAfterV2->validationRun->earned_points);
        $this->assertSame($v1ResultSnapshot['applicable_points'], $v1PublishedAfterV2->validationRun->applicable_points);
        $this->assertSame($v1ResultSnapshot['score'], $v1PublishedAfterV2->validationRun->score);

        $passport = $v1Publication->passport->fresh(['currentDraftVersion']);
        $v2Profile = app(ReadinessProfileRepository::class)->active();
        $this->pinDraftToProfile($passport->currentDraftVersion, $v2Profile);

        $v2Result = $this->evaluate();

        $this->assertSame('nordipass-pilot', $v2Result->profile);
        $this->assertSame(2, $v2Result->profileVersion);
        $this->assertNotSame($v1ResultSnapshot['fingerprint'], $v2Result->ruleSetFingerprint);
        $this->assertSame(['blocker' => 10, 'warning' => 5, 'recommendation' => 2], $v2Result->scoreBreakdown->weights);
        $this->assertNotSame($v1ResultSnapshot['applicable_points'], $v2Result->scoreBreakdown->applicablePoints);

        $v2Publication = app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport->fresh(['currentDraftVersion']),
            $passport->fresh(['currentDraftVersion'])->currentDraftVersion->draft_revision,
            true,
        );

        $v2Published = $v2Publication->publishedVersion->fresh(['validationRun']);

        $this->assertSame(2, $v2Published->version_number);
        $this->assertSame(2, $v2Published->readiness_profile_version);
        $this->assertSame($v2Result->ruleSetFingerprint, $v2Published->readiness_rule_set_fingerprint);
        $this->assertSame($v2Result->ruleSetFingerprint, $v2Published->readiness_evidence['rule_set_fingerprint']);
        $this->assertSame($v2Result->scoreBreakdown->weights, $v2Published->readiness_evidence['weights']);

        $v1PublishedFinal = ProductPassportVersion::query()
            ->with('validationRun')
            ->findOrFail($v1Published->getKey());

        $this->assertSame(1, $v1PublishedFinal->readiness_profile_version);
        $this->assertSame($v1Evidence, $v1PublishedFinal->readiness_evidence);
        $this->assertSame($v1ResultSnapshot['fingerprint'], $v1PublishedFinal->validationRun->rule_set_fingerprint);
        $this->assertNotSame($v1PublishedFinal->readiness_rule_set_fingerprint, $v2Published->readiness_rule_set_fingerprint);
    }

    public function test_readiness_diagnostics_commands_report_profile_evidence_and_missing_records_safely(): void
    {
        $this->createReadyPassportDraft();

        $passport = $this->passport();

        $profileExitCode = Artisan::call('nordipass:readiness-profile', [
            'profile' => 'nordipass-pilot',
            'version' => 1,
        ]);

        $profileOutput = Artisan::output();
        $this->assertSame(0, $profileExitCode);
        $this->assertStringContainsString('f668cbb32defc4b23420a129970ec9233c8cb330905898ce2206e37583611569', $profileOutput);
        $this->assertStringContainsString('weighted_ratio', $profileOutput);

        $exitCode = Artisan::call('nordipass:readiness-diagnose', [
            'passportUuid' => $passport->uuid,
        ]);

        $this->assertSame(0, $exitCode);
        $diagnostic = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($passport->uuid, $diagnostic['passport_uuid']);
        $this->assertSame('nordipass-pilot', $diagnostic['profile']);
        $this->assertSame(1, $diagnostic['profile_version']);
        $this->assertSame('weighted_ratio', $diagnostic['score_algorithm']);
        $this->assertSame(1, $diagnostic['score_algorithm_version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $diagnostic['rule_set_fingerprint']);
        $this->assertArrayHasKey('score_breakdown', $diagnostic);
        $this->assertArrayHasKey('counts', $diagnostic);

        $missingExitCode = Artisan::call('nordipass:readiness-diagnose', [
            'passportUuid' => (string) str()->uuid(),
        ]);

        $this->assertSame(1, $missingExitCode);
        $this->assertStringContainsString('Passport not found.', Artisan::output());
    }

    private function createReadyPassportDraft(): void
    {
        $passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $passport->currentDraftVersion->draft_revision;

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Historical Product Name',
            'public_description' => 'Historical reproducibility test description.',
        ]);
        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Historical Mfg Inc.',
            'responsible_operator_display_name' => 'Historical Operator',
            'contact_notes' => 'Historical contact.',
        ]);
        $this->injectManufacturerContact();
        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Historical warning'],
            'storage_instructions' => 'Historical storage.',
        ]);
        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Historical recycling.',
        ]);
        $this->fillSection(DppSectionKey::MaterialsAndComposition, [
            'materials' => [
                ['name' => 'Polyester', 'percentage' => 100.0, 'recycled_content_percentage' => 40.0, 'hazardous' => false],
            ],
        ]);
        $this->fillSection(DppSectionKey::EnvironmentalInformation, [
            'carbon_footprint_kg_co2e' => 7.5,
        ]);
        $this->fillSection(DppSectionKey::UsageAndCare, [
            'usage_instructions' => 'Use as instructed.',
        ]);
        $this->fillSection(DppSectionKey::RepairAndSpareParts, [
            'repair_instructions' => 'Repair through approved provider.',
        ]);
        $this->fillSection(DppSectionKey::SupportAndContact, [
            'warranty_summary' => 'One-year warranty.',
        ]);
    }

    private function fillSection(DppSectionKey $section, array $payload): void
    {
        $result = app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->passport(),
            $section->value,
            $payload,
            $this->revision,
        );

        $this->revision = $result->currentDraftVersion->draft_revision;
    }

    private function injectManufacturerContact(): void
    {
        $draft = $this->passport()->currentDraftVersion;
        $payload = $draft->payload;
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@historical.example';

        $newRevision = $draft->draft_revision + 1;
        $draft->forceFill([
            'payload' => app(DppPayloadNormalizer::class)->normalize($payload),
            'draft_revision' => $newRevision,
            'updated_by' => $this->actor->getKey(),
        ])->save();

        $this->revision = $newRevision;
    }

    private function passport(): ProductPassport
    {
        return ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->firstOrFail()
            ->fresh(['currentDraftVersion', 'currentPublishedVersion']);
    }

    private function evaluate(): PassportReadinessResult
    {
        $this->product = $this->product->fresh();
        $context = app(ReadinessContextBuilder::class)->build($this->company, $this->product);

        return app(PassportReadinessEvaluator::class)->evaluate($context);
    }

    private function activateSemanticV2Profile(): void
    {
        $profiles = config('passport_readiness.profiles');
        $profiles['nordipass-pilot']['versions'][2] = array_replace_recursive(
            $profiles['nordipass-pilot']['versions'][1],
            [
                'status' => 'active',
                'rule_set_version' => 2,
                'weights' => [
                    'blocker' => 10,
                    'warning' => 5,
                    'recommendation' => 2,
                ],
                'metadata' => [
                    'migration_source' => 'R3.2 historical reproducibility acceptance fixture',
                    'legal_disclaimer' => 'Operational readiness only; not legal certification.',
                ],
            ],
        );

        $profiles['nordipass-pilot']['versions'][1]['status'] = 'deprecated';

        config()->set('passport_readiness.profiles', $profiles);
        config()->set('passport_readiness.profile_version', 2);
    }

    private function pinDraftToProfile(ProductPassportVersion $draft, object $profile): void
    {
        $draft->forceFill([
            'readiness_profile' => $profile->code,
            'readiness_profile_version' => $profile->version,
            'readiness_rule_set_fingerprint' => $profile->fingerprint,
            'updated_by' => $this->actor->getKey(),
        ])->save();
    }
}
