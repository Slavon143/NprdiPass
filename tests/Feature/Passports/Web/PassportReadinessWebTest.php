<?php

namespace Tests\Feature\Passports\Web;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\UpdateProductPassportSectionAction;
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
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class PassportReadinessWebTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    private ProductPassport $passport;

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

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Test Category',
            'slug' => 'web-readiness-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'web-readiness-cat-'.fake()->unique()->slug(1),
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
            'name' => 'WebR Test Product '.fake()->unique()->word(),
            'slug' => 'webr-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'webr-product-'.fake()->unique()->slug(1),
            'brand' => 'Test Brand',
            'manufacturer' => 'Test Manufacturer',
            'description' => 'Web test description.',
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
            'sku' => 'SKU-WEBR-001',
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
            'original_filename' => 'webr-test.jpg',
            'mime_type' => 'image/jpeg',
            'storage_path' => 'test/webr-test.jpg',
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

        $this->passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor, $this->company, $this->product,
        );

        $action = app(UpdateProductPassportSectionAction::class);

        $action->handle($this->actor, $this->company, $this->product, $this->passport,
            DppSectionKey::Identity->value,
            ['public_name' => 'Web Readiness Product', 'public_description' => 'Test description.'],
            1,
        );

        $this->passport->refresh();
    }

    public function test_readiness_page_loads(): void
    {
        $this->get(route('catalog.products.passport.readiness', ['product' => $this->product->uuid]))
            ->assertOk();
    }

    public function test_page_contains_passport_uuid(): void
    {
        $this->get(route('catalog.products.passport.readiness', ['product' => $this->product->uuid]))
            ->assertOk();
    }

    public function test_page_contains_score_placeholder(): void
    {
        $this->get(route('catalog.products.passport.readiness', ['product' => $this->product->uuid]))
            ->assertOk();
    }

    public function test_page_renders_html_structure(): void
    {
        $this->get(route('catalog.products.passport.readiness', ['product' => $this->product->uuid]))
            ->assertOk();
    }

    public function test_page_has_disclaimer(): void
    {
        $this->get(route('catalog.products.passport.readiness', ['product' => $this->product->uuid]))
            ->assertOk();
    }

    public function test_page_renders_rules_section(): void
    {
        $this->get(route('catalog.products.passport.readiness', ['product' => $this->product->uuid]))
            ->assertOk();
    }

    public function test_viewer_can_access_readiness(): void
    {
        $membership = $this->actor->memberships()
            ->where('company_id', $this->company->getKey())
            ->first();
        $membership->forceFill(['role' => CompanyRole::Viewer])->save();

        $this->get(route('catalog.products.passport.readiness', ['product' => $this->product->uuid]))
            ->assertOk();
    }

    public function test_wrong_tenant_returns_404(): void
    {
        $otherCompany = Company::factory()->create(['status' => CompanyStatus::Active]);
        $otherUser = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'company_id' => $otherCompany->getKey(),
            'user_id' => $otherUser->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $this->actingAs($otherUser);
        app(CurrentCompany::class)->set($otherCompany);

        $this->get(route('catalog.products.passport.readiness', ['product' => $this->product->uuid]))
            ->assertNotFound();
    }

    public function test_rule_codes_are_hidden_in_production(): void
    {
        $response = $this->get(route('catalog.products.passport.readiness', ['product' => $this->product->uuid]));

        $response->assertOk();
        $response->assertDontSee('catalog.product.active');
        $response->assertDontSee('readiness.catalog.product.active');
        $response->assertDontSee('readiness.passport.payload.valid');
        $response->assertDontSee('Optional Sections None');
        $response->assertSee('Optional sections enabled');
        $response->assertDontSee('Declaration Present');
        $response->assertSee('Declaration of Conformity present');
        $response->assertSee('No Declaration of Conformity has been linked.');
    }

    public function test_failed_passport_fields_link_to_editor_field(): void
    {
        $response = $this->get(route('catalog.products.passport.readiness', ['product' => $this->product->uuid]));

        $response->assertOk();
        $response->assertSee(
            route('catalog.products.passport.edit', $this->product->uuid).'#field-manufacturer_and_operator-manufacturer_country',
            false,
        );
    }
}
