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
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ProductIndexReadinessProvider;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class ProductIndexReadinessConsistencyTest extends TestCase
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
            'name' => 'Consistency Test Category',
            'slug' => 'consistency-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'consistency-cat-'.fake()->unique()->slug(1),
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
            'name' => 'Consistency Product '.fake()->unique()->word(),
            'slug' => 'consistency-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'consistency-product-'.fake()->unique()->slug(1),
            'brand' => 'ConsistentBrand',
            'manufacturer' => 'ConsistentMfr',
            'description' => 'Product for consistency testing.',
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
            'sku' => 'SKU-CONSISTENCY-001',
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
            'original_filename' => 'consistency-test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/consistency-test.jpg',
            'checksum_sha256' => str_repeat('c', 64),
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

    private function createFullReadyPassport(): void
    {
        app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        $action = app(UpdateProductPassportSectionAction::class);

        $action->handle($this->actor, $this->company, $this->product, $passport, DppSectionKey::Identity->value, [
            'public_name' => 'Test Product Name',
            'public_description' => 'A test product for consistency.',
        ], 1);

        $passport->refresh();

        $action->handle($this->actor, $this->company, $this->product, $passport, DppSectionKey::ManufacturerAndOperator->value, [
            'manufacturer_display_name' => 'Test Manufacturer Inc.',
            'responsible_operator_display_name' => 'Test Operator',
            'contact_notes' => 'Contact during business hours.',
        ], 2);

        $passport->refresh();

        $action->handle($this->actor, $this->company, $this->product, $passport, DppSectionKey::Safety->value, [
            'warnings' => ['Keep away from water', 'Handle with care'],
            'hazards' => ['Electrical shock risk'],
            'storage_instructions' => 'Store in a dry place.',
            'emergency_instructions' => 'In case of fire, use CO2 extinguisher.',
            'age_restrictions' => '18+',
        ], 3);

        $passport->refresh();

        $action->handle($this->actor, $this->company, $this->product, $passport, DppSectionKey::RecyclingAndDisposal->value, [
            'recycling_instructions' => 'Disassemble and sort by material type.',
            'disposal_instructions' => 'Do not dispose in household waste.',
            'take_back_program' => 'Return to any authorized retailer.',
        ], 4);

        $passport->refresh();

        $action->handle($this->actor, $this->company, $this->product, $passport, DppSectionKey::MaterialsAndComposition->value, [
            'materials' => [
                ['name' => 'Plastic', 'percentage' => 60.0, 'recycled_content_percentage' => 30.0, 'hazardous' => false],
                ['name' => 'Steel', 'percentage' => 40.0, 'recycled_content_percentage' => 50.0, 'hazardous' => false],
            ],
        ], 5);

        $passport->refresh();

        $action->handle($this->actor, $this->company, $this->product, $passport, DppSectionKey::EnvironmentalInformation->value, [
            'carbon_footprint_kg_co2e' => 12.5,
            'expected_lifetime_years' => 5.0,
        ], 6);

        $passport->refresh();

        $action->handle($this->actor, $this->company, $this->product, $passport, DppSectionKey::UsageAndCare->value, [
            'usage_instructions' => 'Plug in and press power button.',
            'care_instructions' => 'Clean with a dry cloth.',
        ], 7);

        $passport->refresh();

        $action->handle($this->actor, $this->company, $this->product, $passport, DppSectionKey::RepairAndSpareParts->value, [
            'repair_instructions' => 'Contact authorized service center.',
            'spare_parts_notes' => 'Spare parts available through authorized dealers.',
        ], 8);

        $passport->refresh();

        $action->handle($this->actor, $this->company, $this->product, $passport, DppSectionKey::SupportAndContact->value, [
            'warranty_summary' => '2-year limited warranty.',
            'support_notes' => 'Contact support for assistance.',
        ], 9);
    }

    private function evaluateDirect(): PassportReadinessResult
    {
        $builder = app(ReadinessContextBuilder::class);
        $context = $builder->build($this->company, $this->product);
        $evaluator = app(PassportReadinessEvaluator::class);

        return $evaluator->evaluate($context);
    }

    /** @return array<string, mixed> */
    private function getIndexSummary(): array
    {
        $provider = app(ProductIndexReadinessProvider::class);

        $paginator = new LengthAwarePaginator(
            new Collection([$this->product->fresh()]),
            1,
            25,
            1,
        );

        $summaries = $provider->loadSummaries($this->company, $paginator);

        return $summaries[$this->product->uuid] ?? [];
    }

    public function test_index_score_matches_readiness_page_score(): void
    {
        $this->createFullReadyPassport();

        $directResult = $this->evaluateDirect();
        $indexSummary = $this->getIndexSummary();

        $this->assertSame($directResult->score, $indexSummary['score']);
    }

    public function test_index_status_matches_evaluator_status(): void
    {
        $this->createFullReadyPassport();

        $directResult = $this->evaluateDirect();
        $indexSummary = $this->getIndexSummary();

        $this->assertSame($directResult->status->value, $indexSummary['readiness_status']);
    }

    public function test_index_blockers_match_evaluator_blockers(): void
    {
        $this->createFullReadyPassport();

        $directResult = $this->evaluateDirect();
        $indexSummary = $this->getIndexSummary();

        $this->assertSame($directResult->counts->blockers, $indexSummary['blockers']);
    }

    public function test_missing_passport_produces_null_score(): void
    {
        $indexSummary = $this->getIndexSummary();

        $this->assertNull($indexSummary['score']);
        $this->assertSame('not_created', $indexSummary['passport_status']);
        $this->assertSame(0, $indexSummary['blockers']);
    }

    public function test_valid_passport_produces_consistent_results(): void
    {
        $this->createFullReadyPassport();

        $summary1 = $this->getIndexSummary();
        $summary2 = $this->getIndexSummary();

        $this->assertSame($summary1['score'], $summary2['score']);
        $this->assertSame($summary1['readiness_status'], $summary2['readiness_status']);
        $this->assertSame($summary1['blockers'], $summary2['blockers']);
        $this->assertSame($summary1['warnings'], $summary2['warnings']);
    }
}
