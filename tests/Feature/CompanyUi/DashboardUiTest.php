<?php

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
        ->assertDontSee('Products')
        ->assertDontSee('Scans')
        ->assertDontSee('Revenue')
        ->assertDontSee('Storage usage');
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
