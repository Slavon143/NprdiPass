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
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class PassportTranslationValidationTest extends TestCase
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

        $this->product = new Product;
        $this->product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Validation Product '.fake()->unique()->word(),
            'slug' => 'validation-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'validation-product-'.fake()->unique()->slug(1),
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
    }

    private function revision(): int
    {
        return $this->passport->fresh(['currentDraftVersion'])->currentDraftVersion->draft_revision;
    }

    public function test_invalid_locale_rejected(): void
    {
        $action = app(UpdateProductPassportSectionAction::class);

        try {
            $action->handle(
                $this->actor,
                $this->company,
                $this->product,
                $this->passport,
                DppSectionKey::Identity->value,
                ['public_name' => 'Test'],
                $this->revision(),
                'invalid_locale',
            );

            $this->fail('Expected ConflictHttpException was not thrown.');
        } catch (ConflictHttpException $e) {
            $this->assertStringContainsString('invalid_locale', $e->getMessage());
        }
    }

    public function test_disabled_locale_save_rejected(): void
    {
        $this->passport->setAttribute('enabled_languages', ['sv']);
        $this->passport->save();

        $action = app(UpdateProductPassportSectionAction::class);
        $rev = $this->revision();

        try {
            $action->handle(
                $this->actor,
                $this->company,
                $this->product,
                $this->passport,
                DppSectionKey::Identity->value,
                ['public_name' => 'English Name'],
                $rev,
                'en',
            );
            $this->fail('Expected ConflictHttpException was not thrown.');
        } catch (ConflictHttpException $e) {
            $this->assertStringContainsString('en', $e->getMessage());
        }
    }

    public function test_unknown_field_rejected(): void
    {
        $action = app(UpdateProductPassportSectionAction::class);
        $rev = $this->revision();

        try {
            $action->handle(
                $this->actor,
                $this->company,
                $this->product,
                $this->passport,
                DppSectionKey::Identity->value,
                ['invented_field' => 'test'],
                $rev,
                'en',
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

    public function test_translatable_field_in_data_rejected(): void
    {
        $action = app(UpdateProductPassportSectionAction::class);
        $rev = $this->revision();

        try {
            $action->handle(
                $this->actor,
                $this->company,
                $this->product,
                $this->passport,
                DppSectionKey::Safety->value,
                ['public_name' => 'test'],
                $rev,
                'en',
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

    public function test_stale_revision_returns_409(): void
    {
        $rev = $this->revision();

        $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'First Save'],
                'expected_revision' => $rev,
                'locale' => 'en',
            ],
        )->assertOk();

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'Stale Save'],
                'expected_revision' => $rev,
                'locale' => 'en',
            ],
        );

        $response->assertStatus(409);
    }

    public function test_invalid_email_rejected(): void
    {
        $action = app(UpdateProductPassportSectionAction::class);
        $rev = $this->revision();

        try {
            $action->handle(
                $this->actor,
                $this->company,
                $this->product,
                $this->passport,
                DppSectionKey::ManufacturerAndOperator->value,
                ['manufacturer_email' => 'not-an-email'],
                $rev,
                'en',
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('manufacturer_email', $e->errors());
        }
    }

    public function test_invalid_url_rejected(): void
    {
        $action = app(UpdateProductPassportSectionAction::class);
        $rev = $this->revision();

        try {
            $action->handle(
                $this->actor,
                $this->company,
                $this->product,
                $this->passport,
                DppSectionKey::ManufacturerAndOperator->value,
                ['manufacturer_website' => 'javascript:alert(1)'],
                $rev,
                'en',
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('manufacturer_website', $e->errors());
        }
    }
}
