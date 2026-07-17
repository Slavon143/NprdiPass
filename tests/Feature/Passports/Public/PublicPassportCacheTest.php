<?php

namespace Tests\Feature\Passports\Public;

use App\Actions\Passports\ArchiveProductPassport;
use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\UnpublishProductPassport;
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

class PublicPassportCacheTest extends TestCase
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
        Storage::disk('catalog_media')->put('test/cache-product.jpg', 'fake content');

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Cache Test Category',
            'slug' => 'cache-test-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'cache-test-cat-'.fake()->unique()->slug(1),
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
            'name' => 'Cache Test Product '.fake()->unique()->word(),
            'slug' => 'cache-test-'.fake()->unique()->slug(1),
            'slug_normalized' => 'cache-test-'.fake()->unique()->slug(1),
            'brand' => 'Cache Brand',
            'manufacturer' => 'Cache Manufacturer',
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
            'sku' => 'SKU-CACHE-001',
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
            'original_filename' => 'cache-product.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/cache-product.jpg',
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

    private function createDraft(): void
    {
        $this->passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $this->passport->currentDraftVersion->draft_revision;
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

    private function fillMinimalSections(string $publicName = 'Cache Product V1'): void
    {
        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => $publicName,
            'public_description' => 'Cache test description.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Cache Manufacturer Inc.',
            'contact_notes' => '<img src=x onerror=alert(1)>',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Cache warning'],
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Cache recycling.',
        ]);
    }

    private function injectManufacturerContact(): void
    {
        $draft = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->currentDraftVersion;

        $payload = $draft->payload;
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@cache-test.example';

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

    private function publish(): void
    {
        $passport = $this->freshPassport();

        app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $this->passport = $passport->fresh(['currentDraftVersion', 'currentPublishedVersion']);
        $this->revision = $this->passport->currentDraftVersion->draft_revision;
        $this->publicId = $this->passport->public_id;
    }

    private function publishAndPrepare(): void
    {
        $this->createDraft();
        $this->fillMinimalSections();
        $this->publish();
    }

    public function test_first_request_creates_cache(): void
    {
        $this->publishAndPrepare();

        auth()->guard('web')->logout();

        $response = $this->get("/p/{$this->publicId}");
        $response->assertOk();
    }

    public function test_second_request_uses_same_response(): void
    {
        $this->publishAndPrepare();

        auth()->guard('web')->logout();

        $first = $this->get("/p/{$this->publicId}");
        $first->assertOk();

        $second = $this->get("/p/{$this->publicId}");
        $second->assertOk();

        $this->assertSame($first->getContent(), $second->getContent());
    }

    public function test_etag_returned(): void
    {
        $this->publishAndPrepare();

        auth()->guard('web')->logout();

        $response = $this->get("/p/{$this->publicId}");
        $response->assertOk();
        $response->assertHeader('ETag');

        $this->assertNotEmpty($response->headers->get('ETag'));
    }

    public function test_if_none_match_returns_304(): void
    {
        $this->publishAndPrepare();

        auth()->guard('web')->logout();

        $first = $this->get("/p/{$this->publicId}");
        $first->assertOk();

        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $second = $this->get("/p/{$this->publicId}", ['If-None-Match' => $etag]);
        $second->assertStatus(304);
    }

    public function test_publish_version_2_invalidates_page(): void
    {
        $this->publishAndPrepare();

        auth()->guard('web')->logout();

        $url = "/p/{$this->publicId}";

        $v1 = $this->get($url);
        $v1->assertOk();
        $v1->assertSee('Cache Product V1', false);

        $this->actingAs($this->actor);
        app(CurrentCompany::class)->set($this->company);

        $passport = $this->freshPassport();
        $this->revision = $passport->currentDraftVersion->draft_revision;

        $this->fillMinimalSections('Cache Product V2');

        $this->publish();

        auth()->guard('web')->logout();

        $v2 = $this->get($url);
        $v2->assertOk();
        $v2->assertSee('Cache Product V2', false);
        $v2->assertDontSee('Cache Product V1', false);
    }

    public function test_unpublish_invalidates_page(): void
    {
        $this->publishAndPrepare();

        auth()->guard('web')->logout();

        $url = "/p/{$this->publicId}";
        $this->get($url)->assertOk();

        $this->actingAs($this->actor);
        app(CurrentCompany::class)->set($this->company);

        app(UnpublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        auth()->guard('web')->logout();

        $this->get($url)->assertNotFound();
    }

    public function test_archive_invalidates_page(): void
    {
        $this->publishAndPrepare();

        auth()->guard('web')->logout();

        $url = "/p/{$this->publicId}";
        $this->get($url)->assertOk();

        $this->actingAs($this->actor);
        app(CurrentCompany::class)->set($this->company);

        $passport = $this->freshPassport();

        app(UnpublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
        );

        app(ArchiveProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        auth()->guard('web')->logout();

        $this->get($url)->assertNotFound();
    }

    public function test_asset_cache_immutable(): void
    {
        $this->publishAndPrepare();

        auth()->guard('web')->logout();

        $resp = $this->get("/p/{$this->publicId}");
        $resp->assertOk();

        preg_match('/\/p\/'.preg_quote($this->publicId, '/').'\/media\/([a-f0-9\-]{36})/', $resp->getContent(), $matches);
        $this->assertNotEmpty($matches, 'Media URL not found in public page HTML.');
        $mediaUuid = $matches[1];

        $mediaResponse = $this->get("/p/{$this->publicId}/media/{$mediaUuid}");
        $mediaResponse->assertOk();

        $cacheControl = $mediaResponse->headers->get('Cache-Control');
        $this->assertStringContainsString('immutable', $cacheControl);
    }
}
