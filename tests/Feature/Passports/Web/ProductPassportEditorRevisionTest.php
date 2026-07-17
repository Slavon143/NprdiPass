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

class ProductPassportEditorRevisionTest extends TestCase
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
            'name' => 'Revision Test Product '.fake()->unique()->word(),
            'slug' => 'revision-test-'.fake()->unique()->slug(1),
            'slug_normalized' => 'revision-test-'.fake()->unique()->slug(1),
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

    // ── Revision increments ───────────────────────────────────────

    public function test_revision_increments_after_save(): void
    {
        $passport = $this->draft();

        $this->assertSame(1, $this->revisionFor($passport));

        $r1 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'NordiStol Ek'],
                'expected_revision' => 1,
            ],
        );

        $r1->assertOk();
        $this->assertSame(2, $r1->json()['data']['draft_revision']);
        $this->assertSame(2, $this->revisionFor($passport));
    }

    public function test_revision_increments_with_each_save(): void
    {
        $passport = $this->draft();

        $r1 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'NordiByrå'],
                'expected_revision' => 1,
            ],
        )->assertOk();

        $revAfter1 = $r1->json()['data']['draft_revision'];

        $r2 = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Safety->value,
            ]),
            [
                'section_payload' => ['age_restrictions' => 'Rekommenderas från 6 år'],
                'expected_revision' => $revAfter1,
            ],
        )->assertOk();

        $revAfter2 = $r2->json()['data']['draft_revision'];

        $this->assertSame(3, $revAfter2);
        $this->assertSame(3, $this->revisionFor($passport));
    }

    public function test_revision_response_matches_actual_revision(): void
    {
        $passport = $this->draft();

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::UsageAndCare->value,
            ]),
            [
                'section_payload' => ['usage_instructions' => 'Montera enligt bifogad manual.'],
                'expected_revision' => 1,
            ],
        );

        $response->assertOk();
        $json = $response->json();

        $this->assertSame(
            $this->revisionFor($passport),
            $json['data']['draft_revision'],
        );
    }

    // ── 409 Revision conflict ─────────────────────────────────────

    public function test_409_on_stale_revision(): void
    {
        $passport = $this->draft();

        $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::UsageAndCare->value,
            ]),
            [
                'section_payload' => ['usage_instructions' => 'Skruva fast väggfästet först.'],
                'expected_revision' => 1,
            ],
        )->assertOk();

        $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::UsageAndCare->value,
            ]),
            [
                'section_payload' => ['usage_instructions' => 'Gammal data — förväntas avvisas.'],
                'expected_revision' => 1,
            ],
        )->assertStatus(409);
    }

    public function test_409_response_has_message(): void
    {
        $passport = $this->draft();

        $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::UsageAndCare->value,
            ]),
            [
                'section_payload' => ['usage_instructions' => 'Undvik direkt solljus.'],
                'expected_revision' => 1,
            ],
        )->assertOk();

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::UsageAndCare->value,
            ]),
            [
                'section_payload' => ['usage_instructions' => 'För gammal revision.'],
                'expected_revision' => 1,
            ],
        );

        $response->assertStatus(409);
        $this->assertArrayHasKey('message', $response->json());
    }

    public function test_data_not_mutated_on_409(): void
    {
        $passport = $this->draft();

        $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::UsageAndCare->value,
            ]),
            [
                'section_payload' => ['usage_instructions' => 'Förvara i rumstemperatur.'],
                'expected_revision' => 1,
            ],
        )->assertOk();

        $payloadBeforeConflict = $passport->fresh(['currentDraftVersion'])
            ->currentDraftVersion
            ->payload;

        $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::UsageAndCare->value,
            ]),
            [
                'section_payload' => ['usage_instructions' => 'Data med gammal revision.'],
                'expected_revision' => 1,
            ],
        )->assertStatus(409);

        $payloadAfterConflict = $passport->fresh(['currentDraftVersion'])
            ->currentDraftVersion
            ->payload;

        $this->assertSame(
            $payloadBeforeConflict,
            $payloadAfterConflict,
        );
    }

    // ── HTTP error handling ───────────────────────────────────────

    public function test_422_returns_field_errors(): void
    {
        $this->draft();

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::ManufacturerAndOperator->value,
            ]),
            [
                'section_payload' => ['manufacturer_email' => 'bad'],
                'expected_revision' => 1,
            ],
        );

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('manufacturer_email', $json['errors']);
        $this->assertIsArray($json['errors']['manufacturer_email']);
        $this->assertNotEmpty($json['errors']['manufacturer_email']);
    }

    public function test_422_message_key(): void
    {
        $this->draft();

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::ManufacturerAndOperator->value,
            ]),
            [
                'section_payload' => ['manufacturer_email' => 'bad'],
                'expected_revision' => 1,
            ],
        );

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('message', $json);
        $this->assertSame('Validation failed.', $json['message']);
    }

    public function test_unsupported_section_rejected(): void
    {
        $this->draft();

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => 'nonexistent_section',
            ]),
            [
                'section_payload' => ['field' => 'value'],
                'expected_revision' => 1,
            ],
        );

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
    }

    public function test_viewer_sees_read_only(): void
    {
        $this->draft();

        $membership = $this->actor->memberships()
            ->where('company_id', $this->company->getKey())
            ->first();
        $membership->forceFill(['role' => CompanyRole::Viewer])->save();

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'Ska ej sparas'],
                'expected_revision' => 1,
            ],
        );

        $response->assertStatus(403);
    }

    public function test_wrong_tenant_cannot_save(): void
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
                'section_payload' => ['public_name' => 'NordiStol Ek'],
                'expected_revision' => 1,
            ],
        )->assertNotFound();
    }

    public function test_missing_passport_returns_404(): void
    {
        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::Identity->value,
            ]),
            [
                'section_payload' => ['public_name' => 'NordiStol Ek'],
                'expected_revision' => 1,
            ],
        );

        $response->assertStatus(404);
    }
}
