<?php

use App\Actions\Companies\RemoveCompanyMember;
use App\Domain\Companies\Exceptions\CannotRemoveOwnCompanyMembership;
use App\Domain\Companies\Exceptions\LastCompanyOwnerCannotBeRemoved;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;

test('owner can remove non owner members', function (CompanyRole $targetRole) {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $target = CompanyMembership::factory()->create([
        'company_id' => $company,
        'role' => $targetRole,
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    app(RemoveCompanyMember::class)->execute($actor, $target);

    expect(CompanyMembership::query()->find($target->getKey()))->toBeNull();
})->with([
    'viewer' => [CompanyRole::Viewer],
    'admin' => [CompanyRole::Admin],
]);

test('admin can remove editor and viewer memberships', function (CompanyRole $targetRole) {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company]);
    CompanyMembership::factory()->admin()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $target = CompanyMembership::factory()->create([
        'company_id' => $company,
        'role' => $targetRole,
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    app(RemoveCompanyMember::class)->execute($actor, $target);

    expect(CompanyMembership::query()->find($target->getKey()))->toBeNull();
})->with([
    'viewer' => [CompanyRole::Viewer],
    'editor' => [CompanyRole::Editor],
]);

test('admin cannot remove an owner', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $target = CompanyMembership::factory()->owner()->create(['company_id' => $company]);
    CompanyMembership::factory()->admin()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    expect(fn () => app(RemoveCompanyMember::class)->execute($actor, $target))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh())->not->toBeNull();
});

test('editor and viewer cannot remove members', function (CompanyRole $actorRole) {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company]);
    CompanyMembership::factory()->create([
        'user_id' => $actor,
        'company_id' => $company,
        'role' => $actorRole,
    ]);
    $target = CompanyMembership::factory()->viewer()->create(['company_id' => $company]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    expect(fn () => app(RemoveCompanyMember::class)->execute($actor, $target))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh())->not->toBeNull();
})->with([
    'editor' => [CompanyRole::Editor],
    'viewer' => [CompanyRole::Viewer],
]);

test('member removal cannot target another company', function () {
    $actor = User::factory()->create();
    $currentCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $currentCompany,
    ]);
    $target = CompanyMembership::factory()->viewer()->create(['company_id' => $otherCompany]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($currentCompany);

    expect(fn () => app(RemoveCompanyMember::class)->execute($actor, $target))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh())->not->toBeNull();
});

test('admin removal action rejects self removal', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company]);
    $actorMembership = CompanyMembership::factory()->admin()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    expect(fn () => app(RemoveCompanyMember::class)->execute($actor, $actorMembership))
        ->toThrow(CannotRemoveOwnCompanyMembership::class);

    expect($actorMembership->fresh())->not->toBeNull();
});

test('removing a membership keeps its user and memberships in other companies', function () {
    $actor = User::factory()->create();
    $targetUser = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $target = CompanyMembership::factory()->viewer()->create([
        'user_id' => $targetUser,
        'company_id' => $company,
    ]);
    $otherMembership = CompanyMembership::factory()->admin()->create([
        'user_id' => $targetUser,
        'company_id' => $otherCompany,
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    app(RemoveCompanyMember::class)->execute($actor, $target);

    expect(User::query()->find($targetUser->getKey()))->not->toBeNull()
        ->and(CompanyMembership::query()->find($target->getKey()))->toBeNull()
        ->and(CompanyMembership::query()->find($otherMembership->getKey()))->not->toBeNull();
});

test('the only owner cannot be removed and the transaction leaves it intact', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    expect(fn () => app(RemoveCompanyMember::class)->execute($actor, $membership))
        ->toThrow(LastCompanyOwnerCannotBeRemoved::class);

    expect($membership->fresh()?->role)->toBe(CompanyRole::Owner);
});

test('one owner can remove another when two owners exist and one remains', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $target = CompanyMembership::factory()->owner()->create(['company_id' => $company]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    app(RemoveCompanyMember::class)->execute($actor, $target);

    expect($target->fresh())->toBeNull()
        ->and(CompanyMembership::query()
            ->where('company_id', $company->getKey())
            ->where('role', CompanyRole::Owner->value)
            ->count())->toBe(1);
});
