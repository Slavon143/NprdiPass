<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;

test('owner and admin can update a viewer role through the UI route', function (CompanyRole $actorRole) {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company]);
    CompanyMembership::factory()->create([
        'user_id' => $actor,
        'company_id' => $company,
        'role' => $actorRole,
    ]);
    $target = CompanyMembership::factory()->viewer()->create(['company_id' => $company]);

    $this->actingAs($actor)
        ->patch(route('settings.members.role.update', ['membership' => $target->getKey()]), [
            'role' => CompanyRole::Editor->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Member role updated.');

    expect($target->fresh()?->role)->toBe(CompanyRole::Editor)
        ->and($target->fresh()?->is_owner)->toBeFalse();
})->with([
    'owner' => [CompanyRole::Owner],
    'admin' => [CompanyRole::Admin],
]);

test('admin cannot assign owner or modify an existing owner', function (
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

    $this->actingAs($actor)
        ->patch(route('settings.members.role.update', ['membership' => $target->getKey()]), [
            'role' => $newRole->value,
        ])
        ->assertForbidden();

    expect($target->fresh()?->role)->toBe($targetRole);
})->with([
    'assign owner' => [CompanyRole::Viewer, CompanyRole::Owner],
    'change owner' => [CompanyRole::Owner, CompanyRole::Editor],
]);

test('editor and viewer cannot submit role updates', function (CompanyRole $actorRole) {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company]);
    CompanyMembership::factory()->create([
        'user_id' => $actor,
        'company_id' => $company,
        'role' => $actorRole,
    ]);
    $target = CompanyMembership::factory()->viewer()->create(['company_id' => $company]);

    $this->actingAs($actor)
        ->patch(route('settings.members.role.update', ['membership' => $target->getKey()]), [
            'role' => CompanyRole::Editor->value,
        ])
        ->assertForbidden();

    expect($target->fresh()?->role)->toBe(CompanyRole::Viewer);
})->with([
    'editor' => [CompanyRole::Editor],
    'viewer' => [CompanyRole::Viewer],
]);

test('foreign membership substitution returns not found before policy authorization', function () {
    $actor = User::factory()->create();
    $currentCompany = Company::factory()->create();
    $foreignCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $currentCompany,
    ]);
    $foreignMembership = CompanyMembership::factory()->viewer()->create([
        'company_id' => $foreignCompany,
    ]);

    $this->actingAs($actor)
        ->patch(route('settings.members.role.update', ['membership' => $foreignMembership->getKey()]), [
            'role' => CompanyRole::Editor->value,
        ])
        ->assertNotFound();

    expect($foreignMembership->fresh()?->role)->toBe(CompanyRole::Viewer);
});

test('last owner downgrade becomes a safe validation error and changes nothing', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);

    $this->actingAs($actor)
        ->from(route('settings.members.index'))
        ->patch(route('settings.members.role.update', ['membership' => $membership->getKey()]), [
            'role' => CompanyRole::Admin->value,
        ])
        ->assertRedirect(route('settings.members.index'))
        ->assertSessionHasErrors(['role' => 'At least one owner is required.']);

    expect($membership->fresh()?->role)->toBe(CompanyRole::Owner)
        ->and($membership->fresh()?->is_owner)->toBeTrue();
});

test('invalid role is rejected and hidden owner flag cannot be trusted', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $target = CompanyMembership::factory()->viewer()->create(['company_id' => $company]);

    $this->actingAs($actor)
        ->from(route('settings.members.index'))
        ->patch(route('settings.members.role.update', ['membership' => $target->getKey()]), [
            'role' => 'super_admin',
            'is_owner' => true,
        ])
        ->assertRedirect(route('settings.members.index'))
        ->assertSessionHasErrors('role');

    expect($target->fresh()?->role)->toBe(CompanyRole::Viewer)
        ->and($target->fresh()?->is_owner)->toBeFalse();

    $this->patch(route('settings.members.role.update', ['membership' => $target->getKey()]), [
        'role' => CompanyRole::Editor->value,
        'is_owner' => true,
    ])->assertSessionHas('success');

    expect($target->fresh()?->role)->toBe(CompanyRole::Editor)
        ->and($target->fresh()?->is_owner)->toBeFalse();
});

test('stale admin page cannot authorize after actor role is revoked', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company]);
    $actorMembership = CompanyMembership::factory()->admin()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $target = CompanyMembership::factory()->viewer()->create(['company_id' => $company]);
    $this->actingAs($actor)
        ->get(route('settings.members.index'))
        ->assertOk();

    CompanyMembership::query()->whereKey($actorMembership->getKey())->update([
        'role' => CompanyRole::Viewer->value,
        'is_owner' => false,
    ]);

    $this->patch(route('settings.members.role.update', ['membership' => $target->getKey()]), [
        'role' => CompanyRole::Editor->value,
    ])->assertForbidden();

    expect($target->fresh()?->role)->toBe(CompanyRole::Viewer);
});

test('role update endpoint only accepts patch semantics', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $target = CompanyMembership::factory()->viewer()->create(['company_id' => $company]);

    $this->actingAs($actor)
        ->post(route('settings.members.role.update', ['membership' => $target->getKey()]), [
            'role' => CompanyRole::Editor->value,
        ])
        ->assertMethodNotAllowed();
});
