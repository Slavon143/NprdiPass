<?php

namespace Tests\Feature\Passports\Web;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Enums\Passports\DppSectionKey;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class DppWebTest extends TestCase
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
            'name' => 'Web Test Product '.fake()->unique()->word(),
            'slug' => 'web-test-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'web-test-product-'.fake()->unique()->slug(1),
            'status' => ProductStatus::Active,
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();
        $this->product->refresh();
    }

    private function createDraftPassport(Product $product): ProductPassport
    {
        return app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $product,
        );
    }

    private function createProductForArchivedTest(): Product
    {
        $product = new Product;
        $product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Archived Product '.fake()->unique()->word(),
            'slug' => 'archived-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'archived-product-'.fake()->unique()->slug(1),
            'status' => ProductStatus::Archived,
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        return $product->refresh();
    }

    public function test_create_passport_page_loads(): void
    {
        $passport = $this->createDraftPassport($this->product);

        $this->get(route('catalog.products.passport.show', ['product' => $this->product->uuid]))
            ->assertOk();
    }

    public function test_create_passport_submission_creates_passport(): void
    {
        $this->post(route('catalog.products.passport.store', ['product' => $this->product->uuid]))
            ->assertRedirect(route('catalog.products.passport.edit', ['product' => $this->product->uuid]))
            ->assertSessionHas('success', 'Passport draft created.');

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        $this->assertNotNull($passport);
    }

    public function test_editor_page_loads(): void
    {
        $this->createDraftPassport($this->product);

        $this->get(route('catalog.products.passport.edit', ['product' => $this->product->uuid]))
            ->assertOk();
    }

    public function test_authenticated_draft_preview_is_rendered_without_publishing(): void
    {
        $passport = $this->createDraftPassport($this->product);

        $response = $this->get(route('catalog.products.passport.preview', ['product' => $this->product->uuid]));

        $response
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Draft preview — not public')
            ->assertSee($this->product->name);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->get(route('public.passports.show', ['publicId' => $passport->public_id]))
            ->assertNotFound();
    }

    public function test_draft_preview_is_company_scoped(): void
    {
        $foreignCompany = Company::factory()->create(['status' => CompanyStatus::Active]);
        $foreignProduct = new Product;
        $foreignProduct->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $foreignCompany->getKey(),
            'name' => 'Foreign preview product',
            'slug' => 'foreign-preview-product',
            'slug_normalized' => 'foreign-preview-product',
            'status' => ProductStatus::Active,
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->get(route('catalog.products.passport.preview', ['product' => $foreignProduct->uuid]))
            ->assertNotFound();
    }

    public function test_owner_can_access_editor(): void
    {
        $this->createDraftPassport($this->product);

        $this->get(route('catalog.products.passport.edit', ['product' => $this->product->uuid]))
            ->assertOk();
    }

    public function test_admin_can_access_editor(): void
    {
        $membership = $this->actor->memberships()
            ->where('company_id', $this->company->getKey())
            ->first();
        $membership->forceFill(['role' => CompanyRole::Admin])->save();

        $this->createDraftPassport($this->product);

        $this->get(route('catalog.products.passport.edit', ['product' => $this->product->uuid]))
            ->assertOk();
    }

    public function test_editor_can_access_editor(): void
    {
        $membership = $this->actor->memberships()
            ->where('company_id', $this->company->getKey())
            ->first();
        $membership->forceFill(['role' => CompanyRole::Editor])->save();

        $this->createDraftPassport($this->product);

        $this->get(route('catalog.products.passport.edit', ['product' => $this->product->uuid]))
            ->assertOk();
    }

    public function test_viewer_sees_read_only(): void
    {
        $passport = $this->createDraftPassport($this->product);

        $membership = $this->actor->memberships()
            ->where('company_id', $this->company->getKey())
            ->first();
        $membership->forceFill(['role' => CompanyRole::Viewer])->save();

        $this->get(route('catalog.products.passport.edit', ['product' => $this->product->uuid]))
            ->assertOk();
    }

    public function test_archived_product_read_only_behavior(): void
    {
        $archivedProduct = $this->createProductForArchivedTest();

        $this->post(route('catalog.products.passport.store', ['product' => $archivedProduct->uuid]))
            ->assertStatus(500);
    }

    public function test_valid_section_update_via_web(): void
    {
        $passport = $this->createDraftPassport($this->product);

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::UsageAndCare->value,
            ]),
            [
                'section_payload' => ['usage_instructions' => 'Montera enligt anvisning.'],
                'expected_revision' => 1,
            ],
        );

        $response->assertOk();
        $json = $response->json();

        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('passport_uuid', $json['data']);
        $this->assertSame(2, $json['data']['draft_revision']);
    }

    public function test_validation_error_on_section_update(): void
    {
        $this->createDraftPassport($this->product);

        $response = $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::UsageAndCare->value,
            ]),
            [
                'section_payload' => ['unknown_field' => 'bad'],
                'expected_revision' => 1,
            ],
        );

        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('errors', $json);
    }

    public function test_stale_revision_conflict(): void
    {
        $passport = $this->createDraftPassport($this->product);

        $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::UsageAndCare->value,
            ]),
            [
                'section_payload' => ['usage_instructions' => 'Undvik kontakt med vatten.'],
                'expected_revision' => 1,
            ],
        )->assertOk();

        $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::UsageAndCare->value,
            ]),
            [
                'section_payload' => ['usage_instructions' => 'För gammal revision.'],
                'expected_revision' => 1,
            ],
        )->assertStatus(409);
    }

    public function test_settings_update(): void
    {
        $passport = $this->createDraftPassport($this->product);

        $response = $this->putJson(
            route('catalog.products.passport.settings.update', [
                'product' => $this->product->uuid,
            ]),
            [
                'settings' => [
                    'enabled_sections' => array_map(fn ($s) => $s->value, DppSectionKey::cases()),
                ],
                'expected_revision' => 1,
            ],
        );

        $response->assertOk();
        $json = $response->json();

        $this->assertSame(2, $json['draft_revision']);
    }

    public function test_document_sync(): void
    {
        $passport = $this->createDraftPassport($this->product);

        $document = ProductDocument::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'status' => ProductDocumentStatus::Active->value,
            'created_by_user_id' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $version = new ProductDocumentVersion;
        $version->forceFill([
            'uuid' => fake()->uuid(),
            'company_id' => $this->company->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'document_type' => ProductDocumentType::Instruction->value,
            'title' => 'Web Test Doc',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
            'original_filename' => 'web-test.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'storage_key' => 'test/'.fake()->uuid().'.pdf',
            'created_by_user_id' => $this->actor->getKey(),
        ])->save();

        $document->forceFill(['current_version_id' => $version->getKey()])->save();

        $response = $this->putJson(
            route('catalog.products.passport.documents.update', [
                'product' => $this->product->uuid,
            ]),
            [
                'document_references' => [[
                    'document_uuid' => $document->uuid,
                    'role' => 'instruction',
                ]],
                'expected_revision' => 1,
            ],
        );

        $response->assertOk();
        $json = $response->json();

        $this->assertSame(2, $json['draft_revision']);
        $this->assertCount(1, $json['payload']['document_references']);
    }

    public function test_section_reset(): void
    {
        $passport = $this->createDraftPassport($this->product);

        $this->putJson(
            route('catalog.products.passport.sections.update', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::UsageAndCare->value,
            ]),
            [
                'section_payload' => ['usage_instructions' => 'Kommer att återställas.'],
                'expected_revision' => 1,
            ],
        )->assertOk();

        $response = $this->postJson(
            route('catalog.products.passport.sections.reset', [
                'product' => $this->product->uuid,
                'section' => DppSectionKey::UsageAndCare->value,
            ]),
            [
                'expected_revision' => 2,
            ],
        );

        $response->assertOk();
        $json = $response->json();

        $this->assertSame(3, $json['data']['draft_revision']);
    }

    public function test_wrong_tenant_returns_404(): void
    {
        $otherCompany = Company::factory()->create(['status' => CompanyStatus::Active]);
        $otherUser = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'company_id' => $otherCompany->getKey(),
            'user_id' => $otherUser->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $this->actingAs($otherUser);
        app(CurrentCompany::class)->set($otherCompany);

        $this->get(route('catalog.products.passport.show', ['product' => $this->product->uuid]))
            ->assertNotFound();

        $this->get(route('catalog.products.passport.edit', ['product' => $this->product->uuid]))
            ->assertNotFound();

        $this->post(route('catalog.products.passport.store', ['product' => $this->product->uuid]))
            ->assertNotFound();
    }
}
