<?php

use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\Catalog\ProductStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;

test('guest is redirected from dashboard to login', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('company member sees real current company data and role on dashboard', function () {
    $user = User::factory()->create(['name' => 'Dashboard User']);
    $company = Company::factory()->create([
        'name' => 'Dashboard Company AB',
        'legal_name' => 'Dashboard Legal AB',
        'organization_number' => '559999-0001',
        'country_code' => 'SE',
    ]);
    CompanyMembership::factory()->editor()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Dashboard Company AB')
        ->assertSee('Dashboard Legal AB')
        ->assertSee('559999-0001')
        ->assertSee('editor')
        ->assertSee('Dashboard User')
        ->assertSee('Products')
        ->assertDontSee('Scans')
        ->assertDontSee('Revenue')
        ->assertDontSee('Storage usage');
    $response->assertDontSee('Average readiness')->assertDontSee('% this month');
});

test('empty dashboard does not render the marketing sample product as company data', function () {
    $user = User::factory()->create(['name' => 'Empty Dashboard User']);
    $company = Company::factory()->create(['name' => 'Empty Dashboard Company']);
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('No products yet')
        ->assertDontSee('Industrial Storage Case');
});

test('dashboard renders readiness for a company with a product', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);

    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'name' => 'Readiness test product',
        'slug' => 'readiness-test-product',
        'slug_normalized' => 'readiness-test-product',
        'status' => ProductStatus::Active,
        'created_by' => $user->getKey(),
    ])->save();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Readiness test product')
        ->assertSee('No image')
        ->assertDontSee('industrial-storage-case.png')
        ->assertSee('Readiness: Not Evaluated');
});

test('dashboard shell renders billing summary from the current subscription', function () {
    config()->set('features.billing', true);

    $user = User::factory()->create(['name' => 'Billing Dashboard User']);
    $company = Company::factory()->create(['name' => 'Billing Dashboard Company']);
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'plan_key' => 'growth',
        'status' => BillingSubscriptionStatus::Trialing,
        'entitlements' => [
            'catalog.products.max' => 123,
        ],
        'trial_ends_at' => now()->addDays(6),
        'current_period_start' => now(),
        'current_period_end' => now()->addDays(6),
    ]);
    $trialEndDate = now()->addDays(6)->format('Y-m-d');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Plan: Growth')
        ->assertSee('Trial ends '.$trialEndDate)
        ->assertSee('0 / 123 products')
        ->assertDontSee('0 / 100 products');
});

test('dashboard does not expose data from another company', function () {
    $user = User::factory()->create();
    $currentCompany = Company::factory()->create(['name' => 'Visible Company']);
    $otherCompany = Company::factory()->create([
        'name' => 'Hidden Foreign Company',
        'legal_name' => 'Hidden Legal Name',
        'billing_email' => 'hidden-company@example.test',
    ]);
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $currentCompany,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Visible Company')
        ->assertDontSee('Hidden Foreign Company')
        ->assertDontSee('Hidden Legal Name')
        ->assertDontSee('hidden-company@example.test');
});

test('user without a company cannot open dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('companies.none'));
});

test('suspended company is blocked before dashboard controller', function () {
    $user = User::factory()->create();
    $company = Company::factory()->suspended()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);

    $this->actingAs($user)
        ->withSession([config('tenancy.session_key') => $company->getKey()])
        ->get(route('dashboard'))
        ->assertRedirect(route('company.suspended'));
});

test('dashboard policy rejects a suspended user even with a valid membership', function () {
    $user = User::factory()->suspended()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertForbidden();
});
