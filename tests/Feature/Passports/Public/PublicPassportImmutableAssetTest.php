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
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportAssetKind;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportAsset;
use App\Models\User;
use App\Services\Passports\DppPayloadNormalizer;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicPassportImmutableAssetTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    private ProductMedia $productMedia;

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
        Storage::disk('catalog_media')->put('test/v1-media.jpg', 'V1 media content');
        Storage::fake('product_documents');

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Immutable Asset Category',
            'slug' => 'immutable-asset-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'immutable-asset-cat-'.fake()->unique()->slug(1),
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
            'name' => 'Immutable Asset Product '.fake()->unique()->word(),
            'slug' => 'immutable-asset-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'immutable-asset-product-'.fake()->unique()->slug(1),
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
            'sku' => 'SKU-IA-001',
            'status' => ProductVariantStatus::Active,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->productMedia = new ProductMedia;
        $this->productMedia->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'original_filename' => 'v1-media.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen('V1 media content'),
            'storage_path' => 'test/v1-media.jpg',
            'checksum_sha256' => hash('sha256', 'V1 media content'),
            'sort_order' => 0,
            'uploaded_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->product->forceFill([
            'default_variant_id' => $variant->getKey(),
            'primary_media_id' => $this->productMedia->getKey(),
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

    private function injectManufacturerContact(): void
    {
        $draft = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->currentDraftVersion;

        $payload = $draft->payload;
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@immutable-asset.example';

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

    private function fillSectionsAndPublish(): void
    {
        $this->createDraft();

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Immutable Asset Test Product',
            'public_description' => 'Immutable asset test description.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Test Manufacturer Inc.',
            'responsible_operator_display_name' => 'Test Operator',
            'contact_notes' => 'Contact notes.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Immutable asset safety warning'],
            'storage_instructions' => 'Store safely.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Immutable asset recycling instructions.',
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

    private function getStreamedContent(string $uri): string
    {
        $response = $this->get($uri);
        $response->assertOk();

        ob_start();
        $response->baseResponse->sendContent();
        $content = ob_get_clean();

        return $content ?: '';
    }

    private function getMediaUrlFromPublicPage(string $publicId): ?string
    {
        $response = $this->get("/p/{$publicId}");
        $response->assertOk();

        preg_match('/\/p\/'.preg_quote($publicId, '/').'\/media\/([a-f0-9\-]{36})/', $response->getContent(), $matches);

        return $matches[1] ?? null;
    }

    private function getDocumentUrlFromPublicPage(string $publicId): ?string
    {
        $response = $this->get("/p/{$publicId}");
        $response->assertOk();

        preg_match('/\/p\/'.preg_quote($publicId, '/').'\/documents\/([a-f0-9\-]{36})/', $response->getContent(), $matches);

        return $matches[1] ?? null;
    }

    public function test_source_media_replacement_does_not_affect_public_version_1(): void
    {
        $this->fillSectionsAndPublish();

        $passport = $this->freshPassport();
        $publicId = $passport->public_id;

        $mediaUuid = $this->getMediaUrlFromPublicPage($publicId);
        $this->assertNotNull($mediaUuid, 'Media UUID not found in public page.');

        $originalContent = $this->getStreamedContent("/p/{$publicId}/media/{$mediaUuid}");
        $this->assertSame('V1 media content', $originalContent);

        Storage::disk('catalog_media')->put('test/v1-media.jpg', 'REPLACED content');

        $secondContent = $this->getStreamedContent("/p/{$publicId}/media/{$mediaUuid}");

        $this->assertSame($originalContent, $secondContent);
        $this->assertSame('V1 media content', $secondContent);
        $this->assertNotSame('REPLACED content', $secondContent);
    }

    public function test_source_media_deletion_does_not_affect_public_version_1(): void
    {
        $this->fillSectionsAndPublish();

        $passport = $this->freshPassport();
        $publicId = $passport->public_id;

        $mediaUuid = $this->getMediaUrlFromPublicPage($publicId);
        $this->assertNotNull($mediaUuid, 'Media UUID not found in public page.');

        $firstResponse = $this->get("/p/{$publicId}/media/{$mediaUuid}");
        $firstResponse->assertOk();
        $originalContent = $this->getStreamedContent("/p/{$publicId}/media/{$mediaUuid}");
        $this->assertSame('V1 media content', $originalContent);

        Storage::disk('catalog_media')->delete('test/v1-media.jpg');

        $this->assertFalse(Storage::disk('catalog_media')->exists('test/v1-media.jpg'));

        $secondContent = $this->getStreamedContent("/p/{$publicId}/media/{$mediaUuid}");

        $this->assertSame($originalContent, $secondContent);
    }

    public function test_version_2_uses_its_own_immutable_assets(): void
    {
        $this->fillSectionsAndPublish();

        $passport = $this->freshPassport();
        $publicId = $passport->public_id;

        $v1MediaUuid = $this->getMediaUrlFromPublicPage($publicId);
        $this->assertNotNull($v1MediaUuid, 'V1 media UUID not found in public page.');

        $v1Content = $this->getStreamedContent("/p/{$publicId}/media/{$v1MediaUuid}");
        $this->assertSame('V1 media content', $v1Content);

        $v1Asset = ProductPassportAsset::query()
            ->forCompany($this->company)
            ->where('uuid', $v1MediaUuid)
            ->first();
        $this->assertNotNull($v1Asset);
        $v1StorageKey = $v1Asset->storage_key;

        Storage::disk('catalog_media')->put('test/v1-media.jpg', 'V2 media content');

        $this->productMedia->forceFill([
            'size_bytes' => strlen('V2 media content'),
            'checksum_sha256' => hash('sha256', 'V2 media content'),
        ])->save();

        $passport = $this->freshPassport();
        $draft = $passport->currentDraftVersion;
        $revisionForV2 = $draft->draft_revision;

        $passport = app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            DppSectionKey::Identity->value,
            [
                'public_name' => 'Immutable Asset Test Product V2',
                'public_description' => 'V2 description.',
            ],
            $revisionForV2,
        );

        $revisionForV2 = $passport->currentDraftVersion->draft_revision;

        $draftPayload = $passport->currentDraftVersion->payload;
        $draftPayload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@v2.example';

        $normalized = app(DppPayloadNormalizer::class)->normalize($draftPayload);
        $revisionForV2++;

        $draftVersion = $passport->currentDraftVersion;
        $draftVersion->setAttribute('payload', $normalized);
        $draftVersion->setAttribute('draft_revision', $revisionForV2);
        $draftVersion->setAttribute('updated_by', $this->actor->getKey());
        $draftVersion->save();

        $this->revision = $revisionForV2;

        app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            ProductPassport::query()
                ->forCompany($this->company)
                ->where('product_id', $this->product->getKey())
                ->first(),
            $revisionForV2,
            true,
        );

        $v1AssetRefreshed = ProductPassportAsset::query()
            ->forCompany($this->company)
            ->where('uuid', $v1MediaUuid)
            ->first();
        $this->assertNotNull($v1AssetRefreshed, 'V1 immutable asset record must still exist.');
        $this->assertTrue(
            Storage::disk('passport_assets')->exists($v1AssetRefreshed->storage_key),
            'V1 immutable file must still exist on passport_assets disk.',
        );
        $this->assertSame('V1 media content', Storage::disk('passport_assets')->get($v1AssetRefreshed->storage_key));

        $v2MediaUuid = $this->getMediaUrlFromPublicPage($publicId);
        $this->assertNotNull($v2MediaUuid, 'V2 media UUID not found in public page.');

        $v2Content = $this->getStreamedContent("/p/{$publicId}/media/{$v2MediaUuid}");
        $this->assertSame('V2 media content', $v2Content);

        $this->assertNotSame($v1MediaUuid, $v2MediaUuid, 'V1 and V2 must use different immutable asset UUIDs.');
    }

    public function test_asset_checksum_stable(): void
    {
        $this->fillSectionsAndPublish();

        $passport = $this->freshPassport();
        $publicId = $passport->public_id;

        $mediaUuid = $this->getMediaUrlFromPublicPage($publicId);
        $this->assertNotNull($mediaUuid, 'Media UUID not found in public page.');

        $firstResponse = $this->get("/p/{$publicId}/media/{$mediaUuid}");
        $firstResponse->assertOk();

        $this->assertTrue($firstResponse->headers->has('ETag'));
        $firstEtag = $firstResponse->headers->get('ETag');
        $this->assertNotEmpty($firstEtag);

        $firstContent = $this->getStreamedContent("/p/{$publicId}/media/{$mediaUuid}");

        $secondResponse = $this->get("/p/{$publicId}/media/{$mediaUuid}");
        $secondResponse->assertOk();

        $this->assertTrue($secondResponse->headers->has('ETag'));
        $secondEtag = $secondResponse->headers->get('ETag');

        $this->assertSame($firstEtag, $secondEtag);

        $secondContent = $this->getStreamedContent("/p/{$publicId}/media/{$mediaUuid}");
        $this->assertSame($firstContent, $secondContent);
    }

    public function test_asset_from_wrong_passport_returns_404(): void
    {
        $this->fillSectionsAndPublish();

        $passport1 = $this->freshPassport();
        $publicId1 = $passport1->public_id;

        $mediaUuid1 = $this->getMediaUrlFromPublicPage($publicId1);
        $this->assertNotNull($mediaUuid1, 'Passport 1 media UUID not found.');

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
            'sku' => 'SKU-IA2-001',
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
            ['public_name' => 'Product 2 Name', 'public_description' => 'Product 2 description.'],
            $revision2,
        );
        $revision2 = $result->currentDraftVersion->draft_revision;

        $result = app(UpdateProductPassportSectionAction::class)->handle(
            $actor2,
            $company2,
            $product2,
            $passport2,
            DppSectionKey::ManufacturerAndOperator->value,
            ['manufacturer_display_name' => 'Mfr 2 Inc.', 'responsible_operator_display_name' => 'Operator 2', 'contact_notes' => 'Contact 2.'],
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

        $publicId2 = ProductPassport::query()
            ->forCompany($company2)
            ->where('product_id', $product2->getKey())
            ->first()
            ->public_id;

        $response = $this->get("/p/{$publicId2}/media/{$mediaUuid1}");
        $response->assertNotFound();
    }

    public function test_storage_paths_absent_from_html_and_response(): void
    {
        $this->fillSectionsAndPublish();

        $passport = $this->freshPassport();
        $publicId = $passport->public_id;

        $response = $this->get("/p/{$publicId}");
        $response->assertOk();

        $content = $response->getContent();

        $this->assertStringNotContainsString('catalog_media', $content);
        $this->assertStringNotContainsString('product_documents', $content);
        $this->assertStringNotContainsString('storage_path', $content);
        $this->assertStringNotContainsString('storage_key', $content);
    }

    public function test_immutable_asset_exists_on_passport_assets_disk(): void
    {
        $this->fillSectionsAndPublish();

        $passport = $this->freshPassport();
        $publishedVersion = $passport->currentPublishedVersion;
        $this->assertNotNull($publishedVersion);

        $assets = ProductPassportAsset::query()
            ->forCompany($this->company)
            ->where('passport_id', $passport->getKey())
            ->where('version_id', $publishedVersion->getKey())
            ->where('kind', ProductPassportAssetKind::ProductMedia)
            ->get();

        $this->assertNotEmpty($assets, 'No ProductPassportAsset records found for the published version.');

        foreach ($assets as $asset) {
            $this->assertNotEmpty($asset->storage_key, 'Asset storage_key must not be empty.');
            $this->assertTrue(
                Storage::disk('passport_assets')->exists($asset->storage_key),
                "Asset file not found on passport_assets disk: {$asset->storage_key}",
            );
        }
    }

    public function test_source_document_replacement_does_not_affect_public_version_1(): void
    {
        Storage::disk('product_documents')->put('test/cert-v1.pdf', 'PDF content V1');

        $document = ProductDocument::query()->forceCreate([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'status' => ProductDocumentStatus::Active->value,
            'created_by_user_id' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $docVersion = new ProductDocumentVersion;
        $docVersion->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'document_type' => ProductDocumentType::Certificate->value,
            'title' => 'Certificate v1',
            'language' => 'en',
            'visibility' => ProductDocumentVisibility::PassportPublic->value,
            'issuer_name' => 'Test Issuer',
            'issue_date' => now()->subMonth(),
            'original_filename' => 'cert-v1.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => strlen('PDF content V1'),
            'checksum_sha256' => hash('sha256', 'PDF content V1'),
            'storage_key' => 'test/cert-v1.pdf',
            'created_by_user_id' => $this->actor->getKey(),
        ])->save();

        $document->forceFill(['current_version_id' => $docVersion->getKey()])->save();

        $this->createDraft();

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Document Immutable Test Product',
            'public_description' => 'Document immutable test description.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Test Manufacturer Inc.',
            'responsible_operator_display_name' => 'Test Operator',
            'contact_notes' => 'Contact notes.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Document safety warning'],
            'storage_instructions' => 'Store docs safely.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Document recycling instructions.',
        ]);

        $draft = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->currentDraftVersion;

        $payload = $draft->payload;
        $payload['document_references'] = [
            [
                'document_uuid' => $document->uuid,
                'document_version_uuid' => $docVersion->uuid,
                'role' => 'certificate',
            ],
        ];

        $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

        $newRevision = $draft->draft_revision + 1;
        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $newRevision);
        $draft->setAttribute('updated_by', $this->actor->getKey());
        $draft->save();

        $this->revision = $newRevision;

        $passport = $this->freshPassport();

        app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $passport = $this->freshPassport();
        $publicId = $passport->public_id;

        $documentUuid = $this->getDocumentUrlFromPublicPage($publicId);
        $this->assertNotNull($documentUuid, 'Document UUID not found in public page.');

        $originalContent = $this->getStreamedContent("/p/{$publicId}/documents/{$documentUuid}");
        $this->assertSame('PDF content V1', $originalContent);

        Storage::disk('product_documents')->put('test/cert-v1.pdf', 'PDF content REPLACED');

        $secondContent = $this->getStreamedContent("/p/{$publicId}/documents/{$documentUuid}");

        $this->assertSame($originalContent, $secondContent);
        $this->assertSame('PDF content V1', $secondContent);
        $this->assertNotSame('PDF content REPLACED', $secondContent);
    }
}
