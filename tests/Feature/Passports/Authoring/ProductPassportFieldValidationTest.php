<?php

namespace Tests\Feature\Passports\Authoring;

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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductPassportFieldValidationTest extends TestCase
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

    public function test_invalid_email_rejected(): void
    {
        $action = app(UpdateProductPassportSectionAction::class);

        try {
            $action->handle(
                $this->actor,
                $this->company,
                $this->product,
                $this->passport,
                DppSectionKey::ManufacturerAndOperator->value,
                ['manufacturer_email' => 'not-an-email'],
                1,
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('manufacturer_email', $e->errors());
        }
    }

    public function test_valid_email_accepted(): void
    {
        $action = app(UpdateProductPassportSectionAction::class);

        $result = $action->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->passport,
            DppSectionKey::ManufacturerAndOperator->value,
            ['manufacturer_email' => 'valid@test.com'],
            1,
        );

        $payload = $result->currentDraftVersion->payload;

        $this->assertSame(
            'valid@test.com',
            $payload['data'][DppSectionKey::ManufacturerAndOperator->value]['manufacturer_email'],
        );
    }

    public function test_javascript_url_rejected(): void
    {
        $action = app(UpdateProductPassportSectionAction::class);

        try {
            $action->handle(
                $this->actor,
                $this->company,
                $this->product,
                $this->passport,
                DppSectionKey::ManufacturerAndOperator->value,
                ['manufacturer_website' => 'javascript:alert(1)'],
                1,
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('manufacturer_website', $e->errors());
        }
    }

    public function test_country_code_invalid_rejected(): void
    {
        $action = app(UpdateProductPassportSectionAction::class);

        try {
            $action->handle(
                $this->actor,
                $this->company,
                $this->product,
                $this->passport,
                DppSectionKey::ManufacturerAndOperator->value,
                ['manufacturer_country' => 'SWE'],
                1,
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('manufacturer_country', $e->errors());
        }
    }

    public function test_country_code_valid_accepted(): void
    {
        $action = app(UpdateProductPassportSectionAction::class);

        $result = $action->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->passport,
            DppSectionKey::ManufacturerAndOperator->value,
            ['manufacturer_country' => 'SE'],
            1,
        );

        $payload = $result->currentDraftVersion->payload;

        $this->assertSame(
            'SE',
            $payload['data'][DppSectionKey::ManufacturerAndOperator->value]['manufacturer_country'],
        );
    }

    public function test_percentage_above_100_rejected(): void
    {
        $action = app(UpdateProductPassportSectionAction::class);

        try {
            $action->handle(
                $this->actor,
                $this->company,
                $this->product,
                $this->passport,
                DppSectionKey::EnvironmentalInformation->value,
                ['recycled_content_percentage' => 101],
                1,
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recycled_content_percentage', $e->errors());
        }
    }

    public function test_percentage_negative_rejected(): void
    {
        $action = app(UpdateProductPassportSectionAction::class);

        try {
            $action->handle(
                $this->actor,
                $this->company,
                $this->product,
                $this->passport,
                DppSectionKey::EnvironmentalInformation->value,
                ['recycled_content_percentage' => -1],
                1,
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recycled_content_percentage', $e->errors());
        }
    }

    public function test_boolean_field_rejects_array(): void
    {
        $action = app(UpdateProductPassportSectionAction::class);

        try {
            $action->handle(
                $this->actor,
                $this->company,
                $this->product,
                $this->passport,
                DppSectionKey::RepairAndSpareParts->value,
                ['repairable' => ['yes']],
                1,
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('repairable', $e->errors());
        }
    }

    public function test_unknown_field_rejected(): void
    {
        $action = app(UpdateProductPassportSectionAction::class);

        try {
            $action->handle(
                $this->actor,
                $this->company,
                $this->product,
                $this->passport,
                DppSectionKey::Identity->value,
                ['invented_field' => 'test'],
                1,
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('fields', $errors);
            $this->assertStringContainsString(
                "Unknown field 'invented_field'",
                $errors['fields'][0],
            );
        }
    }

    public function test_wrong_section_field_rejected(): void
    {
        $action = app(UpdateProductPassportSectionAction::class);

        try {
            $action->handle(
                $this->actor,
                $this->company,
                $this->product,
                $this->passport,
                DppSectionKey::Safety->value,
                ['public_name' => 'test'],
                1,
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('fields', $errors);
            $this->assertStringContainsString(
                "Unknown field 'public_name'",
                $errors['fields'][0],
            );
        }
    }
}
