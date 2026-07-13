<?php

use App\Enums\CompanyRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('owner admin and editor can view members while viewer receives forbidden', function (
    CompanyRole $role,
    int $status,
) {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user,
        'company_id' => $company,
        'role' => $role,
    ]);

    $this->actingAs($user)
        ->get(route('settings.members.index'))
        ->assertStatus($status);
})->with([
    'owner' => [CompanyRole::Owner, 200],
    'admin' => [CompanyRole::Admin, 200],
    'editor' => [CompanyRole::Editor, 200],
    'viewer' => [CompanyRole::Viewer, 403],
]);

test('members page shows only current company users and their role status and owner badge', function () {
    $actor = User::factory()->create(['name' => 'Current Owner']);
    $member = User::factory()->suspended()->create([
        'name' => 'Visible Suspended Member',
        'email' => 'visible-long-member-address@example.test',
        'status' => UserStatus::Suspended,
    ]);
    $foreignUser = User::factory()->create(['name' => 'Hidden Foreign Member']);
    $company = Company::factory()->create();
    $foreignCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    CompanyMembership::factory()->editor()->create([
        'user_id' => $member,
        'company_id' => $company,
    ]);
    CompanyMembership::factory()->admin()->create([
        'user_id' => $foreignUser,
        'company_id' => $foreignCompany,
    ]);

    $this->actingAs($actor)
        ->get(route('settings.members.index'))
        ->assertOk()
        ->assertSee('Current Owner')
        ->assertSee('Owner')
        ->assertSee('Visible Suspended Member')
        ->assertSee('visible-long-member-address@example.test')
        ->assertSee('editor')
        ->assertSee('suspended')
        ->assertDontSee('Hidden Foreign Member')
        ->assertDontSee('User ID')
        ->assertSee('name="_token"', false);
});

test('members are sorted by explicit role priority and then name', function () {
    $actor = User::factory()->create(['name' => 'Zulu Owner']);
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);

    foreach ([
        ['Alpha Viewer', CompanyRole::Viewer],
        ['Charlie Admin', CompanyRole::Admin],
        ['Bravo Editor', CompanyRole::Editor],
    ] as [$name, $role]) {
        CompanyMembership::factory()->create([
            'company_id' => $company,
            'user_id' => User::factory()->create(['name' => $name]),
            'role' => $role,
        ]);
    }

    $content = $this->actingAs($actor)
        ->get(route('settings.members.index'))
        ->assertOk()
        ->getContent();

    expect(strpos($content, 'Zulu Owner'))->toBeLessThan(strpos($content, 'Charlie Admin'))
        ->and(strpos($content, 'Charlie Admin'))->toBeLessThan(strpos($content, 'Bravo Editor'))
        ->and(strpos($content, 'Bravo Editor'))->toBeLessThan(strpos($content, 'Alpha Viewer'));
});

test('members page paginates at twenty five records', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    CompanyMembership::factory()->count(25)->viewer()->create(['company_id' => $company]);

    $this->actingAs($actor)
        ->get(route('settings.members.index'))
        ->assertOk()
        ->assertSee('?page=2', false);

    $this->get(route('settings.members.index', ['page' => 2]))
        ->assertOk();
});

test('members page eager loads users in one relation query', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    CompanyMembership::factory()->count(5)->viewer()->create(['company_id' => $company]);
    $this->actingAs($actor);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->get(route('settings.members.index'))->assertOk();

    $userRelationQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => (
            str_contains($query, 'from "users"') || str_contains($query, 'from `users`')
        )
            && str_contains($query, ' in ('));

    expect($userRelationQueries)->toHaveCount(1);
});

test('sole owner sees the owner invariant instead of a downgrade control', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);

    $this->actingAs($actor)
        ->get(route('settings.members.index'))
        ->assertOk()
        ->assertSee('At least one owner is required.')
        ->assertDontSee('id="role-'.$membership->getKey().'"', false);
});

test('admin role selector never offers owner', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company]);
    CompanyMembership::factory()->admin()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    CompanyMembership::factory()->viewer()->create(['company_id' => $company]);

    $this->actingAs($actor)
        ->get(route('settings.members.index'))
        ->assertOk()
        ->assertDontSee('<option value="owner"', false)
        ->assertSee('<option value="admin"', false)
        ->assertSee('<option value="editor"', false)
        ->assertSee('<option value="viewer"', false);
});
