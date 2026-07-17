<?php

namespace Tests\Feature\Passports\Localization;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\UpdatePassportLanguagesAction;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class PassportLanguageConfigurationTest extends TestCase
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
            'name' => 'Language Config Product '.fake()->unique()->word(),
            'slug' => 'lang-config-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'lang-config-product-'.fake()->unique()->slug(1),
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
    }

    public function test_enable_swedish_via_update_languages_route(): void
    {
        $this->assertSame(['sv'], $this->passport->enabled_languages);

        $response = $this->putJson(
            route('catalog.products.passport.languages.update', ['product' => $this->product->uuid]),
            [
                'default_language' => 'sv',
                'enabled_languages' => ['sv', 'en'],
            ],
        );

        $response->assertOk();
        $json = $response->json();

        $this->assertArrayHasKey('data', $json);
        $this->assertSame('sv', $json['data']['default_language']);
        $this->assertContains('sv', $json['data']['enabled_languages']);
        $this->assertContains('en', $json['data']['enabled_languages']);

        $this->passport->refresh();
        $this->assertContains('en', $this->passport->enabled_languages);
    }

    public function test_disable_swedish(): void
    {
        $this->passport->setAttribute('enabled_languages', ['en', 'sv']);
        $this->passport->setAttribute('default_language', 'en');
        $this->passport->save();

        $response = $this->putJson(
            route('catalog.products.passport.languages.update', ['product' => $this->product->uuid]),
            [
                'default_language' => 'en',
                'enabled_languages' => ['en'],
            ],
        );

        $response->assertOk();
        $json = $response->json();

        $this->assertSame(['en'], $json['data']['enabled_languages']);

        $this->passport->refresh();
        $this->assertSame(['en'], $this->passport->enabled_languages);
    }

    public function test_cannot_disable_default_locale(): void
    {
        $this->passport->setAttribute('enabled_languages', ['sv', 'en']);
        $this->passport->save();

        $response = $this->putJson(
            route('catalog.products.passport.languages.update', ['product' => $this->product->uuid]),
            [
                'default_language' => 'sv',
                'enabled_languages' => ['en'],
            ],
        );

        $response->assertStatus(409);
    }

    public function test_cannot_disable_last_locale(): void
    {
        $action = app(UpdatePassportLanguagesAction::class);

        $this->expectException(ConflictHttpException::class);
        $action->handle($this->actor, $this->company, $this->product, $this->passport, 'sv', []);
    }

    public function test_change_default_to_swedish(): void
    {
        $this->passport->setAttribute('enabled_languages', ['en', 'sv']);
        $this->passport->setAttribute('default_language', 'en');
        $this->passport->save();

        $response = $this->putJson(
            route('catalog.products.passport.languages.update', ['product' => $this->product->uuid]),
            [
                'default_language' => 'sv',
                'enabled_languages' => ['sv', 'en'],
            ],
        );

        $response->assertOk();
        $json = $response->json();

        $this->assertSame('sv', $json['data']['default_language']);

        $this->passport->refresh();
        $this->assertSame('sv', $this->passport->default_language);
    }

    public function test_unsupported_locale_rejected(): void
    {
        $response = $this->putJson(
            route('catalog.products.passport.languages.update', ['product' => $this->product->uuid]),
            [
                'default_language' => 'fr',
                'enabled_languages' => ['fr', 'sv'],
            ],
        );

        $response->assertStatus(409);
    }

    public function test_viewer_cannot_mutate(): void
    {
        $this->actor->memberships()
            ->where('company_id', $this->company->getKey())
            ->update(['role' => CompanyRole::Viewer->value]);

        $response = $this->putJson(
            route('catalog.products.passport.languages.update', ['product' => $this->product->uuid]),
            [
                'default_language' => 'sv',
                'enabled_languages' => ['sv', 'en'],
            ],
        );

        $response->assertStatus(403);
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

        $this->putJson(
            route('catalog.products.passport.languages.update', ['product' => $this->product->uuid]),
            [
                'default_language' => 'sv',
                'enabled_languages' => ['sv', 'en'],
            ],
        )->assertNotFound();
    }

    public function test_duplicate_locales_rejected(): void
    {
        $action = app(UpdatePassportLanguagesAction::class);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Duplicate');
        $action->handle($this->actor, $this->company, $this->product, $this->passport, 'sv', ['sv', 'sv']);
    }

    public function test_empty_enabled_languages_rejected(): void
    {
        $action = app(UpdatePassportLanguagesAction::class);

        $this->expectException(ConflictHttpException::class);
        $action->handle($this->actor, $this->company, $this->product, $this->passport, 'sv', []);
    }
}
