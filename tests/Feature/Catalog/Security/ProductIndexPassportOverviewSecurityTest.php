<?php

namespace Tests\Feature\Catalog\Security;

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

class ProductIndexPassportOverviewSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Product $product;

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

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Security Test Category',
            'slug' => 'security-test-cat',
            'slug_normalized' => 'security-test-cat',
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
            'name' => 'Security Test Product',
            'slug' => 'security-test-product',
            'slug_normalized' => 'security-test-product',
            'brand' => 'SecureBrand',
            'manufacturer' => 'SecureMfr',
            'primary_category_id' => $category->getKey(),
            'status' => ProductStatus::Active,
            'created_by' => $this->owner->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $variant = new ProductVariant;
        $variant->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'name' => 'Default',
            'sku' => 'SKU-SECURE-001',
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
            'original_filename' => 'secure-thumb.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'catalog/sensitive/path/secure-thumb.jpg',
            'checksum_sha256' => str_repeat('a', 64),
            'sort_order' => 0,
            'uploaded_by' => $this->owner->getKey(),
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

    public function test_unauthenticated_redirected(): void
    {
        auth()->logout();

        $this->get(route('catalog.products.index'))
            ->assertRedirect(route('login'));
    }

    public function test_wrong_tenant_products_excluded(): void
    {
        $foreignCompany = Company::factory()->create(['status' => CompanyStatus::Active]);
        CompanyMembership::factory()->create([
            'company_id' => $foreignCompany->getKey(),
            'user_id' => $this->owner->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $foreignProduct = new Product;
        $foreignProduct->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $foreignCompany->getKey(),
            'name' => 'Foreign Product Should Not Appear',
            'slug' => 'foreign-product',
            'slug_normalized' => 'foreign-product',
            'status' => ProductStatus::Active,
            'created_by' => $this->owner->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->get(route('catalog.products.index'))
            ->assertOk()
            ->assertSee('Security Test Product')
            ->assertDontSee('Foreign Product Should Not Appear');
    }

    public function test_owner_sees_actions(): void
    {
        $this->get(route('catalog.products.index'))
            ->assertOk()
            ->assertSee('Create product')
            ->assertSee('Edit');
    }

    public function test_editor_sees_actions(): void
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
            ->assertOk()
            ->assertSee('Create product')
            ->assertSee('Edit');
    }

    public function test_viewer_read_only(): void
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

    public function test_no_storage_paths_in_html(): void
    {
        $response = $this->get(route('catalog.products.index'));

        $response->assertOk();

        $body = $response->getContent();

        $this->assertStringNotContainsString('storage_path', $body);
        $this->assertStringNotContainsString('catalog/sensitive/path/secure-thumb.jpg', $body);
        $this->assertStringNotContainsString(str_repeat('a', 64), $body);
    }

    public function test_no_internal_ids_in_html(): void
    {
        $response = $this->get(route('catalog.products.index'));

        $response->assertOk();

        $body = $response->getContent();

        $internalId = (string) $this->product->getKey();
        $this->assertStringNotContainsString('>'.$internalId.'<', $body);
        $this->assertStringNotContainsString('&quot;id&quot;:'.$internalId, $body);
    }
}
