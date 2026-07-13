<?php

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;

test('a user can switch to an active company by UUID and access the dashboard', function () {
    $user = User::factory()->create();
    $companies = Company::factory()->count(2)->active()->create();

    foreach ($companies as $company) {
        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);
    }

    $selectedCompany = $companies->last();
    $response = $this->actingAs($user)->post(route('companies.switch', $selectedCompany));

    $response->assertRedirect(route('dashboard'))
        ->assertSessionHas(config('tenancy.session_key'), $selectedCompany->id);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee($selectedCompany->name);
});

test('a user cannot switch to a foreign company even when a trusted id is posted', function () {
    $user = User::factory()->create();
    $ownCompany = Company::factory()->active()->create();
    $foreignCompany = Company::factory()->active()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $ownCompany->id,
    ]);

    $this->actingAs($user)
        ->post(route('companies.switch', $foreignCompany), [
            'company_id' => $ownCompany->id,
        ])
        ->assertForbidden();

    expect(session(config('tenancy.session_key')))->not->toBe($foreignCompany->id);
});

test('a user cannot switch to a suspended or archived company', function (string $state) {
    $user = User::factory()->create();
    $company = Company::factory()->{$state}()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
    ]);

    $this->actingAs($user)
        ->post(route('companies.switch', $company))
        ->assertForbidden();

    expect(session()->has(config('tenancy.session_key')))->toBeFalse();
})->with(['suspended', 'archived']);

test('company switch route rejects a numeric id in place of a UUID', function () {
    $user = User::factory()->create();
    $company = Company::factory()->active()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
    ]);

    $this->actingAs($user)
        ->post('/companies/'.$company->id.'/switch')
        ->assertNotFound();
});
