<?php

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use App\Tenancy\Exceptions\CurrentCompanyNotSet;
use App\Tenancy\SessionCurrentCompany;

test('current company is empty until a tenant is selected', function () {
    $currentCompany = app(CurrentCompany::class);

    expect($currentCompany->get())->toBeNull()
        ->and($currentCompany->has())->toBeFalse()
        ->and(fn () => $currentCompany->require())->toThrow(CurrentCompanyNotSet::class);
});

test('current company stores only the company id in session and can be cleared', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
    ]);

    $this->actingAs($user);
    $currentCompany = app(CurrentCompany::class);
    $currentCompany->set($company);

    expect(session(config('tenancy.session_key')))->toBe($company->id)
        ->and($currentCompany->get()?->is($company))->toBeTrue()
        ->and($currentCompany->has())->toBeTrue();

    $currentCompany->clear();

    expect(session()->has(config('tenancy.session_key')))->toBeFalse()
        ->and($currentCompany->get())->toBeNull();
});

test('stale and malformed company session values are cleared', function (mixed $value) {
    $user = User::factory()->create();
    $this->actingAs($user);
    session()->put(config('tenancy.session_key'), $value);

    expect(app(CurrentCompany::class)->get())->toBeNull()
        ->and(session()->has(config('tenancy.session_key')))->toBeFalse();
})->with([
    'missing company' => 999999,
    'non numeric value' => 'not-a-company',
    'invalid numeric value' => 0,
]);

test('a foreign company from session is rejected', function () {
    $user = User::factory()->create();
    $foreignCompany = Company::factory()->create();
    $this->actingAs($user);
    session()->put(config('tenancy.session_key'), $foreignCompany->id);

    expect(app(CurrentCompany::class)->get())->toBeNull()
        ->and(session()->has(config('tenancy.session_key')))->toBeFalse();
});

test('a soft deleted company from session is rejected', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
    ]);
    $company->delete();

    $this->actingAs($user);
    session()->put(config('tenancy.session_key'), $company->id);

    expect(app(CurrentCompany::class)->get())->toBeNull()
        ->and(session()->has(config('tenancy.session_key')))->toBeFalse();
});

test('a soft deleted authenticated user cannot restore a company from session', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
    ]);
    $user->delete();

    $this->actingAs($user);
    session()->put(config('tenancy.session_key'), $company->id);

    expect(app(CurrentCompany::class)->get())->toBeNull()
        ->and(session()->has(config('tenancy.session_key')))->toBeFalse();
});

test('one service instance does not leak state between users or service calls', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'user_id' => $firstUser->id,
        'company_id' => $company->id,
    ]);

    $currentCompany = app(CurrentCompany::class);

    $this->actingAs($firstUser);
    $currentCompany->set($company);
    expect($currentCompany->get()?->is($company))->toBeTrue();

    $currentCompany->clear();
    $this->actingAs($secondUser);

    expect($currentCompany->get())->toBeNull()
        ->and((new ReflectionClass(SessionCurrentCompany::class))->getProperties(ReflectionProperty::IS_STATIC))->toBeEmpty();
});
