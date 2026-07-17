<?php

namespace Tests\Feature\Passports\Localization;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PassportLocaleIsolationTest extends TestCase
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

        $this->product = new Product;
        $this->product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Locale Isolation Product '.fake()->unique()->word(),
            'slug' => 'locale-isolation-'.fake()->unique()->slug(1),
            'slug_normalized' => 'locale-isolation-'.fake()->unique()->slug(1),
            'status' => ProductStatus::Active,
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();
        $this->product->refresh();

        $this->passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->passport->setAttribute('enabled_languages', ['sv', 'en']);
        $this->passport->save();

        $this->revision = $this->passport->currentDraftVersion->draft_revision;
    }

    private function saveSection(DppSectionKey $section, array $payload, string $locale): ProductPassport
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
            $locale,
        );

        $this->revision = $result->currentDraftVersion->draft_revision;

        return $result;
    }

    private function freshPayload(): array
    {
        return ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion'])
            ->currentDraftVersion
            ->payload;
    }

    public function test_english_identity_preserved_when_saving_swedish_safety(): void
    {
        $this->saveSection(DppSectionKey::Identity, [
            'public_name' => 'English Product Name',
            'public_description' => 'English description.',
        ], 'en');

        $this->saveSection(DppSectionKey::Safety, [
            'warnings' => ['Svensk varning'],
            'storage_instructions' => 'Svensk förvaring.',
        ], 'sv');

        $payload = $this->freshPayload();

        $this->assertArrayHasKey('en', $payload['translations']);
        $this->assertArrayHasKey(DppSectionKey::Identity->value, $payload['translations']['en']);
        $this->assertSame(
            'English Product Name',
            $payload['translations']['en'][DppSectionKey::Identity->value]['public_name'],
        );

        $this->assertArrayHasKey('sv', $payload['translations']);
        $this->assertArrayHasKey(DppSectionKey::Safety->value, $payload['translations']['sv']);
        $this->assertSame(
            ['Svensk varning'],
            $payload['translations']['sv'][DppSectionKey::Safety->value]['warnings'],
        );
    }

    public function test_swedish_identity_preserved_when_saving_english_safety(): void
    {
        $this->saveSection(DppSectionKey::Identity, [
            'public_name' => 'Svenskt Produktnamn',
            'public_description' => 'Svensk beskrivning.',
        ], 'sv');

        $this->saveSection(DppSectionKey::Safety, [
            'warnings' => ['English warning'],
            'storage_instructions' => 'Store carefully.',
        ], 'en');

        $payload = $this->freshPayload();

        $this->assertArrayHasKey('sv', $payload['translations']);
        $this->assertArrayHasKey(DppSectionKey::Identity->value, $payload['translations']['sv']);
        $this->assertSame(
            'Svenskt Produktnamn',
            $payload['translations']['sv'][DppSectionKey::Identity->value]['public_name'],
        );

        $this->assertArrayHasKey('en', $payload['translations']);
        $this->assertArrayHasKey(DppSectionKey::Safety->value, $payload['translations']['en']);
        $this->assertSame(
            ['English warning'],
            $payload['translations']['en'][DppSectionKey::Safety->value]['warnings'],
        );
    }

    public function test_shared_data_unchanged_across_locale_saves(): void
    {
        $this->saveSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_email' => 'contact@example.com',
            'manufacturer_country' => 'SE',
        ], 'en');

        $this->saveSection(DppSectionKey::Identity, [
            'public_name' => 'Svenskt Namn',
        ], 'sv');

        $payload = $this->freshPayload();

        $this->assertArrayHasKey('data', $payload);
        $this->assertSame(
            'contact@example.com',
            $payload['data'][DppSectionKey::ManufacturerAndOperator->value]['manufacturer_email'],
        );
        $this->assertSame(
            'SE',
            $payload['data'][DppSectionKey::ManufacturerAndOperator->value]['manufacturer_country'],
        );
    }
}
