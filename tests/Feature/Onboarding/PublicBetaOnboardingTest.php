<?php

use App\Actions\Catalog\Products\ProductAggregateCreator;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\CompanyRole;
use App\Enums\Passports\ProductPassportStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Onboarding\PublicBetaOnboardingService;
use App\Tenancy\Contracts\CurrentCompany;

function r318Tenant(CompanyRole $role = CompanyRole::Owner): array
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create(['settings' => ['support_email' => 'support@example.test']]);

    $factory = CompanyMembership::factory()
        ->for($actor, 'user')
        ->for($company);

    (match ($role) {
        CompanyRole::Owner => $factory->owner(),
        CompanyRole::Admin => $factory->admin(),
        CompanyRole::Editor => $factory->editor(),
        CompanyRole::Viewer => $factory->viewer(),
    })->create();

    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company];
}

function r318Product(User $actor, Company $company, string $slug = 'beta-product'): Product
{
    return app(ProductAggregateCreator::class)->create($actor, $company, [
        'name' => str($slug)->replace('-', ' ')->title()->toString(),
        'slug' => $slug,
        'description' => null,
        'brand' => null,
        'manufacturer' => null,
        'short_description' => 'Beta onboarding product.',
    ], [
        'name' => 'Default',
        'sku' => null,
        'sku_normalized' => null,
        'gtin' => null,
        'mpn' => null,
        'sort_order' => 0,
    ]);
}

function r318PublishedPassport(User $actor, Company $company, Product $product): ProductPassport
{
    $passport = ProductPassport::factory()->published()->create([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'created_by' => $actor->getKey(),
    ]);
    $version = ProductPassportVersion::factory()->published()->create([
        'company_id' => $company->getKey(),
        'passport_id' => $passport->getKey(),
        'published_by' => $actor->getKey(),
    ]);

    $passport->forceFill([
        'current_published_version_id' => $version->getKey(),
        'current_draft_version_id' => null,
        'status' => ProductPassportStatus::Published,
        'first_published_at' => now(),
        'last_published_at' => now(),
    ])->save();

    return $passport->refresh();
}

test('public beta onboarding dashboard renders the tenant launch path', function () {
    [$actor, $company] = r318Tenant();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_beta',
        'stripe_subscription_id' => 'sub_beta',
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::Active,
        'entitlements' => ['public_passports.enabled' => true],
    ]);
    $product = r318Product($actor, $company);
    r318PublishedPassport($actor, $company, $product);

    $this->actingAs($actor)
        ->get(route('onboarding.index'))
        ->assertOk()
        ->assertSee('Public Beta')
        ->assertSee('Launch path')
        ->assertSee('Activate billing')
        ->assertSee('Verify QR')
        ->assertSee('Support contact is configured');
});

test('sample product action is tenant scoped and idempotent', function () {
    [$actor, $company] = r318Tenant();
    $foreign = Company::factory()->create();
    r318Product($actor, $foreign, 'r3-18-sample-product');

    $this->actingAs($actor)
        ->post(route('onboarding.sample-product.store'))
        ->assertRedirect();

    $this->actingAs($actor)
        ->post(route('onboarding.sample-product.store'))
        ->assertRedirect();

    expect(Product::query()->forCompany($company)->where('slug_normalized', 'r3-18-sample-product')->count())->toBe(1)
        ->and(Product::query()->forCompany($foreign)->where('slug_normalized', 'r3-18-sample-product')->count())->toBe(1);
});

test('viewer can inspect onboarding but cannot create sample data', function () {
    [$viewer] = r318Tenant(CompanyRole::Viewer);

    $this->actingAs($viewer)
        ->get(route('onboarding.index'))
        ->assertOk();

    $this->actingAs($viewer)
        ->post(route('onboarding.sample-product.store'))
        ->assertForbidden();
});

test('snapshot never counts foreign tenant products or passports', function () {
    [$actor, $company] = r318Tenant();
    $foreign = Company::factory()->create();
    $ownProduct = r318Product($actor, $company, 'own-product');
    $foreignProduct = r318Product($actor, $foreign, 'foreign-product');
    r318PublishedPassport($actor, $foreign, $foreignProduct);

    $snapshot = app(PublicBetaOnboardingService::class)->snapshot($company);

    expect($snapshot['product_count'])->toBe(1)
        ->and($snapshot['passport_count'])->toBe(0)
        ->and($snapshot['published_passport_count'])->toBe(0)
        ->and($snapshot['steps']['first_product']['complete'])->toBeTrue()
        ->and($snapshot['steps']['publication']['complete'])->toBeFalse()
        ->and($snapshot['steps']['first_product']['detail'])->toBe($ownProduct->name)
        ->and(json_encode($snapshot, JSON_THROW_ON_ERROR))->not->toContain('foreign-product');
});

test('support page exposes the configured recovery path', function () {
    [$actor] = r318Tenant();

    $this->actingAs($actor)
        ->get(route('onboarding.support'))
        ->assertOk()
        ->assertSee('Support and recovery')
        ->assertSee('support@example.test')
        ->assertSee('Billing recovery')
        ->assertSee('Job center retry')
        ->assertSee('Publication recovery')
        ->assertSee('Company details');
});

test('onboarding help page explains readiness and recovery paths', function () {
    [$actor] = r318Tenant();

    $this->actingAs($actor)
        ->get(route('onboarding.help'))
        ->assertOk()
        ->assertSee('Onboarding help')
        ->assertSee('Readiness explained')
        ->assertSee('Blockers')
        ->assertSee('Warnings')
        ->assertSee('Publication and QR')
        ->assertSee('Recovery paths');
});

test('viewer can read onboarding help', function () {
    [$viewer] = r318Tenant(CompanyRole::Viewer);

    $this->actingAs($viewer)
        ->get(route('onboarding.help'))
        ->assertOk();
});

test('publication and qr steps are entitlement aware when the plan lacks public passports', function () {
    [$actor, $company] = r318Tenant();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_nopub',
        'stripe_subscription_id' => 'sub_nopub',
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::Active,
        'entitlements' => ['imports.enabled' => true],
    ]);
    $product = r318Product($actor, $company);
    r318PublishedPassport($actor, $company, $product);

    $snapshot = app(PublicBetaOnboardingService::class)->snapshot($company);

    expect($snapshot['public_passports_enabled'])->toBeFalse()
        ->and($snapshot['steps']['billing']['complete'])->toBeTrue()
        ->and($snapshot['steps']['publication']['complete'])->toBeFalse()
        ->and($snapshot['steps']['publication']['entitlement_required'])->toBe('public_passports.enabled')
        ->and($snapshot['steps']['publication']['url'])->toBe(route('settings.billing.index'))
        ->and($snapshot['steps']['qr']['complete'])->toBeFalse()
        ->and($snapshot['steps']['qr']['entitlement_required'])->toBe('public_passports.enabled')
        ->and($snapshot['steps']['qr']['url'])->toBe(route('settings.billing.index'))
        ->and($snapshot['complete'])->toBeFalse();

    $this->actingAs($actor)
        ->get(route('onboarding.index'))
        ->assertOk()
        ->assertSee('Plan upgrade required')
        ->assertSee('Plan required');
});

test('billing step exposes a recovery hint for stalled subscriptions', function () {
    [$actor, $company] = r318Tenant();
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_pastdue',
        'stripe_subscription_id' => 'sub_pastdue',
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::PastDue,
        'entitlements' => ['public_passports.enabled' => true],
    ]);

    $snapshot = app(PublicBetaOnboardingService::class)->snapshot($company);

    expect($snapshot['steps']['billing']['complete'])->toBeFalse()
        ->and($snapshot['steps']['billing']['detail'])->toBe('Subscription status: past_due — open billing to resume the journey.');

    $this->actingAs($actor)
        ->get(route('onboarding.index'))
        ->assertOk()
        ->assertSee('Subscription status: past_due');
});
