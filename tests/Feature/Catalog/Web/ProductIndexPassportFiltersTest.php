<?php

namespace Tests\Feature\Catalog\Web;

use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\ProductPassportStatus;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\View;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class ProductIndexPassportFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['status' => CompanyStatus::Active]);
        $this->owner = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'company_id' => $this->company->getKey(),
            'user_id' => $this->owner->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $this->actingAs($this->owner);
        app(CurrentCompany::class)->set($this->company);

        View::share('currentCompany', $this->company);
        View::share('availableCompanies', new Collection([$this->company]));
        View::share('currentMembership', $this->owner->memberships()
            ->where('company_id', $this->company->getKey())
            ->first());
        View::share('slot', '');

        $this->category = new Category;
        $this->category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Filter Test Category',
            'slug' => 'filter-test-category',
            'slug_normalized' => 'filter-test-category',
            'depth' => 0,
            'sort_order' => 0,
            'status' => CategoryStatus::Active,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();
    }

    private function createProduct(string $name, string $status = 'active'): Product
    {
        $slug = str($name)->slug()->toString();

        $product = new Product;
        $product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => $name,
            'slug' => $slug,
            'slug_normalized' => $slug,
            'brand' => 'FilterBrand',
            'manufacturer' => 'FilterMfr',
            'primary_category_id' => $this->category->getKey(),
            'status' => $status === 'active' ? ProductStatus::Active : ($status === 'draft' ? ProductStatus::Draft : ProductStatus::Archived),
            'created_by' => $this->owner->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $variant = new ProductVariant;
        $variant->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $product->getKey(),
            'name' => 'Default',
            'sku' => 'SKU-'.substr(strtoupper(str_replace('-', '_', $slug)), 0, 20),
            'status' => ProductVariantStatus::Active,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $product->forceFill(['default_variant_id' => $variant->getKey()])->save();
        $product->categories()->attach($this->category->getKey(), [
            'company_id' => $this->company->getKey(),
            'created_at' => now(),
        ]);

        return $product->refresh();
    }

    private function createPassportForProduct(Product $product, ProductPassportStatus $status): void
    {
        $passport = new ProductPassport;
        $passport->forceFill([
            'uuid' => (string) str()->uuid(),
            'public_id' => (string) Uuid::uuid7(),
            'company_id' => $this->company->getKey(),
            'product_id' => $product->getKey(),
            'status' => $status,
            'default_language' => 'sv',
            'enabled_languages' => json_encode(['sv', 'en']),
            'created_by' => $this->owner->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        if ($status === ProductPassportStatus::Published) {
            $passport->forceFill([
                'first_published_at' => now(),
                'last_published_at' => now(),
            ])->save();
        }
    }

    private function seedProducts(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $product = $this->createProduct("No Passport Product {$i}");
        }

        for ($i = 1; $i <= 10; $i++) {
            $product = $this->createProduct("Draft Passport Product {$i}");
            $this->createPassportForProduct($product, ProductPassportStatus::Draft);
        }

        for ($i = 1; $i <= 10; $i++) {
            $product = $this->createProduct("Published Passport Product {$i}");
            $this->createPassportForProduct($product, ProductPassportStatus::Published);
        }

        for ($i = 1; $i <= 5; $i++) {
            $product = $this->createProduct("Draft Status Product {$i}", 'draft');
        }
    }

    public function test_passport_missing_filter(): void
    {
        $this->seedProducts();

        $response = $this->get(route('catalog.products.index', [
            'passport_statuses' => ['not_created'],
            'per_page' => 50,
        ]));

        $response->assertOk();
        $response->assertViewHas('products', function (LengthAwarePaginator $products): bool {
            return $products->total() === 15;
        });
    }

    public function test_passport_draft_filter(): void
    {
        $this->seedProducts();

        $response = $this->get(route('catalog.products.index', [
            'passport_statuses' => ['draft'],
            'per_page' => 50,
        ]));

        $response->assertOk();
        $response->assertViewHas('products', function (LengthAwarePaginator $products): bool {
            return $products->total() === 10;
        });
    }

    public function test_needs_attention_filter(): void
    {
        $this->seedProducts();

        $response = $this->get(route('catalog.products.index', [
            'needs_attention' => '1',
            'per_page' => 50,
        ]));

        $response->assertOk();

        $response->assertViewHas('products', function (LengthAwarePaginator $products): bool {
            return $products->total() >= 10;
        });
    }

    public function test_product_status_filter(): void
    {
        $this->seedProducts();

        $response = $this->get(route('catalog.products.index', [
            'product_statuses' => ['active'],
            'per_page' => 50,
        ]));

        $response->assertOk();

        $response->assertViewHas('products', function (LengthAwarePaginator $products): bool {
            return $products->total() === 30;
        });
    }

    public function test_search_filter(): void
    {
        $this->createProduct('UniqueSearchName AlphaBeta');
        $this->createProduct('Unrelated Product');

        $response = $this->get(route('catalog.products.index', ['q' => 'UniqueSearchName']));

        $response->assertOk();
        $response->assertSee('UniqueSearchName');
        $response->assertDontSee('Unrelated Product');
    }

    public function test_pagination_totals_correct(): void
    {
        $this->seedProducts();

        $response = $this->get(route('catalog.products.index', ['per_page' => 25]));

        $response->assertOk();

        $response->assertViewHas('products', function (LengthAwarePaginator $products): bool {
            return $products->total() === 35
                && $products->perPage() === 25;
        });
    }

    public function test_page_boundaries(): void
    {
        $this->seedProducts();

        $page1 = $this->get(route('catalog.products.index', ['per_page' => 25, 'page' => 1]));
        $page2 = $this->get(route('catalog.products.index', ['per_page' => 25, 'page' => 2]));

        $page1->assertOk();
        $page2->assertOk();

        $page1Content = $page1->getContent();
        $page2Content = $page2->getContent();

        $this->assertNotSame($page1Content, $page2Content);
    }

    public function test_combined_filters(): void
    {
        $this->seedProducts();

        $response = $this->get(route('catalog.products.index', [
            'passport_statuses' => ['draft'],
            'product_statuses' => ['active'],
            'per_page' => 50,
        ]));

        $response->assertOk();

        $response->assertViewHas('products', function (LengthAwarePaginator $products): bool {
            return $products->total() === 10;
        });
    }
}
