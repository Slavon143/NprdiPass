<?php

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
        ->assertSee('an-equally-long-billing-email-address@nordipass.local');
});
