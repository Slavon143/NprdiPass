<?php

use App\Enums\ApiTokenAbility;
use App\Enums\AuditEvent;
use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;

function signInForApiTokenManagement(CompanyRole $role = CompanyRole::Owner): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'company_id' => $company,
        'user_id' => $user,
        'role' => $role,
    ]);
    test()->actingAs($user);
    app(CurrentCompany::class)->set($company);

    return [$user, $company];
}

test('owner and admin can open token management and create a company scoped token', function (CompanyRole $role) {
    [$user, $company] = signInForApiTokenManagement($role);
    $foreignCompany = Company::factory()->create();

    $this->get(route('settings.api-tokens.index'))
        ->assertOk()
        ->assertSee('API tokens')
        ->assertSee(ApiTokenAbility::CompanyRead->value)
        ->assertSee(ApiTokenAbility::MembersRead->value);

    $response = $this->post(route('settings.api-tokens.store'), [
        'name' => 'Warehouse sync',
        'abilities' => [ApiTokenAbility::CompanyRead->value],
        'expiration' => '90_days',
        'company_id' => $foreignCompany->getKey(),
    ]);

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee('This secret is shown only once');
    $token = PersonalAccessToken::query()->sole();
    preg_match('/<code[^>]*id="plain-api-token"[^>]*>([^<]+)<\/code>/', $response->getContent(), $matches);
    $plainTextToken = html_entity_decode($matches[1] ?? '');

    expect($token->company_id)->toBe($company->getKey())
        ->and($token->tokenable?->is($user))->toBeTrue()
        ->and($plainTextToken)->not->toBe('')
        ->and($plainTextToken)->not->toBe($token->getRawOriginal('token'));

    $audit = AuditLog::query()->where('event', AuditEvent::ApiTokenCreated->value)->sole();
    expect($audit->company_id)->toBe($company->getKey())
        ->and($audit->properties->toJson())->not->toContain($plainTextToken)
        ->and($audit->getProperty('abilities'))->toBe([ApiTokenAbility::CompanyRead->value]);

    $this->get(route('settings.api-tokens.index'))
        ->assertOk()
        ->assertSee('Warehouse sync')
        ->assertDontSee($plainTextToken);
})->with([
    'owner' => [CompanyRole::Owner],
    'admin' => [CompanyRole::Admin],
]);

test('editor and viewer cannot manage API tokens', function (CompanyRole $role) {
    signInForApiTokenManagement($role);

    $this->get(route('settings.api-tokens.index'))->assertForbidden();
    $this->post(route('settings.api-tokens.store'), [
        'name' => 'Denied',
        'abilities' => [ApiTokenAbility::CompanyRead->value],
        'expiration' => '90_days',
    ])->assertForbidden();

    expect(PersonalAccessToken::query()->count())->toBe(0);
})->with([
    'editor' => [CompanyRole::Editor],
    'viewer' => [CompanyRole::Viewer],
]);

test('token creation rejects invalid abilities and disabled non expiring tokens', function () {
    signInForApiTokenManagement();

    $this->from(route('settings.api-tokens.index'))->post(route('settings.api-tokens.store'), [
        'name' => 'Invalid ability',
        'abilities' => ['products.write', '*'],
        'expiration' => '90_days',
    ])->assertRedirect(route('settings.api-tokens.index'))->assertSessionHasErrors('abilities.0');

    $this->from(route('settings.api-tokens.index'))->post(route('settings.api-tokens.store'), [
        'name' => 'Never',
        'abilities' => [ApiTokenAbility::CompanyRead->value],
        'expiration' => 'never',
    ])->assertRedirect(route('settings.api-tokens.index'))->assertSessionHasErrors('expiration');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('token list is tenant scoped and never renders token hashes', function () {
    [$user, $company] = signInForApiTokenManagement();
    $otherCompany = Company::factory()->create();
    $visible = issueCompanyApiToken($user, $company, [ApiTokenAbility::CompanyRead->value], name: 'Visible token');
    $hidden = issueCompanyApiToken($user, $otherCompany, [ApiTokenAbility::CompanyRead->value], name: 'Foreign token');

    $this->get(route('settings.api-tokens.index'))
        ->assertOk()
        ->assertSee('Visible token')
        ->assertDontSee('Foreign token')
        ->assertDontSee((string) $visible->accessToken->getRawOriginal('token'))
        ->assertDontSee((string) $hidden->accessToken->getRawOriginal('token'));
});

test('owner and admin can revoke only a token in the current company', function (CompanyRole $role) {
    [$user, $company] = signInForApiTokenManagement($role);
    $otherCompany = Company::factory()->create();
    $token = issueCompanyApiToken($user, $company, [ApiTokenAbility::CompanyRead->value], name: 'Revoke me');
    $otherToken = issueCompanyApiToken($user, $otherCompany, [ApiTokenAbility::CompanyRead->value], name: 'Keep me');

    $this->delete(route('settings.api-tokens.destroy', ['token' => $otherToken->accessToken->getKey()]))
        ->assertNotFound();
    $this->delete(route('settings.api-tokens.destroy', ['token' => $token->accessToken->getKey()]))
        ->assertRedirect(route('settings.api-tokens.index'));

    expect($token->accessToken->fresh())->toBeNull()
        ->and($otherToken->accessToken->fresh())->not->toBeNull()
        ->and($user->fresh())->not->toBeNull();
    $audit = AuditLog::query()->where('event', AuditEvent::ApiTokenRevoked->value)->sole();
    expect($audit->company_id)->toBe($company->getKey())
        ->and($audit->getProperty('token_name'))->toBe('Revoke me');
})->with([
    'owner' => [CompanyRole::Owner],
    'admin' => [CompanyRole::Admin],
]);

test('editor and viewer cannot revoke tokens', function (CompanyRole $role) {
    [$user, $company] = signInForApiTokenManagement($role);
    $token = issueCompanyApiToken($user, $company, [ApiTokenAbility::CompanyRead->value]);

    $this->delete(route('settings.api-tokens.destroy', ['token' => $token->accessToken->getKey()]))
        ->assertForbidden();
    expect($token->accessToken->fresh())->not->toBeNull();
})->with([
    'editor' => [CompanyRole::Editor],
    'viewer' => [CompanyRole::Viewer],
]);
