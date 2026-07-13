<?php

use App\Enums\ApiTokenAbility;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\Route;

test('protected API middleware are declared in the required security order', function () {
    $route = Route::getRoutes()->getByName('api.v1.company.show');

    expect($route)->not->toBeNull()
        ->and($route->middleware())->toBe([
            'api',
            'auth:sanctum',
            'api.token.valid',
            'api.company.resolve',
            'api.company.member',
            'api.company.active',
            'throttle:api-authenticated',
            'api.ability:'.ApiTokenAbility::CompanyRead->value,
        ]);
});

test('public health endpoint uses the stable envelope and security headers', function () {
    $response = $this->getJson('/api/v1/health', ['X-Request-ID' => 'api-health-test']);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('X-Request-ID', 'api-health-test')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson([
            'data' => [
                'status' => 'ok',
                'service' => 'NordiPass API',
                'version' => 'v1',
            ],
            'meta' => ['request_id' => 'api-health-test'],
            'error' => null,
        ]);
});

test('me and company endpoints expose only token scoped public fields', function () {
    $user = User::factory()->create(['name' => 'API Owner']);
    $company = Company::factory()->create([
        'name' => 'Scoped Company',
        'settings' => ['secret_internal_flag' => true],
    ]);
    CompanyMembership::factory()->owner()->create([
        'company_id' => $company,
        'user_id' => $user,
    ]);
    $token = issueCompanyApiToken($user, $company, [
        ApiTokenAbility::CompanyRead->value,
        ApiTokenAbility::MembersRead->value,
    ]);
    $headers = ['Authorization' => 'Bearer '.$token->plainTextToken];

    $me = $this->getJson('/api/v1/me', $headers)->assertOk();
    $me->assertJsonPath('data.user.uuid', $user->uuid)
        ->assertJsonPath('data.company.uuid', $company->uuid)
        ->assertJsonPath('data.role', CompanyRole::Owner->value)
        ->assertJsonPath('data.abilities.0', ApiTokenAbility::CompanyRead->value)
        ->assertJsonMissingPath('data.user.id')
        ->assertJsonMissingPath('data.company.id')
        ->assertJsonStructure(['data', 'meta', 'error']);

    $companyResponse = $this->getJson('/api/v1/company', $headers)->assertOk();
    $companyResponse->assertJsonPath('data.uuid', $company->uuid)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonMissingPath('data.id')
        ->assertJsonMissingPath('data.settings')
        ->assertJsonPath('error', null);

    expect($companyResponse->json('data.created_at'))->toBeString()
        ->and($companyResponse->getContent())->not->toContain('secret_internal_flag');
});

test('company members endpoint is paginated and strictly tenant scoped', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create(['name' => 'Company A']);
    $companyB = Company::factory()->create(['name' => 'Company B']);
    CompanyMembership::factory()->owner()->create(['company_id' => $companyA, 'user_id' => $user]);

    foreach (range(1, 30) as $index) {
        CompanyMembership::factory()->viewer()->create([
            'company_id' => $companyA,
            'user_id' => User::factory()->create(['name' => "A Member {$index}"]),
        ]);
    }
    CompanyMembership::factory()->viewer()->create([
        'company_id' => $companyB,
        'user_id' => User::factory()->create(['name' => 'Foreign Member Marker']),
    ]);
    $token = issueCompanyApiToken($user, $companyA, [ApiTokenAbility::MembersRead->value]);
    $headers = ['Authorization' => 'Bearer '.$token->plainTextToken];

    $default = $this->getJson('/api/v1/company/members?company_uuid='.$companyB->uuid, $headers)
        ->assertOk();
    $default->assertJsonCount(25, 'data')
        ->assertJsonPath('meta.pagination.current_page', 1)
        ->assertJsonPath('meta.pagination.per_page', 25)
        ->assertJsonPath('meta.pagination.total', 31)
        ->assertJsonPath('meta.pagination.last_page', 2)
        ->assertJsonMissing(['id' => $user->getKey()])
        ->assertDontSee('Foreign Member Marker');

    $custom = $this->getJson('/api/v1/company/members?per_page=100&company_id='.$companyB->getKey(), $headers)
        ->assertOk();
    $custom->assertJsonCount(31, 'data')
        ->assertJsonPath('meta.pagination.per_page', 100)
        ->assertJsonPath('meta.pagination.total', 31)
        ->assertJsonMissingPath('data.0.id')
        ->assertJsonMissingPath('data.0.user.id');

    expect($custom->json('data.0.joined_at'))->toBeString();
});

test('pagination rejects values above one hundred using the API error envelope', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $user]);
    $token = issueCompanyApiToken($user, $company, [ApiTokenAbility::MembersRead->value]);

    $this->getJson('/api/v1/company/members?per_page=101', [
        'Authorization' => 'Bearer '.$token->plainTextToken,
    ])->assertUnprocessable()
        ->assertJsonPath('data', null)
        ->assertJsonPath('error.code', 'validation_error')
        ->assertJsonStructure(['data', 'meta' => ['request_id'], 'error' => ['code', 'message', 'details']]);
});

test('token abilities follow least privilege without implication', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $user]);
    $companyToken = issueCompanyApiToken($user, $company, [ApiTokenAbility::CompanyRead->value]);
    $membersToken = issueCompanyApiToken($user, $company, [ApiTokenAbility::MembersRead->value]);

    $companyHeaders = ['Authorization' => 'Bearer '.$companyToken->plainTextToken];
    $this->getJson('/api/v1/me', $companyHeaders)->assertOk();
    $this->getJson('/api/v1/company', $companyHeaders)->assertOk();
    $this->getJson('/api/v1/company/members', $companyHeaders)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'token_ability_missing');

    $memberHeaders = ['Authorization' => 'Bearer '.$membersToken->plainTextToken];
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/company/members', $memberHeaders)->assertOk();
    $this->getJson('/api/v1/me', $memberHeaders)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'token_ability_missing');
    $this->getJson('/api/v1/company', $memberHeaders)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'token_ability_missing');
});

test('bearer token company overrides neither from query nor from web session', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create(['name' => 'Token Company A']);
    $companyB = Company::factory()->create(['name' => 'Session Company B']);
    CompanyMembership::factory()->owner()->create(['company_id' => $companyA, 'user_id' => $user]);
    CompanyMembership::factory()->owner()->create(['company_id' => $companyB, 'user_id' => $user]);
    $tokenA = issueCompanyApiToken($user, $companyA, [ApiTokenAbility::CompanyRead->value]);
    $tokenB = issueCompanyApiToken($user, $companyB, [ApiTokenAbility::CompanyRead->value]);
    $this->actingAs($user);
    app(CurrentCompany::class)->set($companyB);

    $responseA = $this->getJson('/api/v1/company?company_uuid='.$companyB->uuid, [
        'Authorization' => 'Bearer '.$tokenA->plainTextToken,
    ])->assertOk();
    $responseA->assertJsonPath('data.uuid', $companyA->uuid)
        ->assertDontSee('Session Company B');

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/company', [
        'Authorization' => 'Bearer '.$tokenB->plainTextToken,
    ])->assertOk()->assertJsonPath('data.uuid', $companyB->uuid);
});

test('a web session without a bearer token cannot authenticate company scoped API endpoints', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $user]);
    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $this->getJson('/api/v1/company')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});
