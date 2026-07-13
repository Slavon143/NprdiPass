<?php

use App\Actions\Companies\ChangeCompanyMemberRole;
use App\Domain\Companies\Exceptions\LastCompanyOwnerCannotBeRemoved;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;

test('owner changes a viewer to editor and keeps owner flags synchronized', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $target = CompanyMembership::factory()->viewer()->create(['company_id' => $company]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    app(ChangeCompanyMemberRole::class)->execute($actor, $target, CompanyRole::Editor);

    expect($target->role)->toBe(CompanyRole::Editor)
        ->and($target->is_owner)->toBeFalse();
});

test('owner can add another owner without automatically demoting anyone', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $actorMembership = CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $target = CompanyMembership::factory()->admin()->create(['company_id' => $company]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    app(ChangeCompanyMemberRole::class)->execute($actor, $target, CompanyRole::Owner);

    expect($target->role)->toBe(CompanyRole::Owner)
        ->and($target->is_owner)->toBeTrue()
        ->and($actorMembership->fresh()?->role)->toBe(CompanyRole::Owner)
        ->and(CompanyMembership::query()
            ->where('company_id', $company->getKey())
            ->where('role', CompanyRole::Owner->value)
            ->count())->toBe(2);
});

test('admin can change non owner roles', function (
    CompanyRole $startingRole,
    CompanyRole $newRole,
) {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company]);
    CompanyMembership::factory()->admin()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $target = CompanyMembership::factory()->create([
        'company_id' => $company,
        'role' => $startingRole,
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    app(ChangeCompanyMemberRole::class)->execute($actor, $target, $newRole);

    expect($target->role)->toBe($newRole)
        ->and($target->is_owner)->toBeFalse();
})->with([
    'viewer to editor' => [CompanyRole::Viewer, CompanyRole::Editor],
    'editor to viewer' => [CompanyRole::Editor, CompanyRole::Viewer],
]);

test('admin cannot assign owner or change an owner', function (
    CompanyRole $targetRole,
    CompanyRole $newRole,
) {
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

    expect(fn () => app(ChangeCompanyMemberRole::class)->execute($actor, $target, $newRole))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh()?->role)->toBe($targetRole);
})->with([
    'assign owner' => [CompanyRole::Viewer, CompanyRole::Owner],
    'promote admin to owner' => [CompanyRole::Admin, CompanyRole::Owner],
    'change owner' => [CompanyRole::Owner, CompanyRole::Editor],
]);

test('editor and viewer cannot change member roles', function (CompanyRole $actorRole) {
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

    expect(fn () => app(ChangeCompanyMemberRole::class)->execute(
        $actor,
        $target,
        CompanyRole::Editor,
    ))->toThrow(AuthorizationException::class);

    expect($target->fresh()?->role)->toBe(CompanyRole::Viewer);
})->with([
    'editor' => [CompanyRole::Editor],
    'viewer' => [CompanyRole::Viewer],
]);

test('role action cannot change a membership outside current company', function () {
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

    expect(fn () => app(ChangeCompanyMemberRole::class)->execute(
        $actor,
        $target,
        CompanyRole::Editor,
    ))->toThrow(AuthorizationException::class);

    expect($target->fresh()?->role)->toBe(CompanyRole::Viewer);
});

test('the only owner cannot be downgraded and the transaction leaves no partial state', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    expect(fn () => app(ChangeCompanyMemberRole::class)->execute(
        $actor,
        $membership,
        CompanyRole::Admin,
    ))->toThrow(LastCompanyOwnerCannotBeRemoved::class);

    $membership->refresh();

    expect($membership->role)->toBe(CompanyRole::Owner)
        ->and($membership->is_owner)->toBeTrue()
        ->and(CompanyMembership::query()
            ->where('company_id', $company->getKey())
            ->where('role', CompanyRole::Owner->value)
            ->count())->toBe(1);
});

test('one of two owners can be downgraded while one owner remains', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $target = CompanyMembership::factory()->owner()->create(['company_id' => $company]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    app(ChangeCompanyMemberRole::class)->execute($actor, $target, CompanyRole::Admin);

    expect($target->role)->toBe(CompanyRole::Admin)
        ->and($target->is_owner)->toBeFalse()
        ->and(CompanyMembership::query()
            ->where('company_id', $company->getKey())
            ->where('role', CompanyRole::Owner->value)
            ->count())->toBe(1);
});

test('role changes are denied for a non active company', function (CompanyStatus $status) {
    $actor = User::factory()->create();
    $company = Company::factory()->create(['status' => $status]);
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $target = CompanyMembership::factory()->viewer()->create(['company_id' => $company]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    expect(fn () => app(ChangeCompanyMemberRole::class)->execute(
        $actor,
        $target,
        CompanyRole::Editor,
    ))->toThrow(AuthorizationException::class);
})->with([
    'suspended' => [CompanyStatus::Suspended],
    'archived' => [CompanyStatus::Archived],
]);
