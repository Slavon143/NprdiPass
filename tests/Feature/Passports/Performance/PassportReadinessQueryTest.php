<?php

namespace Tests\Feature\Passports\Performance;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PassportReadinessQueryTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY_BUDGET = 50;

    private Company $company;

    private User $actor;

    private Product $product;

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

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Test Category',
            'slug' => 'perf-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'perf-cat-'.fake()->unique()->slug(1),
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
            'name' => 'Perf Test Product '.fake()->unique()->word(),
            'slug' => 'perf-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'perf-product-'.fake()->unique()->slug(1),
            'brand' => 'Test Brand',
            'manufacturer' => 'Test Manufacturer',
            'description' => 'Performance test.',
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
            'name' => 'Default Variant',
            'sku' => 'SKU-PERF-001',
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
            'original_filename' => 'perf-test.jpg',
            'mime_type' => 'image/jpeg',
            'storage_path' => 'test/perf-test.jpg',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
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

        $this->product->refresh();
    }

    public function test_readiness_evaluation_has_bounded_query_count(): void
    {
        app(CreateProductPassportDraftAction::class)->handle($this->actor, $this->company, $this->product);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $builder = app(ReadinessContextBuilder::class);
        $context = $builder->build($this->company, $this->product);
        $evaluator = app(PassportReadinessEvaluator::class);
        $evaluator->evaluate($context);

        $queries = DB::getQueryLog();

        $this->assertLessThanOrEqual(self::QUERY_BUDGET, count($queries),
            sprintf('Readiness evaluation used %d queries, exceeding budget of %d', count($queries), self::QUERY_BUDGET));
    }

    public function test_readiness_returns_valid_result_with_no_documents(): void
    {
        app(CreateProductPassportDraftAction::class)->handle($this->actor, $this->company, $this->product);

        $builder = app(ReadinessContextBuilder::class);
        $context = $builder->build($this->company, $this->product);
        $evaluator = app(PassportReadinessEvaluator::class);
        $result = $evaluator->evaluate($context);

        $this->assertNotNull($result->status);
        $this->assertGreaterThanOrEqual(0, $result->score);
        $this->assertLessThanOrEqual(100, $result->score);
        $this->assertNotEmpty($result->rules);
    }
}
