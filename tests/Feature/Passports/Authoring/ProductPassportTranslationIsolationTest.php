<?php

namespace Tests\Feature\Passports\Authoring;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\DppPayloadNormalizer;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPassportTranslationIsolationTest extends TestCase
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

        $this->product = $this->createProduct();
        $this->passport = $this->createDraftPassport($this->product);

        $this->passport->setAttribute('default_language', 'sv');
        $this->passport->save();
    }

    private function createProduct(): Product
    {
        $product = new Product;
        $product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Test Product '.fake()->unique()->word(),
            'slug' => 'test-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'test-product-'.fake()->unique()->slug(1),
            'status' => ProductStatus::Active,
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        return $product->refresh();
    }

    private function createDraftPassport(Product $product): ProductPassport
    {
        $action = app(CreateProductPassportDraftAction::class);

        return $action->handle($this->actor, $this->company, $product);
    }

    public function test_english_and_swedish_environmental_claims_isolated(): void
    {
        $draft = $this->passport->currentDraftVersion;
        $payload = $draft->payload;

        $payload['translations']['en']['environmental_information']['environmental_claims'] = ['English claim'];
        $payload['translations']['sv']['environmental_information']['environmental_claims'] = ['Swedish claim'];

        $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $draft->draft_revision + 1);
        $draft->save();

        $this->passport->refresh();
        $reloadedPayload = $this->passport->currentDraftVersion->payload;

        $this->assertSame(
            ['English claim'],
            $reloadedPayload['translations']['en']['environmental_information']['environmental_claims'],
        );
        $this->assertSame(
            ['Swedish claim'],
            $reloadedPayload['translations']['sv']['environmental_information']['environmental_claims'],
        );
    }

    public function test_identity_translation_isolation(): void
    {
        $draft = $this->passport->currentDraftVersion;
        $payload = $draft->payload;

        $payload['translations']['en']['identity']['public_name'] = 'English Name';
        $payload['translations']['sv']['identity']['public_name'] = 'Swedish Name';

        $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $draft->draft_revision + 1);
        $draft->save();

        $this->passport->refresh();
        $reloadedPayload = $this->passport->currentDraftVersion->payload;

        $this->assertSame(
            'English Name',
            $reloadedPayload['translations']['en']['identity']['public_name'],
        );
        $this->assertSame(
            'Swedish Name',
            $reloadedPayload['translations']['sv']['identity']['public_name'],
        );
    }

    public function test_safety_translation_isolation(): void
    {
        $draft = $this->passport->currentDraftVersion;
        $payload = $draft->payload;

        $payload['translations']['en']['safety']['warnings'] = ['English warning'];
        $payload['translations']['sv']['safety']['warnings'] = ['Swedish warning'];

        $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $draft->draft_revision + 1);
        $draft->save();

        $this->passport->refresh();
        $reloadedPayload = $this->passport->currentDraftVersion->payload;

        $this->assertSame(
            ['English warning'],
            $reloadedPayload['translations']['en']['safety']['warnings'],
        );
        $this->assertSame(
            ['Swedish warning'],
            $reloadedPayload['translations']['sv']['safety']['warnings'],
        );
    }

    public function test_non_translatable_fields_stay_in_data(): void
    {
        $draft = $this->passport->currentDraftVersion;
        $payload = $draft->payload;

        $payload['data']['manufacturer_and_operator']['manufacturer_country'] = 'DE';

        $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $draft->draft_revision + 1);
        $draft->save();

        $this->passport->refresh();
        $reloadedPayload = $this->passport->currentDraftVersion->payload;

        $this->assertSame(
            'DE',
            $reloadedPayload['data']['manufacturer_and_operator']['manufacturer_country'],
        );

        $this->assertArrayNotHasKey(
            'manufacturer_and_operator',
            $reloadedPayload['translations']['en'] ?? [],
        );
        $this->assertArrayNotHasKey(
            'manufacturer_and_operator',
            $reloadedPayload['translations']['sv'] ?? [],
        );
    }
}
