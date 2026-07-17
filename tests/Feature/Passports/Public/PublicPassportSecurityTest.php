<?php

namespace Tests\Feature\Passports\Public;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
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
use App\Services\Passports\DppPayloadNormalizer;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicPassportSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    private ProductPassport $passport;

    private string $publicId;

    private int $revision = 1;

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

        Storage::fake('catalog_media');
        Storage::disk('catalog_media')->put('test/security.jpg', 'fake content');

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Security Test Category',
            'slug' => 'security-test-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'security-test-cat-'.fake()->unique()->slug(1),
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
            'slug' => 'security-test-'.fake()->unique()->slug(1),
            'slug_normalized' => 'security-test-'.fake()->unique()->slug(1),
            'brand' => 'Security Brand',
            'manufacturer' => 'Security Manufacturer',
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
            'original_filename' => 'security.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/security.jpg',
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
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $this->passport->currentDraftVersion->draft_revision;

        $this->fillIdentityUnsafe();
        $this->fillSafety();
        $this->fillRecycling();
        $this->fillManufacturerUnsafe();
        $this->injectUnsafeManufacturerWebsite();

        $passport = $this->freshPassport();

        app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $this->passport = $passport->fresh(['currentPublishedVersion']);
        $this->publicId = $this->passport->public_id;
    }

    private function fillSection(DppSectionKey $section, array $payload): void
    {
        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        $result = app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $section->value,
            $payload,
            $this->revision,
        );

        $this->revision = $result->currentDraftVersion->draft_revision;
    }

    private function fillIdentityUnsafe(): void
    {
        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => '<b>bold</b>',
            'public_description' => '<script>alert(1)</script>',
        ]);
    }

    private function fillSafety(): void
    {
        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Warning A'],
        ]);
    }

    private function fillRecycling(): void
    {
        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Recycle properly.',
        ]);
    }

    private function fillManufacturerUnsafe(): void
    {
        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Manufacturer Inc.',
            'contact_notes' => '<img src=x onerror=alert(1)>',
        ]);
    }

    private function injectUnsafeManufacturerWebsite(): void
    {
        $draft = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->currentDraftVersion;

        $payload = $draft->payload;
        $payload['data']['manufacturer_and_operator']['manufacturer_website'] = 'javascript:alert(1)';
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@security.example';

        $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

        $newRevision = $draft->draft_revision + 1;
        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $newRevision);
        $draft->setAttribute('updated_by', $this->actor->getKey());
        $draft->save();

        $this->revision = $newRevision;
    }

    private function freshPassport(): ProductPassport
    {
        return ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion', 'currentPublishedVersion']);
    }

    public function test_stored_script_escaped(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get("/p/{$this->publicId}");
        $response->assertOk();

        $response->assertDontSee('<script>alert', false);
        $response->assertSee('&lt;script&gt;alert', false);
        $response->assertDontSee('javascript://alert', false);
    }

    public function test_javascript_url_not_become_link(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get("/p/{$this->publicId}");
        $response->assertOk();

        $response->assertDontSee('javascript:', false);
    }

    public function test_html_injection_escaped(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get("/p/{$this->publicId}");
        $response->assertOk();

        $response->assertDontSee('<b>', false);
        $response->assertDontSee('</b>', false);
        $response->assertSee('&lt;b&gt;', false);
    }

    public function test_json_ld_valid(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get("/p/{$this->publicId}");
        $response->assertOk();

        $content = $response->getContent();
        preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $matches);
        $this->assertNotEmpty($matches, 'JSON-LD script not found in page.');

        $json = json_decode($matches[1], true);
        $this->assertIsArray($json, 'JSON-LD script is not valid JSON.');
        $this->assertNotNull($json, 'JSON-LD script decoded to null.');
    }

    public function test_private_storage_path_absent(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get("/p/{$this->publicId}");
        $response->assertOk();

        $response->assertDontSee('catalog_media', false);
        $response->assertDontSee('product_documents', false);
        $response->assertDontSee('storage/app', false);
    }

    public function test_company_numeric_id_absent(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get("/p/{$this->publicId}");
        $response->assertOk();

        $response->assertDontSee('company_id=', false);
        $response->assertDontSee('"company_id"', false);
    }

    public function test_document_version_uuid_not_visible(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get("/p/{$this->publicId}");
        $response->assertOk();

        $response->assertDontSee('document_version_uuid', false);
        $response->assertDontSee('version_uuid', false);
    }

    public function test_rate_limiter_works(): void
    {
        auth()->guard('web')->logout();

        $found429 = false;

        for ($i = 0; $i < 100; $i++) {
            $resp = $this->get("/p/{$this->publicId}");

            if ($resp->status() === 429) {
                $found429 = true;
                break;
            }
        }

        $this->assertTrue($found429, 'Expected a 429 response from the rate limiter after many rapid requests.');
    }
}
