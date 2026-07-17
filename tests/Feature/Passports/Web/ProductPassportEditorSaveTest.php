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
use Tests\TestCase;

class ProductPassportEditorSaveTest extends TestCase
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
            'name' => 'Save Test Product '.fake()->unique()->word(),
            'slug' => 'save-test-'.fake()->unique()->slug(1),
            'slug_normalized' => 'save-test-'.fake()->unique()->slug(1),
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

    private function revisionFor(ProductPassport $passport): int
    {
        return $passport->fresh(['currentDraftVersion'])->currentDraftVersion->draft_revision;
    }

    // ── First save ────────────────────────────────────────────────

    public function test_first_section_saves(): void
    {
        $passport = $this->draft();

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'NordiChair Pro'],
                'expected_revision' => 1,
            ],
        );

        $response->assertOk();
        $json = $response->json();

        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('section', $json['data']);
        $this->assertSame(DppSectionKey::Identity->value, $json['data']['section']);
        $this->assertArrayHasKey('passport_uuid', $json['data']);
        $this->assertArrayHasKey('draft_revision', $json['data']);
        $this->assertSame(2, $json['data']['draft_revision']);
        $this->assertArrayHasKey('saved_at', $json['data']);
        $this->assertArrayHasKey('readiness', $json['data']);
        $this->assertArrayHasKey('score', $json['data']['readiness']);
        $this->assertArrayHasKey('status', $json['data']['readiness']);
        $this->assertArrayHasKey('blockers', $json['data']['readiness']);
        $this->assertArrayHasKey('warnings', $json['data']['readiness']);
    }

    public function test_second_section_saves_without_reload(): void
    {
        $passport = $this->draft();

        $r1 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'NordiTable Basic'],
                'expected_revision' => 1,
            ],
        );

        $r1->assertOk();
        $revAfterFirst = $r1->json()['data']['draft_revision'];

        $r2 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Safety->value,
            ]),
            [
                'section_payload' => ['warnings' => ['Warning 1']],
                'expected_revision' => $revAfterFirst,
            ],
        );

        $r2->assertOk();
        $json2 = $r2->json();

        $this->assertArrayHasKey('data', $json2);
        $this->assertSame(DppSectionKey::Safety->value, $json2['data']['section']);
        $this->assertSame($revAfterFirst + 1, $json2['data']['draft_revision']);
    }

    public function test_same_section_saves_repeatedly(): void
    {
        $passport = $this->draft();

        $r1 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::UsageAndCare->value,
            ]),
            [
                'section_payload' => ['usage_instructions' => 'Lyft endast i bottenplattan.'],
                'expected_revision' => 1,
            ],
        );

        $r1->assertOk();
        $rev1 = $r1->json()['data']['draft_revision'];

        $r2 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::UsageAndCare->value,
            ]),
            [
                'section_payload' => ['usage_instructions' => 'Använd endast inomhus.'],
                'expected_revision' => $rev1,
            ],
        );

        $r2->assertOk();
        $rev2 = $r2->json()['data']['draft_revision'];

        $r3 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::UsageAndCare->value,
            ]),
            [
                'section_payload' => ['usage_instructions' => 'Rengör med fuktig trasa.'],
                'expected_revision' => $rev2,
            ],
        );

        $r3->assertOk();
        $rev3 = $r3->json()['data']['draft_revision'];

        $this->assertGreaterThan($rev2, $rev3);
    }

    // ── Multi-section save workflow ───────────────────────────────

    public function test_full_multi_section_save_workflow(): void
    {
        $passport = $this->draft();

        $sections = [
            DppSectionKey::Identity->value => ['public_name' => 'NordiShelf Wall'],
            DppSectionKey::ManufacturerAndOperator->value => ['manufacturer_display_name' => 'Nordic Furniture AB'],
            DppSectionKey::Safety->value => ['age_restrictions' => 'Rekommenderas från 3 år'],
            DppSectionKey::UsageAndCare->value => ['usage_instructions' => 'Torka av med torr trasa.'],
            DppSectionKey::SupportAndContact->value => ['support_phone' => '+46812345678'],
        ];

        $revision = 1;
        $savedData = [];

        foreach ($sections as $sectionKey => $payload) {
            $response = $this->putJson(
                route('catalog.products.passport.sections.update', [
                    'product' => $this->product->uuid,
                    'section' => $sectionKey,
                ]),
                [
                    'section_payload' => $payload,
                    'expected_revision' => $revision,
                ],
            );

            $response->assertOk();
            $json = $response->json();

            $this->assertArrayHasKey('data', $json);
            $this->assertSame($sectionKey, $json['data']['section']);
            $this->assertGreaterThan($revision, $json['data']['draft_revision']);

            $revision = $json['data']['draft_revision'];
            $savedData[$sectionKey] = $payload;
        }

        $this->assertGreaterThan(1, $revision);
    }

    // ── Data integrity & normalization ────────────────────────────

    public function test_payload_values_are_normalized(): void
    {
        $passport = $this->draft();

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::ManufacturerAndOperator->value,
            ]),
            [
                'section_payload' => [
                    'manufacturer_email' => '  Kontakt@NordicMobel.Se  ',
                    'manufacturer_country' => 'SE',
                ],
                'expected_revision' => 1,
            ],
        );

        $response->assertOk();
        $json = $response->json();

        $payload = $json['data']['payload'];
        $sectionData = $payload['data'][DppSectionKey::ManufacturerAndOperator->value] ?? [];

        $this->assertSame('kontakt@nordicmobel.se', $sectionData['manufacturer_email']);
        $this->assertSame('SE', $sectionData['manufacturer_country']);
    }

    public function test_no_data_loss_between_section_saves(): void
    {
        $passport = $this->draft();

        $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'NordiLamp Golv'],
                'expected_revision' => 1,
            ],
        )->assertOk();

        $r2 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Safety->value,
            ]),
            [
                'section_payload' => ['age_restrictions' => 'Ej lämplig för barn under 3 år'],
                'expected_revision' => 2,
            ],
        );

        $r2->assertOk();
        $json = $r2->json();

        $locale = config('passports.default_language', 'sv');
        $payload = $json['data']['payload'];

        $this->assertArrayHasKey('translations', $payload);
        $this->assertArrayHasKey($locale, $payload['translations']);
        $this->assertArrayHasKey(DppSectionKey::Identity->value, $payload['translations'][$locale]);
        $this->assertSame('NordiLamp Golv', $payload['translations'][$locale][DppSectionKey::Identity->value]['public_name']);
    }

    // ── Response structure ────────────────────────────────────────

    public function test_success_response_contains_section(): void
    {
        $this->draft();

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'NordiDesk Sit/Stand'],
                'expected_revision' => 1,
            ],
        );

        $response->assertOk();
        $json = $response->json();

        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('section', $json['data']);
    }

    public function test_success_response_contains_new_revision(): void
    {
        $passport = $this->draft();

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'NordiDesk Sit/Stand'],
                'expected_revision' => 1,
            ],
        );

        $response->assertOk();
        $json = $response->json();

        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('draft_revision', $json['data']);
        $this->assertGreaterThan(1, $json['data']['draft_revision']);
    }

    public function test_success_response_contains_readiness_summary(): void
    {
        $this->draft();

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'NordiDesk Sit/Stand'],
                'expected_revision' => 1,
            ],
        );

        $response->assertOk();
        $json = $response->json();

        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('readiness', $json['data']);
        $this->assertIsNumeric($json['data']['readiness']['score']);
        $this->assertIsString($json['data']['readiness']['status']);
        $this->assertIsInt($json['data']['readiness']['blockers']);
        $this->assertIsInt($json['data']['readiness']['warnings']);
    }
}
