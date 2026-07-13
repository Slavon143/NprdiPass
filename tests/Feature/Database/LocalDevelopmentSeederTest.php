<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Database\Seeders\LocalDevelopmentSeeder;

test('local development seeder creates the expected multi-company data idempotently', function () {
    $this->seed(LocalDevelopmentSeeder::class);
    $this->seed(LocalDevelopmentSeeder::class);

    $emails = [
        'superadmin@nordipass.local',
        'owner@nordipass.local',
        'admin@nordipass.local',
        'editor@nordipass.local',
        'viewer@nordipass.local',
        'multi@nordipass.local',
    ];

    expect(User::query()->whereIn('email', $emails)->count())->toBe(6)
        ->and(Company::query()->whereIn('name', ['NordiPass Demo AB', 'NordiPass Test AB'])->count())->toBe(2)
        ->and(CompanyMembership::query()->count())->toBe(6);

    $multiUser = User::query()->where('email', 'multi@nordipass.local')->firstOrFail();
    $memberships = $multiUser->memberships()->get()->keyBy('company_id');
    $demoCompany = Company::query()->where('name', 'NordiPass Demo AB')->firstOrFail();
    $testCompany = Company::query()->where('name', 'NordiPass Test AB')->firstOrFail();
    $demoMemberships = $demoCompany->memberships()
        ->with('user')
        ->get()
        ->keyBy(fn (CompanyMembership $membership): string => $membership->user->email);

    expect($memberships)->toHaveCount(2)
        ->and($memberships[$demoCompany->id]->role)->toBe(CompanyRole::Viewer)
        ->and($memberships[$testCompany->id]->role)->toBe(CompanyRole::Owner)
        ->and($demoMemberships['owner@nordipass.local']->role)->toBe(CompanyRole::Owner)
        ->and($demoMemberships['owner@nordipass.local']->is_owner)->toBeTrue()
        ->and($demoMemberships['admin@nordipass.local']->role)->toBe(CompanyRole::Admin)
        ->and($demoMemberships['editor@nordipass.local']->role)->toBe(CompanyRole::Editor)
        ->and($demoMemberships['viewer@nordipass.local']->role)->toBe(CompanyRole::Viewer)
        ->and($demoMemberships['multi@nordipass.local']->role)->toBe(CompanyRole::Viewer)
        ->and(User::query()->where('email', 'superadmin@nordipass.local')->firstOrFail()->memberships()->count())->toBe(0);
});

test('local development seeder does not create demo data in production', function () {
    $originalEnvironment = app()->environment();

    app()->instance('env', 'production');

    try {
        app(LocalDevelopmentSeeder::class)->run();

        expect(User::query()->count())->toBe(0)
            ->and(Company::query()->count())->toBe(0)
            ->and(CompanyMembership::query()->count())->toBe(0);
    } finally {
        app()->instance('env', $originalEnvironment);
    }
});
