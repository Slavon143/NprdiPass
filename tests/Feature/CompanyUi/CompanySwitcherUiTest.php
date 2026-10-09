<?php

use App\Enums\Billing\BillingSubscriptionStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;

test('single company layout shows a compact current company without switch dropdown', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Only Active Company']);
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Only Active Company')
        ->assertDontSee('Switch company');
});

test('switcher shows only active user companies marks current and uses uuid routes', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create(['name' => 'Active Company Alpha']);
    $companyB = Company::factory()->create(['name' => 'Active Company Beta']);
    $suspendedCompany = Company::factory()->suspended()->create(['name' => 'Suspended Company Hidden']);
    $foreignCompany = Company::factory()->create(['name' => 'Foreign Company Hidden']);
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $companyA,
    ]);
    CompanyMembership::factory()->viewer()->create([
        'user_id' => $user,
        'company_id' => $companyB,
    ]);
    CompanyMembership::factory()->viewer()->create([
        'user_id' => $user,
        'company_id' => $suspendedCompany,
    ]);
    CompanyMembership::factory()->owner()->create(['company_id' => $foreignCompany]);

    $response = $this->actingAs($user)
        ->withSession([config('tenancy.session_key') => $companyA->getKey()])
        ->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Switch company')
        ->assertSee('Active Company Alpha')
        ->assertSee('Active Company Beta')
        ->assertSee('aria-current="true"', false)
        ->assertSee(route('companies.switch', $companyB), false)
        ->assertDontSee(route('companies.switch', ['company' => $companyB->getKey()]), false)
        ->assertDontSee('Suspended Company Hidden')
        ->assertDontSee('Foreign Company Hidden');
});

test('dashboard layout renders array flash messages after company context changes', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create(['name' => 'Array Flash Alpha']);
    $companyB = Company::factory()->create(['name' => 'Array Flash Beta']);
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $companyA,
    ]);
    CompanyMembership::factory()->viewer()->create([
        'user_id' => $user,
        'company_id' => $companyB,
    ]);

    $this->actingAs($user)
        ->withSession([
            config('tenancy.session_key') => $companyB->getKey(),
            'success' => ['Company switched.', 'Dashboard refreshed.'],
        ])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Array Flash Beta')
        ->assertSee('Company switched.')
        ->assertSee('Dashboard refreshed.');
});

test('dashboard layout renders localized array plan names', function () {
    config()->set('features.billing', true);
    config()->set('app.locale', 'en');
    config()->set('billing.plans.pilot.name', ['en' => 'Pilot localized', 'sv' => 'Pilot lokaliserad']);

    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Localized Plan Company']);
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);
    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::Trialing,
        'entitlements' => [
            'catalog.products.max' => 100,
        ],
        'trial_ends_at' => now()->addDays(14),
        'current_period_start' => now(),
        'current_period_end' => now()->addDays(14),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Localized Plan Company')
        ->assertSee('Plan: Pilot localized');
});

test('long company name and user email render without truncating source content', function () {
    $user = User::factory()->create([
        'name' => 'A User With A Deliberately Long Display Name For Responsive Review',
        'email' => 'a-very-long-user-email-address-for-responsive-testing@nordipass.local',
    ]);
    $company = Company::factory()->create([
        'name' => 'NordiPass Company With A Deliberately Long Legal Display Name AB',
        'billing_email' => 'an-equally-long-billing-email-address@nordipass.local',
    ]);
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('NordiPass Company With A Deliberately Long Legal Display Name AB')
        ->assertSee('A User With A Deliberately Long Display Name For Responsive Review')
        ->assertSee('a-very-long-user-email-address-for-responsive-testing@nordipass.local');
});
