<?php

use App\Enums\ApiTokenAbility;
use App\Models\Company;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('sanctum uses the company aware personal access token model', function () {
    expect(Sanctum::$personalAccessTokenModel)->toBe(PersonalAccessToken::class);
});

test('a personal access token stores its company abilities and expiration safely', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $newToken = issueCompanyApiToken(
        $user,
        $company,
        [ApiTokenAbility::CompanyRead->value],
        now()->addDays(30),
    );
    $token = $newToken->accessToken->fresh();

    expect($token)->toBeInstanceOf(PersonalAccessToken::class)
        ->and($token?->company?->is($company))->toBeTrue()
        ->and($token?->company_id)->toBe($company->getKey())
        ->and($token?->abilities)->toBe([ApiTokenAbility::CompanyRead->value])
        ->and($token?->expires_at)->not->toBeNull()
        ->and($token?->toArray())->not->toHaveKey('token')
        ->and($token?->isExpired())->toBeFalse();

    expect($newToken->plainTextToken)->not->toBe($token?->getRawOriginal('token'))
        ->and((string) $token?->getRawOriginal('token'))->toHaveLength(64)
        ->and(PersonalAccessToken::query()->where('token', $newToken->plainTextToken)->exists())->toBeFalse();
});

test('token expiration helper recognizes expired tokens', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $token = issueCompanyApiToken(
        $user,
        $company,
        [ApiTokenAbility::CompanyRead->value],
        now()->subMinute(),
    )->accessToken;

    expect($token->isExpired())->toBeTrue();
});
