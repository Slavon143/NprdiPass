<?php

use App\Enums\ApiTokenAbility;
use App\Enums\CompanyStatus;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

test('request without Bearer token returns 401', function () {
    $this->getJson(apiUrl('products'))->assertUnauthorized();
});

test('invalid Bearer token returns 401', function () {
    $this->withToken('invalid-token-here-abcdef123456')
        ->getJson(apiUrl('products'))
        ->assertUnauthorized();
});

test('revoked token returns 401', function () {
    [$user, $company] = apiCatalogContext();
    $token = issueCompanyApiToken($user, $company, [ApiTokenAbility::CatalogRead->value]);
    $token->accessToken->delete();

    $this->withToken($token->plainTextToken)
        ->getJson(apiUrl('products'))
        ->assertUnauthorized();
});

test('inactive user token is denied', function () {
    [$user, $company] = apiCatalogContext();
    $user->forceFill(['status' => UserStatus::Suspended])->save();

    $this->withToken(apiToken(User::find($user->getKey()), $company, [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products'))
        ->assertStatus(401);
});

test('inactive company token is denied', function () {
    [$user, $company] = apiCatalogContext();
    $company->forceFill(['status' => CompanyStatus::Suspended])->save();

    $this->withToken(apiToken($user, Company::find($company->getKey()), [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products'))
        ->assertStatus(423);
});

test('inactive membership token is denied', function () {
    [$user, $company] = apiCatalogContext();
    CompanyMembership::query()->where('user_id', $user->getKey())
        ->where('company_id', $company->getKey())
        ->delete();

    $this->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
        ->getJson(apiUrl('products'))
        ->assertStatus(403);
});

test('token without company is denied', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $token = $user->createToken('test', [ApiTokenAbility::CatalogRead->value]);

    $this->withToken($token->plainTextToken)
        ->getJson(apiUrl('products'))
        ->assertStatus(401);
});

test('valid read token can access catalog products index', function () {
    [$user, $company] = apiCatalogContext();

    apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'products')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta']);
});
