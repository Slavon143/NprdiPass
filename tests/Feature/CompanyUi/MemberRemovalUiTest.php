<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;

test('owner and admin can remove a viewer with success flash while user remains', function (CompanyRole $actorRole) {
    $actor = User::factory()->create();
    $targetUser = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company]);
    CompanyMembership::factory()->create([
        'user_id' => $actor,
        'company_id' => $company,
        'role' => $actorRole,
    ]);
    $target = CompanyMembership::factory()->viewer()->create([
        'user_id' => $targetUser,
        'company_id' => $company,
    ]);

    $this->actingAs($actor)
        ->delete(route('settings.members.destroy', ['membership' => $target->getKey()]))
        ->assertRedirect()
        ->assertSessionHas('success', 'Member removed.');

    expect($target->fresh())->toBeNull()
        ->and($targetUser->fresh())->not->toBeNull();
})->with([
    'owner' => [CompanyRole::Owner],
    'admin' => [CompanyRole::Admin],
]);

test('admin cannot remove an owner', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $owner = CompanyMembership::factory()->owner()->create(['company_id' => $company]);
    CompanyMembership::factory()->admin()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);

    $this->actingAs($actor)
        ->delete(route('settings.members.destroy', ['membership' => $owner->getKey()]))
        ->assertForbidden();

    expect($owner->fresh())->not->toBeNull();
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

    $this->actingAs($actor)
        ->delete(route('settings.members.destroy', ['membership' => $target->getKey()]))
        ->assertForbidden();

    expect($target->fresh())->not->toBeNull();
})->with([
    'editor' => [CompanyRole::Editor],
    'viewer' => [CompanyRole::Viewer],
]);

test('foreign membership deletion substitution returns not found', function () {
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
        ->delete(route('settings.members.destroy', ['membership' => $foreignMembership->getKey()]))
        ->assertNotFound();

    expect($foreignMembership->fresh())->not->toBeNull();
});

test('self removal and last owner removal remain forbidden', function (CompanyRole $actorRole) {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->create([
        'user_id' => $actor,
        'company_id' => $company,
        'role' => $actorRole,
    ]);

    $this->actingAs($actor)
        ->delete(route('settings.members.destroy', ['membership' => $membership->getKey()]))
        ->assertForbidden();

    expect($membership->fresh())->not->toBeNull();
})->with([
    'owner' => [CompanyRole::Owner],
    'admin' => [CompanyRole::Admin],
]);

test('removing current membership preserves target membership in another company', function () {
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

    $this->actingAs($actor)
        ->delete(route('settings.members.destroy', ['membership' => $target->getKey()]))
        ->assertSessionHas('success');

    expect($target->fresh())->toBeNull()
        ->and($otherMembership->fresh())->not->toBeNull();
});

test('member removal endpoint only accepts delete semantics', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $target = CompanyMembership::factory()->viewer()->create(['company_id' => $company]);

    $this->actingAs($actor)
        ->get(route('settings.members.destroy', ['membership' => $target->getKey()]))
        ->assertMethodNotAllowed();
});
