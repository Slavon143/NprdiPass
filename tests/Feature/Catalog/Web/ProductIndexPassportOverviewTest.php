<?php

namespace Tests\Feature\Catalog\Web;

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
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class ProductIndexPassportOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Product $product;

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
            'name' => 'Index Test Category',
            'slug' => 'index-test-category',
            'slug_normalized' => 'index-test-category',
            'depth' => 0,
            'sort_order' => 0,
            'status' => CategoryStatus::Active,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->product = $this->createProduct('Passport Overview Product');
    }

    private function createProduct(string $name, ?Company $company = null): Product
    {
        $co = $company ?? $this->company;
        $actor = $this->owner;
        $slug = str($name)->slug()->toString();

        $product = new Product;
        $product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $co->getKey(),
            'name' => $name,
            'slug' => $slug,
            'slug_normalized' => $slug,
            'brand' => 'Test Brand',
            'manufacturer' => 'Test Manufacturer',
            'status' => ProductStatus::Active,
            'created_by' => $actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $variant = new ProductVariant;
        $variant->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $co->getKey(),
            'product_id' => $product->getKey(),
            'name' => 'Default',
            'sku' => 'SKU-'.substr(strtoupper(str_replace('-', '_', $slug)), 0, 20),
            'status' => ProductVariantStatus::Active,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $product->forceFill(['default_variant_id' => $variant->getKey()])->save();

        return $product->refresh();
    }

    private function attachMedia(Product $product): ProductMedia
    {
        $media = new ProductMedia;
        $media->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $product->company_id,
            'product_id' => $product->getKey(),
            'original_filename' => 'product-thumb.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
            'storage_path' => 'catalog/product-thumb.jpg',
            'checksum_sha256' => str_repeat('f', 64),
            'sort_order' => 0,
            'uploaded_by' => $this->owner->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $product->forceFill(['primary_media_id' => $media->getKey()])->save();
        $product->refresh();

        return $media;
    }

    public function test_index_returns_200(): void
    {
        $this->get(route('catalog.products.index'))
            ->assertOk();
    }

    public function test_passport_column_visible(): void
    {
        $this->get(route('catalog.products.index'))
            ->assertSee('Passport');
    }

    public function test_readiness_column_visible(): void
    {
        $this->get(route('catalog.products.index'))
            ->assertSee('Readiness');
    }

    public function test_passport_not_created_state_rendered(): void
    {
        $this->get(route('catalog.products.index'))
            ->assertSee('Not created');
    }

    public function test_create_passport_visible_for_owner(): void
    {
        $this->get(route('catalog.products.index'))
            ->assertSee('Create');
    }

    public function test_create_passport_visible_for_admin(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        CompanyMembership::factory()->create([
            'company_id' => $this->company->getKey(),
            'user_id' => $admin->getKey(),
            'role' => CompanyRole::Admin,
        ]);

        $this->actingAs($admin);
        app(CurrentCompany::class)->set($this->company);

        View::share('currentCompany', $this->company);
        View::share('availableMembers', new Collection([$this->company]));
        View::share('currentMembership', $admin->memberships()
            ->where('company_id', $this->company->getKey())
            ->first());
        View::share('slot', '');

        $this->get(route('catalog.products.index'))
            ->assertSee('Create');
    }

    public function test_create_passport_visible_for_editor(): void
    {
        $editor = User::factory()->create(['email_verified_at' => now()]);
        CompanyMembership::factory()->create([
            'company_id' => $this->company->getKey(),
            'user_id' => $editor->getKey(),
            'role' => CompanyRole::Editor,
        ]);

        $this->actingAs($editor);
        app(CurrentCompany::class)->set($this->company);

        View::share('currentCompany', $this->company);
        View::share('availableCompanies', new Collection([$this->company]));
        View::share('currentMembership', $editor->memberships()
            ->where('company_id', $this->company->getKey())
            ->first());
        View::share('slot', '');

        $this->get(route('catalog.products.index'))
            ->assertSee('Create');
    }

    public function test_create_passport_hidden_for_viewer(): void
    {
        $viewer = User::factory()->create(['email_verified_at' => now()]);
        CompanyMembership::factory()->create([
            'company_id' => $this->company->getKey(),
            'user_id' => $viewer->getKey(),
            'role' => CompanyRole::Viewer,
        ]);

        $this->actingAs($viewer);
        app(CurrentCompany::class)->set($this->company);

        View::share('currentCompany', $this->company);
        View::share('availableCompanies', new Collection([$this->company]));
        View::share('currentMembership', $viewer->memberships()
            ->where('company_id', $this->company->getKey())
            ->first());
        View::share('slot', '');

        $response = $this->get(route('catalog.products.index'))
            ->assertOk();

        $body = $response->getContent();
        $this->assertStringNotContainsString(
            'catalog.products.passport.store',
            $body,
        );
    }

    public function test_viewer_sees_no_mutation_actions(): void
    {
        $viewer = User::factory()->create(['email_verified_at' => now()]);
        CompanyMembership::factory()->create([
            'company_id' => $this->company->getKey(),
            'user_id' => $viewer->getKey(),
            'role' => CompanyRole::Viewer,
        ]);

        $this->actingAs($viewer);
        app(CurrentCompany::class)->set($this->company);

        View::share('currentCompany', $this->company);
        View::share('availableCompanies', new Collection([$this->company]));
        View::share('currentMembership', $viewer->memberships()
            ->where('company_id', $this->company->getKey())
            ->first());
        View::share('slot', '');

        $this->get(route('catalog.products.index'))
            ->assertOk()
            ->assertDontSee('Create product')
            ->assertDontSee('Edit');
    }

    public function test_pagination_retains_filters(): void
    {
        foreach (range(1, 26) as $i) {
            $this->createProduct("Pagination Product {$i}");
        }

        $response = $this->get(route('catalog.products.index', ['per_page' => 25, 'product_statuses' => ['active']]));

        $this->assertStringContainsString('product_statuses%5B0%5D=active', $response->getContent());
    }

    public function test_empty_state_renders(): void
    {
        Product::query()->delete();

        $this->get(route('catalog.products.index'))
            ->assertOk()
            ->assertSee('No products yet');
    }

    public function test_media_thumbnail_rendered(): void
    {
        $product = $this->createProduct('Media Product');
        $this->attachMedia($product);

        $response = $this->get(route('catalog.products.index'))
            ->assertOk();

        $body = $response->getContent();
        $this->assertStringContainsString('<img ', $body);
        $this->assertStringContainsString('/catalog/media/', $body);
    }
}
