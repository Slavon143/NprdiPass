<?php

use App\Http\Middleware\EnsureUserBelongsToCurrentCompany;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

test('dashboard tenant middleware are registered in the required order', function () {
    $route = Route::getRoutes()->getByName('dashboard');

    expect($route)->not->toBeNull()
        ->and($route->middleware())->toBe([
            'web',
            'auth',
            'verified',
            'company.resolve',
            'company.selected',
            'company.member',
            'company.active',
        ]);
});

test('a guest is redirected to login before tenancy middleware run', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('a user without a company is redirected to the empty company page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('companies.none'));

    $this->get(route('companies.none'))
        ->assertOk()
        ->assertSee('does not currently have access');
});

test('a user with multiple active companies must select one', function () {
    $user = User::factory()->create();
    $companies = Company::factory()->count(2)->active()->create();

    foreach ($companies as $company) {
        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);
    }

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('companies.select'));
});

test('a user with one active company receives automatic dashboard access', function () {
    $user = User::factory()->create();
    $company = Company::factory()->active()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee($company->name)
        ->assertSessionHas(config('tenancy.session_key'), $company->id);
});

test('a stale membership clears the selected company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->active()->create();
    $membership = CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
    ]);
    $membership->delete();

    $this->actingAs($user)
        ->withSession([config('tenancy.session_key') => $company->id])
        ->get(route('dashboard'))
        ->assertRedirect(route('companies.none'))
        ->assertSessionMissing(config('tenancy.session_key'));
});

test('company member middleware performs a fresh database membership check', function () {
    $user = User::factory()->create();
    $company = Company::factory()->active()->create();
    $membership = CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
    ]);

    $this->actingAs($user);
    session()->put(config('tenancy.session_key'), $company->id);
    $membership->delete();

    $request = Request::create('/membership-check');
    $request->setUserResolver(fn () => $user);
    $request->attributes->set('currentCompany', $company);

    $response = app(EnsureUserBelongsToCurrentCompany::class)->handle(
        $request,
        fn () => response('unreachable'),
    );

    expect($response->isRedirect(route('companies.none')))->toBeTrue()
        ->and(session()->has(config('tenancy.session_key')))->toBeFalse();
});

test('a selected suspended company blocks HTML and JSON tenant requests', function () {
    $user = User::factory()->create();
    $company = Company::factory()->suspended()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
    ]);

    $this->actingAs($user)
        ->withSession([config('tenancy.session_key') => $company->id])
        ->get(route('dashboard'))
        ->assertRedirect(route('company.suspended'));

    $this->get(route('company.suspended'))
        ->assertOk()
        ->assertSee($company->name)
        ->assertSee('suspended');

    $this->getJson(route('dashboard'))
        ->assertStatus(423)
        ->assertJson(['message' => 'Current company is suspended.']);
});

test('a selected archived company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->archived()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
    ]);

    $this->actingAs($user)
        ->withSession([config('tenancy.session_key') => $company->id])
        ->get(route('dashboard'))
        ->assertForbidden();

    $this->getJson(route('dashboard'))
        ->assertForbidden()
        ->assertJson(['message' => 'Current company is archived.']);
});

test('a JSON tenant request without a selected company returns conflict', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('dashboard'))
        ->assertConflict()
        ->assertJson(['message' => 'Current company is not selected.']);
});

test('a JSON tenant request for only suspended memberships returns locked', function () {
    $user = User::factory()->create();
    $company = Company::factory()->suspended()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
    ]);

    $this->actingAs($user)
        ->getJson(route('dashboard'))
        ->assertStatus(423)
        ->assertJson(['message' => 'Current company is suspended.']);
});
