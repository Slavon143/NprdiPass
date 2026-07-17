<?php

namespace Tests\Feature\Passports\Public;

use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\ProductPassportAssetKind;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportAsset;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicPassportLanguageCacheTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private ProductPassport $passport;

    private ProductPassportVersion $version;

    private ProductPassportAsset $asset;

    private string $publicId;

    private string $checksum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checksum = hash('sha256', 'v1-checksum-abc');

        $this->company = Company::factory()->create(['status' => CompanyStatus::Active]);
        $this->actor = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'company_id' => $this->company->getKey(),
            'user_id' => $this->actor->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $this->actingAs($this->actor);
        app(CurrentCompany::class)->set($this->company);

        Storage::disk('passport_assets')->put('test/cache-asset.png', 'fake media content');

        $product = new Product;
        $product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Cache Test Product',
            'slug' => 'cache-test-'.fake()->unique()->slug(1),
            'slug_normalized' => 'cache-test-'.fake()->unique()->slug(1),
            'brand' => 'Test Brand',
            'manufacturer' => 'Test Manufacturer',
            'status' => ProductStatus::Active,
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->passport = new ProductPassport;
        $this->passport->forceFill([
            'company_id' => $this->company->getKey(),
            'product_id' => $product->getKey(),
            'default_language' => 'en',
            'enabled_languages' => ['en', 'sv'],
            'status' => ProductPassportStatus::Draft,
            'created_by' => $this->actor->getKey(),
        ])->save();

        $this->version = new ProductPassportVersion;
        $this->version->forceFill([
            'company_id' => $this->company->getKey(),
            'passport_id' => $this->passport->getKey(),
            'version_number' => 1,
            'status' => ProductPassportVersionStatus::Published,
            'draft_revision' => 1,
            'schema_version' => '1.0',
            'payload' => [
                'enabled_sections' => ['identity'],
                'data' => [],
                'translations' => [
                    'en' => ['identity' => ['public_name' => 'English Cache Product', 'public_description' => 'Cache English description.']],
                    'sv' => ['identity' => ['public_name' => 'Svensk Cache Produkt', 'public_description' => 'Cache svensk beskrivning.']],
                ],
                'document_references' => [],
                '_catalog_context' => [
                    'product' => [
                        'name' => 'Cache Test Product',
                        'brand' => 'Test Brand',
                        'manufacturer' => 'Test Manufacturer',
                        'primary_category_name' => 'Test Category',
                    ],
                    'default_variant' => null,
                    'media' => [],
                    'documents' => [],
                ],
            ],
            'content_checksum' => $this->checksum,
            'published_at' => now(),
            'published_by' => $this->actor->getKey(),
            'created_by' => $this->actor->getKey(),
        ])->save();

        $this->passport->forceFill([
            'current_published_version_id' => $this->version->getKey(),
            'status' => ProductPassportStatus::Published,
        ])->save();

        $this->publicId = $this->passport->public_id;

        $assetChecksum = hash('sha256', 'fake media content');

        $this->asset = new ProductPassportAsset;
        $this->asset->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'passport_id' => $this->passport->getKey(),
            'version_id' => $this->version->getKey(),
            'kind' => ProductPassportAssetKind::ProductMedia,
            'language' => null,
            'mime_type' => 'image/png',
            'file_extension' => 'png',
            'size_bytes' => 18,
            'checksum_sha256' => $assetChecksum,
            'storage_key' => 'test/cache-asset.png',
            'is_public' => true,
            'sort_order' => 0,
        ])->save();
    }

    public function test_english_and_swedish_have_separate_etags(): void
    {
        auth()->guard('web')->logout();

        $enResponse = $this->get('/p/'.$this->publicId.'?lang=en');
        $enResponse->assertOk();
        $enEtag = $enResponse->headers->get('ETag');

        $svResponse = $this->get('/p/'.$this->publicId.'?lang=sv');
        $svResponse->assertOk();
        $svEtag = $svResponse->headers->get('ETag');

        $this->assertNotNull($enEtag);
        $this->assertNotNull($svEtag);
        $this->assertNotEquals($enEtag, $svEtag);
    }

    public function test_same_locale_returns_stable_etag(): void
    {
        auth()->guard('web')->logout();

        $first = $this->get('/p/'.$this->publicId.'?lang=en');
        $first->assertOk();

        $second = $this->get('/p/'.$this->publicId.'?lang=en');
        $second->assertOk();

        $this->assertSame($first->headers->get('ETag'), $second->headers->get('ETag'));
    }

    public function test_if_none_match_returns_304(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId.'?lang=en');
        $response->assertOk();
        $etag = $response->headers->get('ETag');

        $this->assertNotNull($etag);

        $this->get('/p/'.$this->publicId.'?lang=en', ['If-None-Match' => $etag])
            ->assertStatus(304);
    }

    public function test_version_2_bypasses_version_1_cache(): void
    {
        auth()->guard('web')->logout();

        $v1Response = $this->get('/p/'.$this->publicId.'?lang=en');
        $v1Response->assertOk();
        $v1Etag = $v1Response->headers->get('ETag');

        $this->assertNotNull($v1Etag);

        $bogusEtag = '"not-the-v2-etag"';

        $v2 = new ProductPassportVersion;
        $v2->forceFill([
            'company_id' => $this->company->getKey(),
            'passport_id' => $this->passport->getKey(),
            'version_number' => 2,
            'status' => ProductPassportVersionStatus::Published,
            'draft_revision' => 1,
            'schema_version' => '1.0',
            'payload' => [
                'enabled_sections' => ['identity'],
                'data' => [],
                'translations' => [
                    'en' => ['identity' => ['public_name' => 'English Cache Product V2', 'public_description' => 'V2 description.']],
                    'sv' => ['identity' => ['public_name' => 'Svensk Cache Produkt V2', 'public_description' => 'V2 svensk beskrivning.']],
                ],
                'document_references' => [],
                '_catalog_context' => [
                    'product' => [
                        'name' => 'Cache Test Product',
                        'brand' => 'Test Brand',
                        'manufacturer' => 'Test Manufacturer',
                        'primary_category_name' => 'Test Category',
                    ],
                    'default_variant' => null,
                    'media' => [],
                    'documents' => [],
                ],
            ],
            'content_checksum' => hash('sha256', 'v2-checksum-xyz'),
            'published_at' => now(),
            'published_by' => $this->actor->getKey(),
            'created_by' => $this->actor->getKey(),
        ])->save();

        $this->passport->forceFill([
            'current_published_version_id' => $v2->getKey(),
        ])->save();

        $v2Response = $this->get('/p/'.$this->publicId.'?lang=en');
        $v2Response->assertOk();
        $v2Etag = $v2Response->headers->get('ETag');

        $this->assertNotNull($v2Etag);
        $this->assertNotEquals($v1Etag, $v2Etag);

        $this->get('/p/'.$this->publicId.'?lang=en', ['If-None-Match' => $v2Etag])
            ->assertStatus(304);

        $this->get('/p/'.$this->publicId.'?lang=en', ['If-None-Match' => $bogusEtag])
            ->assertStatus(200);
    }

    public function test_asset_cache_remains_unchanged_across_locale_changes(): void
    {
        auth()->guard('web')->logout();

        $assetUrl = '/p/'.$this->publicId.'/media/'.$this->asset->uuid;

        $enResponse = $this->get($assetUrl.'?lang=en');
        $enResponse->assertOk();

        $svResponse = $this->get($assetUrl.'?lang=sv');
        $svResponse->assertOk();

        $enEtag = $enResponse->headers->get('ETag');
        $svEtag = $svResponse->headers->get('ETag');

        $this->assertNotNull($enEtag);
        $this->assertNotNull($svEtag);
        $this->assertSame($enEtag, $svEtag);
    }

    public function test_asset_if_none_match_returns_304_regardless_of_locale(): void
    {
        auth()->guard('web')->logout();

        $assetUrl = '/p/'.$this->publicId.'/media/'.$this->asset->uuid;
        $etag = '"'.hash('sha256', 'fake media content').'"';

        $this->get($assetUrl.'?lang=en', ['If-None-Match' => $etag])
            ->assertStatus(304);

        $this->get($assetUrl.'?lang=sv', ['If-None-Match' => $etag])
            ->assertStatus(304);
    }
}
