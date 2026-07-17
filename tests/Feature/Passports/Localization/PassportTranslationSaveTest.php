<?php

namespace Tests\Feature\Passports\Localization;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PassportTranslationSaveTest extends TestCase
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
            'name' => 'Translation Save Product '.fake()->unique()->word(),
            'slug' => 'translation-save-'.fake()->unique()->slug(1),
            'slug_normalized' => 'translation-save-'.fake()->unique()->slug(1),
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

    private function lastRevision(): int
    {
        return $this->passport->fresh(['currentDraftVersion'])->currentDraftVersion->draft_revision;
    }

    public function test_save_english_identity(): void
    {
        $rev = $this->lastRevision();

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'English Product Name'],
                'expected_revision' => $rev,
                'locale' => 'en',
            ],
        );

        $response->assertOk();
        $json = $response->json();

        $this->assertArrayHasKey('data', $json);
        $payload = $json['data']['payload'];
        $this->assertArrayHasKey('translations', $payload);
        $this->assertArrayHasKey('en', $payload['translations']);
        $this->assertSame(
            'English Product Name',
            $payload['translations']['en'][DppSectionKey::Identity->value]['public_name'],
        );
    }

    public function test_save_swedish_identity_via_locale_param(): void
    {
        $rev = $this->lastRevision();

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'Svenskt Produktnamn'],
                'expected_revision' => $rev,
                'locale' => 'sv',
            ],
        );

        $response->assertOk();
        $json = $response->json();
        $payload = $json['data']['payload'];

        $this->assertArrayHasKey('sv', $payload['translations']);
        $this->assertSame(
            'Svenskt Produktnamn',
            $payload['translations']['sv'][DppSectionKey::Identity->value]['public_name'],
        );
    }

    public function test_reload_draft_both_locales_preserved(): void
    {
        $rev1 = $this->lastRevision();

        $r1 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => [
                    'public_name' => 'English Name',
                    'public_description' => 'English description.',
                ],
                'expected_revision' => $rev1,
                'locale' => 'en',
            ],
        );
        $r1->assertOk();
        $rev2 = $r1->json()['data']['draft_revision'];

        $r2 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => [
                    'public_name' => 'Svenskt Namn',
                    'public_description' => 'Svensk beskrivning.',
                ],
                'expected_revision' => $rev2,
                'locale' => 'sv',
            ],
        );
        $r2->assertOk();

        $this->passport->refresh();
        $payload = $this->passport->currentDraftVersion->payload;

        $this->assertArrayHasKey('en', $payload['translations']);
        $this->assertArrayHasKey('sv', $payload['translations']);
        $this->assertSame(
            'English Name',
            $payload['translations']['en'][DppSectionKey::Identity->value]['public_name'],
        );
        $this->assertSame(
            'Svenskt Namn',
            $payload['translations']['sv'][DppSectionKey::Identity->value]['public_name'],
        );
    }

    public function test_shared_fields_persist_across_locale_saves(): void
    {
        $rev1 = $this->lastRevision();

        $r1 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::ManufacturerAndOperator->value,
            ]),
            [
                'section_payload' => ['manufacturer_email' => 'contact@example.com'],
                'expected_revision' => $rev1,
                'locale' => 'en',
            ],
        );
        $r1->assertOk();
        $rev2 = $r1->json()['data']['draft_revision'];

        $r2 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'Svenskt Namn'],
                'expected_revision' => $rev2,
                'locale' => 'sv',
            ],
        );
        $r2->assertOk();

        $payload = $r2->json()['data']['payload'];
        $this->assertSame(
            'contact@example.com',
            $payload['data'][DppSectionKey::ManufacturerAndOperator->value]['manufacturer_email'],
        );
    }

    public function test_repeat_saves_work(): void
    {
        $rev1 = $this->lastRevision();

        $r1 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'First English Name'],
                'expected_revision' => $rev1,
                'locale' => 'en',
            ],
        );
        $r1->assertOk();
        $rev2 = $r1->json()['data']['draft_revision'];

        $r2 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'Updated English Name'],
                'expected_revision' => $rev2,
                'locale' => 'en',
            ],
        );
        $r2->assertOk();

        $payload = $r2->json()['data']['payload'];
        $this->assertSame(
            'Updated English Name',
            $payload['translations']['en'][DppSectionKey::Identity->value]['public_name'],
        );
    }

    public function test_one_locale_does_not_overwrite_another(): void
    {
        $rev1 = $this->lastRevision();

        $r1 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'English Name'],
                'expected_revision' => $rev1,
                'locale' => 'en',
            ],
        );
        $r1->assertOk();
        $rev2 = $r1->json()['data']['draft_revision'];

        $r2 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'Svenskt Namn'],
                'expected_revision' => $rev2,
                'locale' => 'sv',
            ],
        );
        $r2->assertOk();

        $payload = $r2->json()['data']['payload'];
        $this->assertSame(
            'English Name',
            $payload['translations']['en'][DppSectionKey::Identity->value]['public_name'],
        );
        $this->assertSame(
            'Svenskt Namn',
            $payload['translations']['sv'][DppSectionKey::Identity->value]['public_name'],
        );
    }

    public function test_one_section_does_not_overwrite_another(): void
    {
        $rev1 = $this->lastRevision();

        $r1 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'English Name'],
                'expected_revision' => $rev1,
                'locale' => 'en',
            ],
        );
        $r1->assertOk();
        $rev2 = $r1->json()['data']['draft_revision'];

        $r2 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Safety->value,
            ]),
            [
                'section_payload' => ['age_restrictions' => '18+'],
                'expected_revision' => $rev2,
                'locale' => 'en',
            ],
        );
        $r2->assertOk();

        $payload = $r2->json()['data']['payload'];
        $this->assertSame(
            'English Name',
            $payload['translations']['en'][DppSectionKey::Identity->value]['public_name'],
        );
        $this->assertSame(
            '18+',
            $payload['translations']['en'][DppSectionKey::Safety->value]['age_restrictions'],
        );
    }

    public function test_0_and_false_values_preserved(): void
    {
        $rev = $this->lastRevision();

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::RepairAndSpareParts->value,
            ]),
            [
                'section_payload' => [
                    'repairable' => true,
                    'spare_parts_available' => false,
                    'repair_instructions' => 'Repair guide.',
                ],
                'expected_revision' => $rev,
                'locale' => 'en',
            ],
        );

        $response->assertOk();
        $payload = $response->json()['data']['payload'];

        $this->assertArrayHasKey(DppSectionKey::RepairAndSpareParts->value, $payload['data']);
        $this->assertTrue($payload['data'][DppSectionKey::RepairAndSpareParts->value]['repairable']);
        $this->assertFalse($payload['data'][DppSectionKey::RepairAndSpareParts->value]['spare_parts_available']);
    }

    public function test_revision_increments_correctly(): void
    {
        $this->assertSame(1, $this->lastRevision());

        $r1 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'English Name'],
                'expected_revision' => 1,
                'locale' => 'en',
            ],
        );
        $r1->assertOk();
        $this->assertSame(2, $r1->json()['data']['draft_revision']);

        $r2 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'Svenskt Namn'],
                'expected_revision' => 2,
                'locale' => 'sv',
            ],
        );
        $r2->assertOk();
        $this->assertSame(3, $r2->json()['data']['draft_revision']);
    }
}
