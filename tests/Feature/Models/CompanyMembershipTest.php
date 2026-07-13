<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

test('a user can have different roles in multiple companies', function () {
    $user = User::factory()->create();
    $ownedCompany = Company::factory()->create();
    $viewedCompany = Company::factory()->create();

    CompanyMembership::factory()->owner()->create([
        'company_id' => $ownedCompany->id,
        'user_id' => $user->id,
    ]);
    CompanyMembership::factory()->viewer()->create([
        'company_id' => $viewedCompany->id,
        'user_id' => $user->id,
    ]);

    $memberships = $user->memberships()->get()->keyBy('company_id');

    expect($user->companies()->count())->toBe(2)
        ->and($memberships[$ownedCompany->id]->role)->toBe(CompanyRole::Owner)
        ->and($memberships[$viewedCompany->id]->role)->toBe(CompanyRole::Viewer);
});

test('duplicate company membership is rejected by the database', function () {
    $membership = CompanyMembership::factory()->create();

    expect(fn () => CompanyMembership::factory()->create([
        'company_id' => $membership->company_id,
        'user_id' => $membership->user_id,
    ]))->toThrow(QueryException::class);
});

test('membership keeps owner role and owner flag synchronized', function () {
    $membership = CompanyMembership::factory()->create([
        'role' => CompanyRole::Owner,
        'is_owner' => false,
    ]);

    expect($membership->is_owner)->toBeTrue();

    $membership->role = CompanyRole::Admin;
    $membership->is_owner = true;
    $membership->save();

    expect($membership->fresh()->role)->toBe(CompanyRole::Admin)
        ->and($membership->fresh()->is_owner)->toBeFalse();
});

test('belongs to many operations use the custom membership model invariant', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();

    $company->users()->attach($user, [
        'role' => CompanyRole::Owner,
        'is_owner' => false,
        'joined_at' => now(),
    ]);

    $membership = CompanyMembership::query()->firstOrFail();

    expect($membership->is_owner)->toBeTrue();

    $company->users()->updateExistingPivot($user->id, [
        'role' => CompanyRole::Viewer,
        'is_owner' => true,
    ]);

    expect($membership->fresh()->role)->toBe(CompanyRole::Viewer)
        ->and($membership->fresh()->is_owner)->toBeFalse();
});

test('membership casts fields persists timestamps and belongs to user and company', function () {
    $joinedAt = now()->subDay();
    $membership = CompanyMembership::factory()->editor()->create([
        'joined_at' => $joinedAt,
    ]);

    expect($membership->role)->toBe(CompanyRole::Editor)
        ->and($membership->is_owner)->toBeFalse()
        ->and($membership->joined_at)->toBeInstanceOf(Carbon::class)
        ->and($membership->created_at)->not->toBeNull()
        ->and($membership->updated_at)->not->toBeNull()
        ->and($membership->company->is(Company::findOrFail($membership->company_id)))->toBeTrue()
        ->and($membership->user->is(User::findOrFail($membership->user_id)))->toBeTrue();
});
