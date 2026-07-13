<?php

use App\Actions\Companies\RemoveCompanyMember;
use App\Audit\AuditLogger;
use App\Enums\ApiTokenAbility;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;

test('membership removal revokes only tokens for the removed user and company', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $actor]);
    $targetMembership = CompanyMembership::factory()->viewer()->create([
        'company_id' => $company,
        'user_id' => $target,
    ]);
    CompanyMembership::factory()->viewer()->create([
        'company_id' => $otherCompany,
        'user_id' => $target,
    ]);
    $companyToken = issueCompanyApiToken($target, $company, [ApiTokenAbility::CompanyRead->value]);
    $otherToken = issueCompanyApiToken($target, $otherCompany, [ApiTokenAbility::CompanyRead->value]);
    $actorToken = issueCompanyApiToken($actor, $company, [ApiTokenAbility::CompanyRead->value]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    app(RemoveCompanyMember::class)->execute($actor, $targetMembership);

    expect($targetMembership->fresh())->toBeNull()
        ->and($companyToken->accessToken->fresh())->toBeNull()
        ->and($otherToken->accessToken->fresh())->not->toBeNull()
        ->and($actorToken->accessToken->fresh())->not->toBeNull()
        ->and($target->fresh())->not->toBeNull();
    expect(AuditLog::query()->where('event', AuditEvent::ApiTokenRevoked->value)->count())->toBe(1);

    $this->getJson('/api/v1/company', [
        'Authorization' => 'Bearer '.$companyToken->plainTextToken,
    ])->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/company', [
        'Authorization' => 'Bearer '.$otherToken->plainTextToken,
    ])->assertOk()->assertJsonPath('data.uuid', $otherCompany->uuid);
});

test('audit failure rolls back membership and token revocation together', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $actor]);
    $membership = CompanyMembership::factory()->viewer()->create([
        'company_id' => $company,
        'user_id' => $target,
    ]);
    $token = issueCompanyApiToken($target, $company, [ApiTokenAbility::CompanyRead->value]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $logger = Mockery::mock(AuditLogger::class);
    $logger->shouldReceive('logTenant')->once()->andThrow(new RuntimeException('audit unavailable'));
    $this->app->instance(AuditLogger::class, $logger);

    expect(fn () => app(RemoveCompanyMember::class)->execute($actor, $membership))
        ->toThrow(RuntimeException::class, 'audit unavailable');

    expect($membership->fresh())->not->toBeNull()
        ->and($token->accessToken->fresh())->not->toBeNull();
});
