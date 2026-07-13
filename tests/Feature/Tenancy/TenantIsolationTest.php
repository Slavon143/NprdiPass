<?php

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;

test('company selection lists only active companies belonging to the user', function () {
    $user = User::factory()->create();
    $ownCompanies = Company::factory()->count(2)->active()->create();
    $foreignCompany = Company::factory()->active()->create([
        'name' => 'Foreign Company',
    ]);

    foreach ($ownCompanies as $company) {
        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);
    }

    $response = $this->actingAs($user)->get(route('companies.select'));

    $response->assertOk()->assertDontSee($foreignCompany->name);

    foreach ($ownCompanies as $company) {
        $response->assertSee($company->name);
    }
});

test('a foreign company id in session never becomes the current company', function () {
    $user = User::factory()->create();
    $ownCompanies = Company::factory()->count(2)->active()->create();
    $foreignCompany = Company::factory()->active()->create();

    foreach ($ownCompanies as $company) {
        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);
    }

    $this->actingAs($user)
        ->withSession([config('tenancy.session_key') => $foreignCompany->id])
        ->get(route('dashboard'))
        ->assertRedirect(route('companies.select'))
        ->assertSessionMissing(config('tenancy.session_key'));

    expect(app(CurrentCompany::class)->get())->toBeNull();
});

test('route model binding does not bypass the company membership check', function () {
    $user = User::factory()->create();
    $foreignCompany = Company::factory()->active()->create();

    $this->actingAs($user)
        ->post('/companies/'.$foreignCompany->uuid.'/switch')
        ->assertForbidden();

    expect(app(CurrentCompany::class)->get())->toBeNull();
});

test('selection omits suspended and archived companies', function () {
    $user = User::factory()->create();
    $activeCompanies = Company::factory()->count(2)->active()->create();
    $suspendedCompany = Company::factory()->suspended()->create();
    $archivedCompany = Company::factory()->archived()->create();

    foreach ($activeCompanies->push($suspendedCompany, $archivedCompany) as $company) {
        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);
    }

    $response = $this->actingAs($user)->get(route('companies.select'));

    $response->assertOk()
        ->assertDontSee($suspendedCompany->name)
        ->assertDontSee($archivedCompany->name);
});
