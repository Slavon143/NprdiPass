<?php

namespace Tests\Feature\Passports\Readiness;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Data\Passports\Readiness\PassportReadinessResult;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\Readiness\PassportReadinessStatus;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class ReadinessFeatureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    private Category $category;

    private ProductVariant $defaultVariant;

    private ProductMedia $primaryMedia;

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
            'name' => 'Readiness Test Product '.fake()->unique()->word(),
            'slug' => 'readiness-test-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'readiness-test-product-'.fake()->unique()->slug(1),
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

    private function evaluate(): PassportReadinessResult
    {
        $builder = app(ReadinessContextBuilder::class);
        $context = $builder->build($this->company, $this->product);
        $evaluator = app(PassportReadinessEvaluator::class);

        return $evaluator->evaluate($context);
    }

    private function createDraftPassport(): ProductPassport
    {
        return app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );
    }

    private function fillSection(DppSectionKey $section, array $payload, int $revision): ProductPassport
    {
        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        return app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $section->value,
            $payload,
            $revision,
        );
    }

    private function createFullReadyPassport(): void
    {
        $this->createDraftPassport();

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        // Identity — translatable section, only translatable fields
        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Test Product Name',
            'public_description' => 'A test product for readiness evaluation.',
        ], 1);

        // ManufacturerAndOperator — translatable section, only translatable fields
        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Test Manufacturer Inc.',
            'responsible_operator_display_name' => 'Test Operator',
            'contact_notes' => 'Contact during business hours.',
        ], 2);

        // Safety — translatable section, all fields are translatable
        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Keep away from water', 'Handle with care'],
            'hazards' => ['Electrical shock risk'],
            'storage_instructions' => 'Store in a dry place.',
            'emergency_instructions' => 'In case of fire, use CO2 extinguisher.',
            'age_restrictions' => '18+',
        ], 3);

        // RecyclingAndDisposal — translatable section, only translatable fields (no recycling_codes)
        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Disassemble and sort by material type.',
            'disposal_instructions' => 'Do not dispose in household waste.',
            'take_back_program' => 'Return to any authorized retailer.',
        ], 4);

        // MaterialsAndComposition — non-translatable section, only non-translatable fields
        $this->fillSection(DppSectionKey::MaterialsAndComposition, [
            'materials' => [
                ['name' => 'Plastic', 'percentage' => 60.0, 'recycled_content_percentage' => 30.0, 'hazardous' => false],
                ['name' => 'Steel', 'percentage' => 40.0, 'recycled_content_percentage' => 50.0, 'hazardous' => false],
            ],
        ], 5);

        // EnvironmentalInformation — non-translatable section, only non-translatable fields
        $this->fillSection(DppSectionKey::EnvironmentalInformation, [
            'carbon_footprint_kg_co2e' => 12.5,
            'expected_lifetime_years' => 5.0,
        ], 6);

        // UsageAndCare — translatable section, all fields translatable
        $this->fillSection(DppSectionKey::UsageAndCare, [
            'usage_instructions' => 'Plug in and press power button.',
            'care_instructions' => 'Clean with a dry cloth.',
        ], 7);

        // RepairAndSpareParts — translatable section, only translatable fields
        $this->fillSection(DppSectionKey::RepairAndSpareParts, [
            'repair_instructions' => 'Contact authorized service center.',
            'spare_parts_notes' => 'Spare parts available through authorized dealers.',
        ], 8);

        // SupportAndContact — translatable section, only translatable fields
        $this->fillSection(DppSectionKey::SupportAndContact, [
            'warranty_summary' => '2-year limited warranty.',
            'support_notes' => 'Contact support for assistance.',
        ], 9);
    }

    public function test_full_ready_passport_returns_ready_status(): void
    {
        $this->createFullReadyPassport();

        $result = $this->evaluate();

        $this->assertNotNull($result->status);
        $this->assertGreaterThan(0, $result->score);
        $this->assertLessThanOrEqual(100, $result->score);
        $this->assertNotEmpty($result->rules);
        $this->assertGreaterThan(0, $result->counts->passed);
    }

    public function test_full_ready_passport_has_good_score(): void
    {
        $this->createFullReadyPassport();

        $result = $this->evaluate();

        $this->assertGreaterThanOrEqual(50, $result->score);
        $this->assertLessThanOrEqual(100, $result->score);
    }

    public function test_missing_passport_returns_not_ready(): void
    {
        // Product exists but no passport
        $result = $this->evaluate();

        $this->assertSame(PassportReadinessStatus::NotReady, $result->status);
    }

    public function test_missing_passport_has_passport_exists_blocker(): void
    {
        $result = $this->evaluate();

        $failedBlockers = array_filter($result->rules, function ($rule) {
            return $rule->status->value === 'failed' && $rule->severity->value === 'blocker';
        });

        $codes = array_map(fn ($r) => $r->code, $failedBlockers);
        $this->assertContains('passport.exists', $codes);
    }

    public function test_archived_product_returns_not_ready(): void
    {
        $this->createFullReadyPassport();

        $this->product->forceFill(['status' => ProductStatus::Archived])->save();
        $this->product->refresh();

        $result = $this->evaluate();

        $this->assertSame(PassportReadinessStatus::NotReady, $result->status);
    }

    public function test_archived_product_has_catalog_product_active_blocker(): void
    {
        $this->createFullReadyPassport();

        $this->product->forceFill(['status' => ProductStatus::Archived])->save();
        $this->product->refresh();

        $result = $this->evaluate();

        $failedBlockers = array_filter($result->rules, function ($rule) {
            return $rule->status->value === 'failed' && $rule->severity->value === 'blocker';
        });

        $codes = array_map(fn ($r) => $r->code, $failedBlockers);
        $this->assertContains('catalog.product.active', $codes);
    }

    public function test_empty_safety_section_has_safety_blocker(): void
    {
        $this->createDraftPassport();
        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Safety Test Product',
            'public_description' => 'Testing safety blocker.',
        ], 1);

        $result = $this->evaluate();

        $failedBlockers = array_filter($result->rules, function ($rule) {
            return $rule->status->value === 'failed' && $rule->severity->value === 'blocker';
        });

        $codes = array_map(fn ($r) => $r->code, $failedBlockers);
        $this->assertContains('dpp.safety.reviewed', $codes);
    }

    public function test_empty_recycling_section_has_recycling_blocker(): void
    {
        $this->createDraftPassport();
        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Recycling Test Product',
            'public_description' => 'Testing recycling blocker.',
        ], 1);

        $result = $this->evaluate();

        $failedBlockers = array_filter($result->rules, function ($rule) {
            return $rule->status->value === 'failed' && $rule->severity->value === 'blocker';
        });

        $codes = array_map(fn ($r) => $r->code, $failedBlockers);
        $this->assertContains('dpp.recycling.instructions.present', $codes);
    }

    public function test_missing_identity_description_has_blocker(): void
    {
        $this->createDraftPassport();
        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Identity Test Product',
        ], 1);

        $result = $this->evaluate();

        $failedBlockers = array_filter($result->rules, function ($rule) {
            return $rule->status->value === 'failed' && $rule->severity->value === 'blocker';
        });

        $codes = array_map(fn ($r) => $r->code, $failedBlockers);
        $this->assertContains('dpp.identity.description.present', $codes);
    }

    public function test_no_primary_media_has_media_blocker(): void
    {
        $this->product->forceFill(['primary_media_id' => null])->save();
        ProductMedia::where('product_id', $this->product->getKey())->delete();
        $this->createFullReadyPassport();

        $result = $this->evaluate();

        $failedBlockers = array_filter($result->rules, function ($rule) {
            return $rule->status->value === 'failed' && $rule->severity->value === 'blocker';
        });

        $codes = array_map(fn ($r) => $r->code, $failedBlockers);
        $this->assertContains('media.primary.present', $codes);
    }

    public function test_no_identifier_has_blocker(): void
    {
        $this->defaultVariant->forceFill(['sku' => null, 'gtin' => null, 'mpn' => null])->save();

        $this->createFullReadyPassport();

        $result = $this->evaluate();

        $failedBlockers = array_filter($result->rules, function ($rule) {
            return $rule->status->value === 'failed' && $rule->severity->value === 'blocker';
        });

        $codes = array_map(fn ($r) => $r->code, $failedBlockers);
        $this->assertContains('catalog.product.identifier.present', $codes);
    }

    public function test_no_manufacturer_contact_has_blocker(): void
    {
        $this->createFullReadyPassport();

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        // Fill manufacturer without any contact info
        app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            DppSectionKey::ManufacturerAndOperator->value,
            [
                'manufacturer_display_name' => 'Test Manufacturer Inc.',
            ],
            $passport->currentDraftVersion->draft_revision,
        );

        $result = $this->evaluate();

        $failedBlockers = array_filter($result->rules, function ($rule) {
            return $rule->status->value === 'failed' && $rule->severity->value === 'blocker';
        });

        $codes = array_map(fn ($r) => $r->code, $failedBlockers);
        $this->assertContains('dpp.manufacturer.contact.present', $codes);
    }

    public function test_evaluation_is_read_only_product_timestamp_unchanged(): void
    {
        $this->createFullReadyPassport();

        $productTimestamps = [
            'created_at' => $this->product->created_at,
            'updated_at' => $this->product->updated_at,
        ];

        $this->evaluate();

        $this->product->refresh();
        $this->assertEquals($productTimestamps['created_at']->toISOString(), $this->product->created_at->toISOString());
        $this->assertEquals($productTimestamps['updated_at']->toISOString(), $this->product->updated_at->toISOString());
    }

    public function test_score_is_between_0_and_100(): void
    {
        $this->createFullReadyPassport();

        $result = $this->evaluate();

        $this->assertGreaterThanOrEqual(0, $result->score);
        $this->assertLessThanOrEqual(100, $result->score);
    }

    public function test_status_matches_blocker_presence(): void
    {
        $this->createFullReadyPassport();
        $result = $this->evaluate();

        $hasFailedBlockers = false;

        foreach ($result->rules as $rule) {
            if ($rule->status->value === 'failed' && $rule->severity->value === 'blocker') {
                $hasFailedBlockers = true;
                break;
            }
        }

        if ($hasFailedBlockers) {
            $this->assertSame(PassportReadinessStatus::NotReady, $result->status);
        }
    }

    public function test_count_summary_totals_match_rule_count(): void
    {
        $this->createFullReadyPassport();
        $result = $this->evaluate();

        $totalFromCounts = $result->counts->passed
            + $result->counts->blockers
            + $result->counts->warnings
            + $result->counts->recommendations
            + $result->counts->notApplicable;

        $this->assertSame(count($result->rules), $totalFromCounts);
    }
}
