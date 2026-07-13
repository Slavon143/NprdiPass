<?php

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\CompanyResolver;

test('resolver automatically selects the only active company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->active()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
    ]);
    $this->actingAs($user);

    $resolved = app(CompanyResolver::class)->resolveFor($user);

    expect($resolved?->is($company))->toBeTrue()
        ->and(session(config('tenancy.session_key')))->toBe($company->id);
});

test('resolver does not choose an arbitrary company when two are active', function () {
    $user = User::factory()->create();
    $companies = Company::factory()->count(2)->active()->create();

    foreach ($companies as $company) {
        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);
    }

    $this->actingAs($user);

    expect(app(CompanyResolver::class)->resolveFor($user))->toBeNull()
        ->and(session()->has(config('tenancy.session_key')))->toBeFalse();
});

test('resolver restores a previously selected accessible company', function () {
    $user = User::factory()->create();
    $companies = Company::factory()->count(2)->active()->create();

    foreach ($companies as $company) {
        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);
    }

    $selectedCompany = $companies->last();
    $this->actingAs($user)->withSession([
        config('tenancy.session_key') => $selectedCompany->id,
    ]);

    expect(app(CompanyResolver::class)->resolveFor($user)?->is($selectedCompany))->toBeTrue();
});

test('resolver clears a foreign selection and does not replace it when multiple companies are available', function () {
    $user = User::factory()->create();
    $ownCompanies = Company::factory()->count(2)->active()->create();
    $foreignCompany = Company::factory()->active()->create();

    foreach ($ownCompanies as $company) {
        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);
    }

    $this->actingAs($user)->withSession([
        config('tenancy.session_key') => $foreignCompany->id,
    ]);

    expect(app(CompanyResolver::class)->resolveFor($user))->toBeNull()
        ->and(session()->has(config('tenancy.session_key')))->toBeFalse();
});

test('resolver returns null for a user without memberships', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(app(CompanyResolver::class)->resolveFor($user))->toBeNull();
});

test('resolver does not automatically select suspended or archived companies', function (string $state) {
    $user = User::factory()->create();
    $company = Company::factory()->{$state}()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
    ]);
    $this->actingAs($user);

    expect(app(CompanyResolver::class)->resolveFor($user))->toBeNull()
        ->and(session()->has(config('tenancy.session_key')))->toBeFalse();
})->with(['suspended', 'archived']);

test('resolver clears a selected company after membership removal', function () {
    $user = User::factory()->create();
    $company = Company::factory()->active()->create();
    $membership = CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
    ]);
    $membership->delete();

    $this->actingAs($user)->withSession([
        config('tenancy.session_key') => $company->id,
    ]);

    expect(app(CompanyResolver::class)->resolveFor($user))->toBeNull()
        ->and(session()->has(config('tenancy.session_key')))->toBeFalse();
});
