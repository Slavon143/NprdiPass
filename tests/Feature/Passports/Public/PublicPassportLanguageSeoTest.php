<?php

namespace Tests\Feature\Passports\Public;

use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPassportLanguageSeoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private ProductPassport $passport;

    private string $publicId;

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

        $product = new Product;
        $product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'SEO Multilingual Product',
            'slug' => 'seo-multilingual-'.fake()->unique()->slug(1),
            'slug_normalized' => 'seo-multilingual-'.fake()->unique()->slug(1),
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

        $version = new ProductPassportVersion;
        $version->forceFill([
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
                    'en' => ['identity' => ['public_name' => 'English SEO Product', 'public_description' => 'SEO English description.']],
                    'sv' => ['identity' => ['public_name' => 'Svensk SEO Produkt', 'public_description' => 'SEO svensk beskrivning.']],
                ],
                'document_references' => [],
                '_catalog_context' => [
                    'product' => [
                        'name' => 'SEO Multilingual Product',
                        'brand' => 'Test Brand',
                        'manufacturer' => 'Test Manufacturer',
                        'primary_category_name' => 'Test Category',
                    ],
                    'default_variant' => null,
                    'media' => [],
                    'documents' => [],
                ],
            ],
            'content_checksum' => hash('sha256', 'v1-seo-test'),
            'published_at' => now(),
            'published_by' => $this->actor->getKey(),
            'created_by' => $this->actor->getKey(),
        ])->save();

        $this->passport->forceFill([
            'current_published_version_id' => $version->getKey(),
            'status' => ProductPassportStatus::Published,
        ])->save();

        $this->publicId = $this->passport->public_id;
    }

    public function test_html_lang_attribute_matches_requested_locale(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId.'?lang=sv');
        $response->assertOk();

        $response->assertSee('<html lang="sv"', false);
    }

    public function test_html_lang_attribute_defaults_when_no_lang_param(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId);
        $response->assertOk();

        $response->assertSee('<html lang="en"', false);
    }

    public function test_canonical_url_includes_lang_param_when_non_default(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId.'?lang=sv');
        $response->assertOk();

        $response->assertSee('<link rel="canonical" href="'.url('p/'.$this->publicId.'?lang=sv').'"', false);
    }

    public function test_canonical_url_omits_lang_param_for_default_locale(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId.'?lang=en');
        $response->assertOk();

        $response->assertSee('<link rel="canonical" href="'.url('p/'.$this->publicId).'"', false);
    }

    public function test_hreflang_en_link_present(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId);
        $response->assertOk();

        $response->assertSee('<link rel="alternate" hreflang="en"', false);
        $response->assertSee('hreflang="en" href="'.url('p/'.$this->publicId.'?lang=en').'"', false);
    }

    public function test_hreflang_sv_link_present(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId);
        $response->assertOk();

        $response->assertSee('<link rel="alternate" hreflang="sv"', false);
        $response->assertSee('hreflang="sv" href="'.url('p/'.$this->publicId.'?lang=sv').'"', false);
    }

    public function test_hreflang_links_absent_when_single_locale(): void
    {
        $this->passport->forceFill(['enabled_languages' => ['en']])->save();

        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId);
        $response->assertOk();

        $response->assertDontSee('hreflang=', false);
    }

    public function test_x_default_link_present(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId);
        $response->assertOk();

        $response->assertSee('<link rel="alternate" hreflang="x-default"', false);
        $response->assertSee('hreflang="x-default" href="'.url('p/'.$this->publicId).'"', false);
    }

    public function test_open_graph_url_reflects_locale(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId.'?lang=sv');
        $response->assertOk();

        $response->assertSee('<meta property="og:url" content="'.url('p/'.$this->publicId.'?lang=sv').'"', false);
    }

    public function test_open_graph_title_is_present(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId.'?lang=sv');
        $response->assertOk();

        $response->assertSee('<meta property="og:title"', false);
        $response->assertSee('SEO Multilingual Product', false);
    }

    public function test_json_ld_contains_localized_url(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId.'?lang=sv');
        $response->assertOk();

        $response->assertSee('application/ld+json', false);
        $response->assertSee('@context', false);
        $response->assertSee('https://schema.org', false);
        $response->assertSee(url('p/'.$this->publicId.'?lang=sv'), false);
    }

    public function test_no_draft_data_leaked(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId.'?lang=sv');
        $response->assertOk();

        $response->assertDontSee('draft_revision', false);
        $response->assertDontSee('draft', false);
        $response->assertDontSee('Draft', false);
    }

    public function test_no_internal_uuids_leaked(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId.'?lang=sv');
        $response->assertOk();

        $response->assertDontSee($this->passport->uuid, false);
        $response->assertDontSee($this->company->uuid, false);
    }
}
