<?php

namespace Tests\Feature\Passports\Security;

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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class DppSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function createCompanyActorAndProduct(CompanyRole $role = CompanyRole::Owner): array
    {
        $company = Company::factory()->create(['status' => CompanyStatus::Active]);
        $actor = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
            'role' => $role,
        ]);

        $this->actingAs($actor);
        app(CurrentCompany::class)->set($company);

        $product = new Product;
        $product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $company->getKey(),
            'name' => 'Security Product '.fake()->unique()->word(),
            'slug' => 'security-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'security-product-'.fake()->unique()->slug(1),
            'status' => ProductStatus::Active,
            'created_by' => $actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        return [$company, $actor, $product->refresh()];
    }

    private function createDraftPassport(User $actor, Company $company, Product $product): ProductPassport
    {
        return app(CreateProductPassportDraftAction::class)->handle($actor, $company, $product);
    }

    public function test_owner_can_create_passport(): void
    {
        [$company, $actor, $product] = $this->createCompanyActorAndProduct(CompanyRole::Owner);
        $passport = $this->createDraftPassport($actor, $company, $product);

        $this->assertNotNull($passport);
        $this->assertEquals($product->getKey(), $passport->product_id);
    }

    public function test_admin_can_create_passport(): void
    {
        [$company, $actor, $product] = $this->createCompanyActorAndProduct(CompanyRole::Admin);
        $passport = $this->createDraftPassport($actor, $company, $product);

        $this->assertNotNull($passport);
        $this->assertEquals($product->getKey(), $passport->product_id);
    }

    public function test_editor_can_create_passport(): void
    {
        [$company, $actor, $product] = $this->createCompanyActorAndProduct(CompanyRole::Editor);
        $passport = $this->createDraftPassport($actor, $company, $product);

        $this->assertNotNull($passport);
        $this->assertEquals($product->getKey(), $passport->product_id);
    }

    public function test_viewer_cannot_create_passport(): void
    {
        [$company, $actor, $product] = $this->createCompanyActorAndProduct(CompanyRole::Viewer);

        $this->expectException(AuthorizationException::class);

        app(CreateProductPassportDraftAction::class)->handle($actor, $company, $product);
    }

    public function test_viewer_can_view_passport(): void
    {
        [$company, $viewer, $product] = $this->createCompanyActorAndProduct(CompanyRole::Viewer);

        $owner = User::factory()->create(['email_verified_at' => now()]);
        CompanyMembership::factory()->create([
            'company_id' => $company->getKey(),
            'user_id' => $owner->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $this->actingAs($owner);
        app(CurrentCompany::class)->set($company);
        $passport = $this->createDraftPassport($owner, $company, $product);

        $this->actingAs($viewer);
        app(CurrentCompany::class)->set($company);

        $found = ProductPassport::query()
            ->forCompany($company)
            ->where('product_id', $product->getKey())
            ->first();

        $this->assertNotNull($found);
    }

    public function test_viewer_cannot_edit_passport(): void
    {
        [$company, $actor, $product] = $this->createCompanyActorAndProduct(CompanyRole::Owner);
        $passport = $this->createDraftPassport($actor, $company, $product);

        $viewer = User::factory()->create(['email_verified_at' => now()]);
        CompanyMembership::factory()->create([
            'company_id' => $company->getKey(),
            'user_id' => $viewer->getKey(),
            'role' => CompanyRole::Viewer,
        ]);

        $this->actingAs($viewer);
        app(CurrentCompany::class)->set($company);

        $this->expectException(AuthorizationException::class);

        app(UpdateProductPassportSectionAction::class)->handle(
            $viewer,
            $company,
            $product,
            $passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'Test'],
            1,
        );
    }

    public function test_owner_can_edit_passport(): void
    {
        [$company, $actor, $product] = $this->createCompanyActorAndProduct(CompanyRole::Owner);
        $passport = $this->createDraftPassport($actor, $company, $product);

        $result = app(UpdateProductPassportSectionAction::class)->handle(
            $actor,
            $company,
            $product,
            $passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'Editor test.'],
            1,
        );

        $this->assertSame(2, $result->currentDraftVersion->draft_revision);
    }

    public function test_admin_can_edit_passport(): void
    {
        [$company, $actor, $product] = $this->createCompanyActorAndProduct(CompanyRole::Admin);
        $passport = $this->createDraftPassport($actor, $company, $product);

        $result = app(UpdateProductPassportSectionAction::class)->handle(
            $actor,
            $company,
            $product,
            $passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'Admin test.'],
            1,
        );

        $this->assertSame(2, $result->currentDraftVersion->draft_revision);
    }

    public function test_editor_can_edit_passport(): void
    {
        [$company, $actor, $product] = $this->createCompanyActorAndProduct(CompanyRole::Editor);
        $passport = $this->createDraftPassport($actor, $company, $product);

        $result = app(UpdateProductPassportSectionAction::class)->handle(
            $actor,
            $company,
            $product,
            $passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'Editor test.'],
            1,
        );

        $this->assertSame(2, $result->currentDraftVersion->draft_revision);
    }

    public function test_wrong_company_product_returns_404(): void
    {
        [$companyA, $actorA, $productA] = $this->createCompanyActorAndProduct(CompanyRole::Owner);

        $companyB = Company::factory()->create(['status' => CompanyStatus::Active]);
        $actorB = User::factory()->create(['email_verified_at' => now()]);
        CompanyMembership::factory()->create([
            'company_id' => $companyB->getKey(),
            'user_id' => $actorB->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $this->actingAs($actorB);
        app(CurrentCompany::class)->set($companyB);

        $this->expectException(NotFoundHttpException::class);

        app(CreateProductPassportDraftAction::class)->handle($actorB, $companyB, $productA);
    }

    public function test_internal_ids_not_in_passport_response(): void
    {
        [$company, $actor, $product] = $this->createCompanyActorAndProduct(CompanyRole::Owner);
        $passport = $this->createDraftPassport($actor, $company, $product);

        $array = $passport->toArray();

        $this->assertArrayNotHasKey('id', $array);
        $this->assertArrayNotHasKey('company_id', $array);
        $this->assertArrayNotHasKey('product_id', $array);
        $this->assertArrayNotHasKey('created_by', $array);
        $this->assertArrayNotHasKey('updated_by', $array);
    }

    public function test_internal_ids_not_in_version_response(): void
    {
        [$company, $actor, $product] = $this->createCompanyActorAndProduct(CompanyRole::Owner);
        $passport = $this->createDraftPassport($actor, $company, $product);
        $draft = $passport->currentDraftVersion;

        $array = $draft->toArray();

        $this->assertArrayNotHasKey('id', $array);
        $this->assertArrayNotHasKey('company_id', $array);
        $this->assertArrayNotHasKey('passport_id', $array);
        $this->assertArrayNotHasKey('content_checksum', $array);
        $this->assertArrayNotHasKey('created_by', $array);
        $this->assertArrayNotHasKey('updated_by', $array);
        $this->assertArrayNotHasKey('published_by', $array);
    }
}
