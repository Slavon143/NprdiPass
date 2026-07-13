<?php

use App\Enums\ApiTokenAbility;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;

test('API token pruning dry run reports without deleting', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $expired = issueCompanyApiToken(
        $user,
        $company,
        [ApiTokenAbility::CompanyRead->value],
        now()->subDays(40),
    );

    $this->artisan('nordipass:prune-api-tokens', ['--dry-run' => true, '--days' => 30])
        ->expectsOutput('1 API token(s) would be pruned.')
        ->assertSuccessful();

    expect($expired->accessToken->fresh())->not->toBeNull();
});

test('API token pruning removes old invalid tokens and preserves active tokens', function () {
    $activeUser = User::factory()->create();
    $inactiveUser = User::factory()->create(['status' => UserStatus::Suspended]);
    $company = Company::factory()->create();
    $active = issueCompanyApiToken($activeUser, $company, [ApiTokenAbility::CompanyRead->value]);
    $recentlyExpired = issueCompanyApiToken(
        $activeUser,
        $company,
        [ApiTokenAbility::CompanyRead->value],
        now()->subDays(5),
    );
    $oldExpired = issueCompanyApiToken(
        $activeUser,
        $company,
        [ApiTokenAbility::CompanyRead->value],
        now()->subDays(40),
    );
    $inactive = issueCompanyApiToken($inactiveUser, $company, [ApiTokenAbility::CompanyRead->value]);
    $inactive->accessToken->forceFill(['created_at' => now()->subDays(40)])->save();
    $orphaned = issueCompanyApiToken($activeUser, $company, [ApiTokenAbility::CompanyRead->value]);
    $orphaned->accessToken->forceFill([
        'company_id' => null,
        'created_at' => now()->subDays(40),
    ])->save();

    $this->artisan('nordipass:prune-api-tokens', ['--days' => 30])
        ->expectsOutput('3 API token(s) pruned.')
        ->assertSuccessful();

    expect($active->accessToken->fresh())->not->toBeNull()
        ->and($recentlyExpired->accessToken->fresh())->not->toBeNull()
        ->and($oldExpired->accessToken->fresh())->toBeNull()
        ->and($inactive->accessToken->fresh())->toBeNull()
        ->and($orphaned->accessToken->fresh())->toBeNull();
});

test('API token pruning validates retention days', function () {
    $this->artisan('nordipass:prune-api-tokens', ['--days' => 'invalid'])
        ->expectsOutput('The --days option must be an integer between 0 and 3650.')
        ->assertFailed();
});
