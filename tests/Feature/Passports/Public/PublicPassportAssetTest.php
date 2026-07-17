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

class PublicPassportAssetTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    private ProductPassport $passport;

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
        Storage::disk('catalog_media')->put('test/product.jpg', 'fake image content');

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Asset Test Category',
            'slug' => 'asset-test-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'asset-test-cat-'.fake()->unique()->slug(1),
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
            'name' => 'Asset Test Product '.fake()->unique()->word(),
            'slug' => 'asset-test-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'asset-test-product-'.fake()->unique()->slug(1),
            'brand' => 'Test Brand',
            'manufacturer' => 'Test Manufacturer',
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
            'sku' => 'SKU-AT-001',
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
            'original_filename' => 'product.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen('fake image content'),
            'storage_path' => 'test/product.jpg',
            'checksum_sha256' => hash('sha256', 'fake image content'),
            'sort_order' => 0,
            'uploaded_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();
        $this->product->forceFill(['primary_media_id' => $media->getKey()])->save();
        $this->product->refresh();

        $this->product->forceFill([
            'default_variant_id' => $variant->getKey(),
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

    private function fillSectionsAndPublish(): void
    {
        $this->createDraft();

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Asset Test Product Name',
            'public_description' => 'Asset test description.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Test Manufacturer Inc.',
            'responsible_operator_display_name' => 'Test Operator',
            'contact_notes' => 'Contact notes.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Asset test safety warning'],
            'storage_instructions' => 'Store safely.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Asset test recycling instructions.',
        ]);

        $passport = $this->freshPassport();

        app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );
    }

    private function injectManufacturerContact(): void
    {
        $draft = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->currentDraftVersion;

        $payload = $draft->payload;
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@asset-test.example';

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

    public function test_primary_image_accessible(): void
    {
        $this->fillSectionsAndPublish();

        $passport = $this->freshPassport();
        $publicId = $passport->public_id;

        $response = $this->get("/p/{$publicId}");
        $response->assertOk();

        preg_match('/\/p\/'.preg_quote($publicId, '/').'\/media\/([a-f0-9\-]{36})/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'Media URL not found in public page HTML.');
        $mediaUuid = $matches[1];

        $mediaResponse = $this->get("/p/{$publicId}/media/{$mediaUuid}");
        $mediaResponse->assertOk();
        $mediaResponse->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_invalid_media_uuid_returns_404(): void
    {
        $this->fillSectionsAndPublish();

        $passport = $this->freshPassport();
        $publicId = $passport->public_id;
        $randomUuid = (string) str()->uuid();

        $response = $this->get("/p/{$publicId}/media/{$randomUuid}");
        $response->assertNotFound();
    }

    public function test_wrong_passport_media_returns_404(): void
    {
        $this->fillSectionsAndPublish();

        $passport1 = $this->freshPassport();
        $publicId1 = $passport1->public_id;

        $mediaUuid = $passport1->currentPublishedVersion->payload['_catalog_context']['media'][0]['uuid'];

        $company2 = Company::factory()->create(['status' => CompanyStatus::Active]);
        $actor2 = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'company_id' => $company2->getKey(),
            'user_id' => $actor2->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $this->actingAs($actor2);
        app(CurrentCompany::class)->set($company2);

        Storage::fake('catalog_media');
        Storage::disk('catalog_media')->put('test/product2.jpg', 'fake image content 2');

        $category2 = new Category;
        $category2->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $company2->getKey(),
            'name' => 'Asset Test Category 2',
            'slug' => 'asset-test-cat-2-'.fake()->unique()->slug(1),
            'slug_normalized' => 'asset-test-cat-2-'.fake()->unique()->slug(1),
            'depth' => 0,
            'sort_order' => 0,
            'status' => CategoryStatus::Active,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $product2 = new Product;
        $product2->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $company2->getKey(),
            'name' => 'Asset Test Product 2 '.fake()->unique()->word(),
            'slug' => 'asset-test-product-2-'.fake()->unique()->slug(1),
            'slug_normalized' => 'asset-test-product-2-'.fake()->unique()->slug(1),
            'brand' => 'Test Brand 2',
            'manufacturer' => 'Test Manufacturer 2',
            'status' => ProductStatus::Active,
            'primary_category_id' => $category2->getKey(),
            'created_by' => $actor2->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $variant2 = new ProductVariant;
        $variant2->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $company2->getKey(),
            'product_id' => $product2->getKey(),
            'name' => 'Default Variant 2',
            'sku' => 'SKU-AT2-001',
            'status' => ProductVariantStatus::Active,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $media2 = new ProductMedia;
        $media2->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $company2->getKey(),
            'product_id' => $product2->getKey(),
            'original_filename' => 'product2.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen('fake image content 2'),
            'storage_path' => 'test/product2.jpg',
            'checksum_sha256' => hash('sha256', 'fake image content 2'),
            'sort_order' => 0,
            'uploaded_by' => $actor2->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();
        $product2->forceFill(['primary_media_id' => $media2->getKey()])->save();
        $product2->forceFill(['default_variant_id' => $variant2->getKey()])->save();
        $product2->categories()->attach($category2->getKey(), [
            'company_id' => $company2->getKey(),
            'created_at' => now(),
        ]);
        $product2->refresh();

        $passport2 = app(CreateProductPassportDraftAction::class)->handle($actor2, $company2, $product2);
        $revision2 = $passport2->currentDraftVersion->draft_revision;

        $result = app(UpdateProductPassportSectionAction::class)->handle(
            $actor2,
            $company2,
            $product2,
            $passport2,
            DppSectionKey::Identity->value,
            [
                'public_name' => 'Product 2 Name',
                'public_description' => 'Product 2 description.',
            ],
            $revision2,
        );
        $revision2 = $result->currentDraftVersion->draft_revision;

        $result = app(UpdateProductPassportSectionAction::class)->handle(
            $actor2,
            $company2,
            $product2,
            $passport2,
            DppSectionKey::ManufacturerAndOperator->value,
            [
                'manufacturer_display_name' => 'Mfr 2 Inc.',
                'responsible_operator_display_name' => 'Operator 2',
                'contact_notes' => 'Contact 2.',
            ],
            $revision2,
        );
        $revision2 = $result->currentDraftVersion->draft_revision;

        $draft2 = ProductPassport::query()
            ->forCompany($company2)
            ->where('product_id', $product2->getKey())
            ->first()
            ->currentDraftVersion;

        $payload2 = $draft2->payload;
        $payload2['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@passport2.example';

        $normalized2 = app(DppPayloadNormalizer::class)->normalize($payload2);
        $revision2++;
        $draft2->setAttribute('payload', $normalized2);
        $draft2->setAttribute('draft_revision', $revision2);
        $draft2->setAttribute('updated_by', $actor2->getKey());
        $draft2->save();

        $result = app(UpdateProductPassportSectionAction::class)->handle(
            $actor2,
            $company2,
            $product2,
            $passport2,
            DppSectionKey::Safety->value,
            ['warnings' => ['Warning 2']],
            $revision2,
        );
        $revision2 = $result->currentDraftVersion->draft_revision;

        $result = app(UpdateProductPassportSectionAction::class)->handle(
            $actor2,
            $company2,
            $product2,
            $passport2,
            DppSectionKey::RecyclingAndDisposal->value,
            ['recycling_instructions' => 'Recycle 2.'],
            $revision2,
        );
        $revision2 = $result->currentDraftVersion->draft_revision;

        app(PublishProductPassport::class)->handle(
            $actor2,
            $company2,
            $product2,
            ProductPassport::query()
                ->forCompany($company2)
                ->where('product_id', $product2->getKey())
                ->first(),
            $revision2,
            true,
        );

        $response = $this->get("/p/{$publicId1}/media/{$mediaUuid}");
        $response->assertNotFound();
    }

    public function test_path_traversal_blocked(): void
    {
        $this->fillSectionsAndPublish();

        $passport = $this->freshPassport();
        $publicId = $passport->public_id;

        $response = $this->get("/p/{$publicId}/media/../../some-file");
        $response->assertNotFound();
    }

    public function test_media_cache_headers(): void
    {
        $this->fillSectionsAndPublish();

        $passport = $this->freshPassport();
        $publicId = $passport->public_id;

        $resp = $this->get("/p/{$publicId}");
        $resp->assertOk();

        preg_match('/\/p\/'.preg_quote($publicId, '/').'\/media\/([a-f0-9\-]{36})/', $resp->getContent(), $matches);
        $this->assertNotEmpty($matches, 'Media URL not found in public page HTML.');
        $mediaUuid = $matches[1];

        $mediaResponse = $this->get("/p/{$publicId}/media/{$mediaUuid}");
        $mediaResponse->assertOk();

        $cacheControl = $mediaResponse->headers->get('Cache-Control');
        $this->assertStringContainsString('immutable', $cacheControl);
        $this->assertSame('nosniff', $mediaResponse->headers->get('X-Content-Type-Options'));
    }

    public function test_etag_returned_for_media(): void
    {
        $this->fillSectionsAndPublish();

        $passport = $this->freshPassport();
        $publicId = $passport->public_id;

        $resp = $this->get("/p/{$publicId}");
        $resp->assertOk();

        preg_match('/\/p\/'.preg_quote($publicId, '/').'\/media\/([a-f0-9\-]{36})/', $resp->getContent(), $matches);
        $this->assertNotEmpty($matches, 'Media URL not found in public page HTML.');
        $mediaUuid = $matches[1];

        $mediaResponse = $this->get("/p/{$publicId}/media/{$mediaUuid}");
        $mediaResponse->assertOk();

        $this->assertTrue($mediaResponse->headers->has('ETag'));
        $this->assertNotEmpty($mediaResponse->headers->get('ETag'));
    }
}
