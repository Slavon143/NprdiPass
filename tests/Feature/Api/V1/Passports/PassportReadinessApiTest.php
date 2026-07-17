<?php

namespace Tests\Feature\Api\V1\Passports;

use App\Enums\ApiTokenAbility;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PassportReadinessApiTest extends TestCase
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
            'name' => 'API Product '.fake()->unique()->word(),
            'slug' => 'api-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'api-product-'.fake()->unique()->slug(1),
            'brand' => 'Test Brand',
            'manufacturer' => 'Test Manufacturer',
            'description' => 'API test product.',
            'status' => ProductStatus::Active,
            'primary_category_id' => $category->getKey(),
            'created_by' => $this->user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $defaultVariant = new ProductVariant;
        $defaultVariant->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'name' => 'Default Variant',
            'sku' => 'SKU-API-001',
            'status' => ProductVariantStatus::Active,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $primaryMedia = new ProductMedia;
        $primaryMedia->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'original_filename' => 'api-test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/api-test.jpg',
            'checksum_sha256' => str_repeat('a', 64),
            'sort_order' => 0,
            'uploaded_by' => $this->user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->product->forceFill([
            'default_variant_id' => $defaultVariant->getKey(),
            'primary_media_id' => $primaryMedia->getKey(),
        ])->save();

        $this->product->categories()->attach($category->getKey(), [
            'company_id' => $this->company->getKey(),
            'created_at' => now(),
        ]);

        $this->product->refresh();
    }

    private function issueToken(array $abilities): string
    {
        $token = issueCompanyApiToken($this->user, $this->company, $abilities);

        return $token->plainTextToken;
    }

    /** @return array<string, mixed> */
    private function readinessData(): array
    {
        $token = $this->issueToken([ApiTokenAbility::PassportsRead->value]);
        $res = $this->withToken($token)
            ->getJson("/api/v1/catalog/products/{$this->product->uuid}/passport/readiness");

        $res->assertOk();

        return $res->json('data', []);
    }

    public function test_get_readiness_returns_200(): void
    {
        $token = $this->issueToken([ApiTokenAbility::PassportsRead->value]);

        $this->withToken($token)
            ->getJson("/api/v1/catalog/products/{$this->product->uuid}/passport/readiness")
            ->assertOk();
    }

    public function test_response_has_profile(): void
    {
        $data = $this->readinessData();

        $this->assertArrayHasKey('profile', $data);
        $this->assertIsString($data['profile']);
        $this->assertNotEmpty($data['profile']);
    }

    public function test_response_has_profile_version(): void
    {
        $data = $this->readinessData();

        $this->assertArrayHasKey('profile_version', $data);
        $this->assertIsInt($data['profile_version']);
    }

    public function test_response_has_status(): void
    {
        $data = $this->readinessData();

        $this->assertArrayHasKey('status', $data);
        $this->assertContains($data['status'], ['not_ready', 'ready_with_warnings', 'ready']);
    }

    public function test_response_has_score(): void
    {
        $data = $this->readinessData();

        $this->assertArrayHasKey('score', $data);
        $this->assertIsInt($data['score']);
        $this->assertGreaterThanOrEqual(0, $data['score']);
        $this->assertLessThanOrEqual(100, $data['score']);
    }

    public function test_response_has_counts(): void
    {
        $data = $this->readinessData();

        $this->assertArrayHasKey('counts', $data);
        $this->assertIsArray($data['counts']);
        $this->assertArrayHasKey('passed', $data['counts']);
        $this->assertArrayHasKey('blockers', $data['counts']);
        $this->assertArrayHasKey('warnings', $data['counts']);
        $this->assertArrayHasKey('recommendations', $data['counts']);
        $this->assertArrayHasKey('not_applicable', $data['counts']);
    }

    public function test_response_has_rules(): void
    {
        $data = $this->readinessData();

        $this->assertArrayHasKey('rules', $data);
        $this->assertIsArray($data['rules']);
        $this->assertNotEmpty($data['rules']);
    }

    public function test_unauthorized_without_token(): void
    {
        $this->getJson("/api/v1/catalog/products/{$this->product->uuid}/passport/readiness")
            ->assertUnauthorized();
    }

    public function test_forbidden_without_passports_read_ability(): void
    {
        $token = $this->issueToken([ApiTokenAbility::CatalogRead->value]);

        $this->withToken($token)
            ->getJson("/api/v1/catalog/products/{$this->product->uuid}/passport/readiness")
            ->assertForbidden();
    }

    public function test_not_found_for_wrong_tenant(): void
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
            ->getJson("/api/v1/catalog/products/{$this->product->uuid}/passport/readiness")
            ->assertNotFound();
    }

    public function test_response_redacts_internal_ids(): void
    {
        $data = $this->readinessData();

        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('company_id', $data);

        if (isset($data['rules'][0])) {
            $rule = $data['rules'][0];
            $this->assertArrayNotHasKey('id', $rule);
        }
    }

    public function test_response_redacts_storage_paths(): void
    {
        $data = $this->readinessData();

        $this->assertArrayNotHasKey('storage_path', $data);
        $this->assertArrayNotHasKey('storage_key', $data);
        $this->assertArrayNotHasKey('checksum_sha256', $data);
    }

    public function test_readiness_works_even_when_no_passport_exists(): void
    {
        $token = $this->issueToken([ApiTokenAbility::PassportsRead->value]);

        $res = $this->withToken($token)
            ->getJson("/api/v1/catalog/products/{$this->product->uuid}/passport/readiness");

        $res->assertOk();

        $data = $res->json('data', []);

        $this->assertSame('not_ready', $data['status']);
        $this->assertArrayHasKey('rules', $data);
    }

    public function test_response_has_evaluated_at(): void
    {
        $data = $this->readinessData();

        $this->assertArrayHasKey('evaluated_at', $data);
        $this->assertIsString($data['evaluated_at']);
    }

    public function test_response_has_schema_version(): void
    {
        $data = $this->readinessData();

        $this->assertArrayHasKey('schema_version', $data);
        $this->assertIsInt($data['schema_version']);
    }

    public function test_response_has_passport_uuid(): void
    {
        $data = $this->readinessData();

        $this->assertArrayHasKey('passport_uuid', $data);
        $this->assertIsString($data['passport_uuid']);
    }
}
