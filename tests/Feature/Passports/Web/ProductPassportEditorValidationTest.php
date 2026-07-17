<?php

namespace Tests\Feature\Passports\Web;

use App\Actions\Passports\CreateProductPassportDraftAction;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProductPassportEditorValidationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

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

        $this->product = new Product;
        $this->product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Validation Test Product '.fake()->unique()->word(),
            'slug' => 'validation-test-'.fake()->unique()->slug(1),
            'slug_normalized' => 'validation-test-'.fake()->unique()->slug(1),
            'status' => ProductStatus::Active,
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();
        $this->product->refresh();
    }

    private function draft(): ProductPassport
    {
        return app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );
    }

    private function saveSection(string $section, array $payload, int $revision = 1): TestResponse
    {
        return $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => $section,
            ]),
            [
                'section_payload' => $payload,
                'expected_revision' => $revision,
            ],
        );
    }

    // ── Email validation ──────────────────────────────────────────

    public function test_valid_manufacturer_email_accepted(): void
    {
        $this->draft();

        $this->saveSection(DppSectionKey::ManufacturerAndOperator->value, [
            'manufacturer_email' => 'test@example.com',
        ])->assertOk();
    }

    public function test_invalid_manufacturer_email_rejected(): void
    {
        $this->draft();

        $response = $this->saveSection(DppSectionKey::ManufacturerAndOperator->value, [
            'manufacturer_email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('manufacturer_email', $json['errors']);
    }

    public function test_invalid_responsible_operator_email_rejected(): void
    {
        $this->draft();

        $response = $this->saveSection(DppSectionKey::ManufacturerAndOperator->value, [
            'responsible_operator_email' => 'user@',
        ]);

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('responsible_operator_email', $json['errors']);
    }

    public function test_invalid_support_email_rejected(): void
    {
        $this->draft();

        $response = $this->saveSection(DppSectionKey::SupportAndContact->value, [
            'support_email' => '@example.com',
        ]);

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('support_email', $json['errors']);
    }

    public function test_email_with_spaces_rejected(): void
    {
        $this->draft();

        $response = $this->saveSection(DppSectionKey::ManufacturerAndOperator->value, [
            'manufacturer_email' => 'user example.com',
        ]);

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('manufacturer_email', $json['errors']);
    }

    // ── URL validation ────────────────────────────────────────────

    public function test_valid_https_url_accepted(): void
    {
        $this->draft();

        $this->saveSection(DppSectionKey::ManufacturerAndOperator->value, [
            'manufacturer_website' => 'https://example.com',
        ])->assertOk();
    }

    public function test_valid_http_url_accepted(): void
    {
        $this->draft();

        $this->saveSection(DppSectionKey::ManufacturerAndOperator->value, [
            'manufacturer_website' => 'http://example.test',
        ])->assertOk();
    }

    public function test_invalid_url_rejected(): void
    {
        $this->draft();

        $response = $this->saveSection(DppSectionKey::ManufacturerAndOperator->value, [
            'manufacturer_website' => 'not-a-url',
        ]);

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('manufacturer_website', $json['errors']);
    }

    public function test_javascript_url_rejected(): void
    {
        $this->draft();

        $response = $this->saveSection(DppSectionKey::SupportAndContact->value, [
            'support_url' => 'javascript:alert(1)',
        ]);

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('support_url', $json['errors']);
    }

    public function test_file_url_rejected(): void
    {
        $this->draft();

        $response = $this->saveSection(DppSectionKey::RepairAndSpareParts->value, [
            'spare_parts_url' => 'file:///C:/secret',
        ]);

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('spare_parts_url', $json['errors']);
    }

    // ── Date validation ───────────────────────────────────────────

    public function test_valid_date_accepted(): void
    {
        $this->draft();

        $this->saveSection(DppSectionKey::OriginAndTraceability->value, [
            'production_date' => '2024-01-15',
        ])->assertOk();
    }

    public function test_invalid_date_rejected(): void
    {
        $this->draft();

        $response = $this->saveSection(DppSectionKey::OriginAndTraceability->value, [
            'production_date' => 'not-a-date',
        ]);

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('production_date', $json['errors']);
    }

    // ── Percentage validation ─────────────────────────────────────

    public function test_percentage_below_zero_rejected(): void
    {
        $this->draft();

        $response = $this->saveSection(DppSectionKey::EnvironmentalInformation->value, [
            'recycled_content_percentage' => -5,
        ]);

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('recycled_content_percentage', $json['errors']);
    }

    public function test_percentage_above_100_rejected(): void
    {
        $this->draft();

        $response = $this->saveSection(DppSectionKey::EnvironmentalInformation->value, [
            'recycled_content_percentage' => 150,
        ]);

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('recycled_content_percentage', $json['errors']);
    }

    public function test_percentage_within_range_accepted(): void
    {
        $this->draft();

        $this->saveSection(DppSectionKey::EnvironmentalInformation->value, [
            'recycled_content_percentage' => 75.5,
        ])->assertOk();
    }

    // ── Country code validation ───────────────────────────────────

    public function test_valid_country_code_accepted(): void
    {
        $this->draft();

        $this->saveSection(DppSectionKey::ManufacturerAndOperator->value, [
            'manufacturer_country' => 'SE',
        ])->assertOk();
    }

    public function test_invalid_country_code_rejected(): void
    {
        $this->draft();

        $response = $this->saveSection(DppSectionKey::ManufacturerAndOperator->value, [
            'manufacturer_country' => 'SWE',
        ]);

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('manufacturer_country', $json['errors']);
    }

    public function test_lowercase_country_code_rejected(): void
    {
        $this->draft();

        $response = $this->saveSection(DppSectionKey::ManufacturerAndOperator->value, [
            'manufacturer_country' => 'se',
        ]);

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('manufacturer_country', $json['errors']);
    }

    // ── Unknown field validation ──────────────────────────────────

    public function test_unknown_field_rejected(): void
    {
        $this->draft();

        $response = $this->saveSection(DppSectionKey::Identity->value, [
            'nonexistent_field' => 'value',
        ]);

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
    }

    // ── Boolean validation ────────────────────────────────────────

    public function test_non_boolean_value_rejected(): void
    {
        $this->draft();

        $response = $this->saveSection(DppSectionKey::RepairAndSpareParts->value, [
            'repairable' => 'yes',
        ]);

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('repairable', $json['errors']);
    }

    public function test_boolean_true_accepted(): void
    {
        $this->draft();

        $this->saveSection(DppSectionKey::RepairAndSpareParts->value, [
            'repairable' => true,
            'spare_parts_available' => false,
        ])->assertOk();
    }

    // ── Required fields ───────────────────────────────────────────

    public function test_missing_section_payload_rejected(): void
    {
        $this->draft();

        $response = $this->withHeaders(['Accept' => 'application/json'])->put(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'expected_revision' => 1,
            ],
        );

        $this->assertContains($response->status(), [302, 422]);
    }

    public function test_wrong_tenant_returns_404(): void
    {
        $this->draft();

        $otherCompany = Company::factory()->create(['status' => CompanyStatus::Active]);
        $otherUser = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'company_id' => $otherCompany->getKey(),
            'user_id' => $otherUser->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $this->actingAs($otherUser);
        app(CurrentCompany::class)->set($otherCompany);

        $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'Test'],
                'expected_revision' => 1,
            ],
        )->assertNotFound();
    }
}
