<?php

use App\Enums\ApiTokenAbility;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function apiCatalogContext(CompanyRole $role = CompanyRole::Owner): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create(['status' => CompanyStatus::Active]);
    CompanyMembership::factory()->create([
        'user_id' => $user->getKey(),
        'company_id' => $company->getKey(),
        'role' => $role,
    ]);

    return [$user, $company];
}

function apiToken(User $user, Company $company, array $abilities): string
{
    $token = issueCompanyApiToken($user, $company, $abilities);

    return $token->plainTextToken;
}

function apiUrl(string $path): string
{
    return '/api/v1/catalog/'.$path;
}

function apiGet(User $user, Company $company, array $abilities, string $path): TestResponse
{
    return test()->withToken(apiToken($user, $company, $abilities))
        ->getJson(apiUrl($path));
}

function apiPost(User $user, Company $company, array $abilities, string $path, array $data = []): TestResponse
{
    return test()->withToken(apiToken($user, $company, $abilities))
        ->postJson(apiUrl($path), $data);
}

function apiPatch(User $user, Company $company, array $abilities, string $path, array $data = []): TestResponse
{
    return test()->withToken(apiToken($user, $company, $abilities))
        ->patchJson(apiUrl($path), $data);
}

function apiPut(User $user, Company $company, array $abilities, string $path, array $data = []): TestResponse
{
    return test()->withToken(apiToken($user, $company, $abilities))
        ->putJson(apiUrl($path), $data);
}

function apiDelete(User $user, Company $company, array $abilities, string $path): TestResponse
{
    return test()->withToken(apiToken($user, $company, $abilities))
        ->deleteJson(apiUrl($path));
}

function apiLifecycleToken(User $user, Company $company): string
{
    return apiToken($user, $company, [
        ApiTokenAbility::CatalogLifecycle->value,
        ApiTokenAbility::CatalogRead->value,
    ]);
}

function apiUploadToken(User $user, Company $company): string
{
    return apiToken($user, $company, [
        ApiTokenAbility::CatalogMedia->value,
        ApiTokenAbility::CatalogRead->value,
    ]);
}
