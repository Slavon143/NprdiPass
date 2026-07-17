<?php

namespace Tests\Feature\Passports\Security;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Data\Passports\Readiness\PassportReadinessResult;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Models\AuditLog;
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
use Tests\TestCase;

class PassportReadinessSecurityTest extends TestCase
{
    use RefreshDatabase;

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
            'slug' => 'test-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'test-cat-'.fake()->unique()->slug(1),
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
            'name' => 'Security Test Product '.fake()->unique()->word(),
            'slug' => 'security-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'security-product-'.fake()->unique()->slug(1),
            'brand' => 'Test Brand',
            'manufacturer' => 'Test Manufacturer',
            'description' => 'Security test.',
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
            'sku' => 'SKU-SEC-001',
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
            'original_filename' => 'sec-test.jpg',
            'mime_type' => 'image/jpeg',
            'storage_path' => 'test/sec-test.jpg',
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

    private function createPassportAndEvaluate(): void
    {
        app(CreateProductPassportDraftAction::class)->handle($this->actor, $this->company, $this->product);
    }

    public function test_owner_can_evaluate_readiness(): void
    {
        $this->createPassportAndEvaluate();
        $result = $this->evaluate();
        $this->assertNotNull($result->status);
    }

    public function test_admin_can_evaluate_readiness(): void
    {
        $this->actor->memberships()->update(['role' => CompanyRole::Admin->value]);
        $this->createPassportAndEvaluate();
        $result = $this->evaluate();
        $this->assertNotNull($result->status);
    }

    public function test_editor_can_evaluate_readiness(): void
    {
        $this->actor->memberships()->update(['role' => CompanyRole::Editor->value]);
        $this->createPassportAndEvaluate();
        $result = $this->evaluate();
        $this->assertNotNull($result->status);
    }

    public function test_viewer_can_evaluate_readiness(): void
    {
        $this->createPassportAndEvaluate();
        $this->actor->memberships()->update(['role' => CompanyRole::Viewer->value]);
        $result = $this->evaluate();
        $this->assertNotNull($result->status);
    }

    public function test_evaluation_does_not_create_audit_events(): void
    {
        $this->createPassportAndEvaluate();
        $auditCountBefore = AuditLog::count();
        $this->evaluate();
        $this->evaluate();
        $this->assertSame($auditCountBefore, AuditLog::count());
    }

    public function test_evaluation_is_read_only(): void
    {
        $this->createPassportAndEvaluate();
        $timestampBefore = $this->product->fresh()->updated_at;

        $this->evaluate();

        $this->assertSame(
            $timestampBefore->toISOString(),
            $this->product->fresh()->updated_at->toISOString(),
        );
    }

    private function evaluate(): PassportReadinessResult
    {
        $builder = app(ReadinessContextBuilder::class);
        $context = $builder->build($this->company, $this->product);
        $evaluator = app(PassportReadinessEvaluator::class);

        return $evaluator->evaluate($context);
    }
}
