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
use App\Models\AuditLog;
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

class ReadinessPurityTest extends TestCase
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
            'name' => 'Purity Test Product '.fake()->unique()->word(),
            'slug' => 'purity-test-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'purity-test-product-'.fake()->unique()->slug(1),
            'brand' => 'Test Brand',
            'manufacturer' => 'Test Manufacturer',
            'description' => 'Test product for purity evaluation.',
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
            'sku' => 'SKU-PURITY-001',
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
            'public_description' => 'A test product for readiness evaluation.',
        ], 1);

        $passport->refresh();

        $action->handle($this->actor, $this->company, $this->product, $passport, DppSectionKey::ManufacturerAndOperator->value, [
            'manufacturer_display_name' => 'Test Manufacturer Inc.',
        ], 2);

        $passport->refresh();

        $action->handle($this->actor, $this->company, $this->product, $passport, DppSectionKey::Safety->value, [
            'warnings' => ['Test warning'],
            'storage_instructions' => 'Store properly.',
        ], 3);

        $passport->refresh();

        $action->handle($this->actor, $this->company, $this->product, $passport, DppSectionKey::RecyclingAndDisposal->value, [
            'recycling_instructions' => 'Recycle properly.',
        ], 4);
    }

    public function test_two_evaluations_return_same_status(): void
    {
        $this->createFullReadyPassport();

        $result1 = $this->evaluate();
        $result2 = $this->evaluate();

        $this->assertSame($result1->status, $result2->status);
    }

    public function test_two_evaluations_return_same_score(): void
    {
        $this->createFullReadyPassport();

        $result1 = $this->evaluate();
        $result2 = $this->evaluate();

        $this->assertSame($result1->score, $result2->score);
    }

    public function test_two_evaluations_return_same_rule_count(): void
    {
        $this->createFullReadyPassport();

        $result1 = $this->evaluate();
        $result2 = $this->evaluate();

        $this->assertSame(count($result1->rules), count($result2->rules));
    }

    public function test_evaluation_does_not_change_product_timestamps(): void
    {
        $this->createFullReadyPassport();

        $this->product->refresh();
        $beforeCreatedAt = $this->product->created_at;
        $beforeUpdatedAt = $this->product->updated_at;

        sleep(1);

        $this->evaluate();

        $this->product->refresh();
        $this->assertEquals($beforeCreatedAt->toISOString(), $this->product->created_at->toISOString());
        $this->assertEquals($beforeUpdatedAt->toISOString(), $this->product->updated_at->toISOString());
    }

    public function test_evaluation_does_not_change_passport_timestamps(): void
    {
        $this->createFullReadyPassport();

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        $beforeCreatedAt = $passport->created_at;
        $beforeUpdatedAt = $passport->updated_at;

        sleep(1);

        $this->evaluate();

        $passport->refresh();
        $this->assertEquals($beforeCreatedAt->toISOString(), $passport->created_at->toISOString());
        $this->assertEquals($beforeUpdatedAt->toISOString(), $passport->updated_at->toISOString());
    }

    public function test_evaluation_does_not_change_draft_revision(): void
    {
        $this->createFullReadyPassport();

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        $beforeRevision = $passport->currentDraftVersion->draft_revision;

        $this->evaluate();

        $passport->refresh();
        $this->assertSame($beforeRevision, $passport->currentDraftVersion->draft_revision);
    }

    public function test_evaluation_does_not_change_payload(): void
    {
        $this->createFullReadyPassport();

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        $beforePayload = $passport->currentDraftVersion->payload;

        $this->evaluate();

        $passport->refresh();
        $this->assertEquals($beforePayload, $passport->currentDraftVersion->payload);
    }

    public function test_no_audit_events_created_during_evaluation(): void
    {
        $this->createFullReadyPassport();

        $beforeCount = AuditLog::query()->count();

        $this->evaluate();
        $this->evaluate();

        $afterCount = AuditLog::query()->count();

        $this->assertSame($beforeCount, $afterCount, 'No audit events should be created during evaluation');
    }
}
