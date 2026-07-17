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

class PublicPassportLanguageTest extends TestCase
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
            'name' => 'Multilingual Test Product',
            'slug' => 'multilingual-test-'.fake()->unique()->slug(1),
            'slug_normalized' => 'multilingual-test-'.fake()->unique()->slug(1),
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
                    'en' => ['identity' => ['public_name' => 'English Product Name', 'public_description' => 'An English description.']],
                    'sv' => ['identity' => ['public_name' => 'Svenskt Produktnamn', 'public_description' => 'En svensk beskrivning.']],
                ],
                'document_references' => [],
                '_catalog_context' => [
                    'product' => [
                        'name' => 'Multilingual Test Product',
                        'brand' => 'Test Brand',
                        'manufacturer' => 'Test Manufacturer',
                        'primary_category_name' => 'Test Category',
                    ],
                    'default_variant' => null,
                    'media' => [],
                    'documents' => [],
                ],
            ],
            'content_checksum' => hash('sha256', 'v1-language-test'),
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

    public function test_default_locale_renders_english_content(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId);
        $response->assertOk();

        $response->assertSee('English Product Name', false);
        $response->assertSee('An English description.', false);
        $response->assertDontSee('Svenskt Produktnamn', false);
    }

    public function test_lang_en_renders_english_content(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId.'?lang=en');
        $response->assertOk();

        $response->assertSee('English Product Name', false);
        $response->assertSee('An English description.', false);
    }

    public function test_lang_sv_renders_swedish_content(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId.'?lang=sv');
        $response->assertOk();

        $response->assertSee('Svenskt Produktnamn', false);
        $response->assertSee('En svensk beskrivning.', false);
    }

    public function test_language_selector_visible_when_multiple_locales_enabled(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId);
        $response->assertOk();

        $response->assertSee('Language', false);
        $response->assertSee('English', false);
        $response->assertSee('Svenska', false);
    }

    public function test_language_selector_hidden_when_single_locale(): void
    {
        $this->passport->forceFill(['enabled_languages' => ['en']])->save();

        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId);
        $response->assertOk();

        $response->assertDontSee('Svenska', false);
        $response->assertDontSee('hreflang=', false);
    }

    public function test_unsupported_lang_falls_back_to_default(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId.'?lang=de');
        $response->assertOk();

        $response->assertSee('English Product Name', false);
        $response->assertDontSee('Svenskt Produktnamn', false);
    }

    public function test_fallback_notice_shows_when_using_fallback(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId.'?lang=de');
        $response->assertOk();

        $response->assertSee('translation is not available', false);
    }

    public function test_admin_navigation_does_not_appear(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId.'?lang=sv');
        $response->assertOk();

        $response->assertDontSee('passport.edit', false);
        $response->assertDontSee('catalog.products.passport', false);
        $response->assertDontSee('/dashboard', false);
        $response->assertDontSee('/admin', false);
    }

    public function test_raw_locale_keys_are_absent(): void
    {
        auth()->guard('web')->logout();

        $response = $this->get('/p/'.$this->publicId.'?lang=sv');
        $response->assertOk();

        $response->assertDontSee('dpp.', false);
        $response->assertDontSee('passport.', false);
        $response->assertDontSee('catalog.', false);
    }
}
