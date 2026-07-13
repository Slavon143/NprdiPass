<?php

use App\Actions\Companies\RemoveCompanyMember;
use App\Enums\ApiTokenAbility;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('MySQL personal access tokens have company foreign key and tenant indexes', function () {
    expect(config('database.default'))->toBe('mysql')
        ->and(Schema::hasColumns('personal_access_tokens', [
            'company_id',
            'tokenable_id',
            'expires_at',
        ]))->toBeTrue();

    $indexes = collect(DB::select('SHOW INDEX FROM personal_access_tokens'))
        ->pluck('Key_name')
        ->unique()
        ->values()
        ->all();

    expect($indexes)->toContain('personal_access_tokens_company_id_tokenable_id_index')
        ->and($indexes)->toContain('personal_access_tokens_company_id_created_at_index');

    $foreignKeys = collect(DB::select(<<<'SQL'
        SELECT REFERENCED_TABLE_NAME, DELETE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'personal_access_tokens'
    SQL));
    expect($foreignKeys->contains(
        fn (object $key): bool => $key->REFERENCED_TABLE_NAME === 'companies'
            && $key->DELETE_RULE === 'SET NULL',
    ))->toBeTrue();
});

test('MySQL membership removal and token revoke commit atomically per company', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $companyA, 'user_id' => $owner]);
    $membership = CompanyMembership::factory()->viewer()->create([
        'company_id' => $companyA,
        'user_id' => $member,
    ]);
    CompanyMembership::factory()->viewer()->create(['company_id' => $companyB, 'user_id' => $member]);
    $tokenA = issueCompanyApiToken($member, $companyA, [ApiTokenAbility::CompanyRead->value]);
    $tokenB = issueCompanyApiToken($member, $companyB, [ApiTokenAbility::CompanyRead->value]);
    $this->actingAs($owner);
    app(CurrentCompany::class)->set($companyA);

    app(RemoveCompanyMember::class)->execute($owner, $membership);

    expect(PersonalAccessToken::query()->find($tokenA->accessToken->getKey()))->toBeNull()
        ->and(PersonalAccessToken::query()->find($tokenB->accessToken->getKey()))->not->toBeNull();
});

test('MySQL pruning finds expired company tokens without deleting active rows', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $expired = issueCompanyApiToken(
        $user,
        $company,
        [ApiTokenAbility::CompanyRead->value],
        now()->subDays(45),
    );
    $active = issueCompanyApiToken($user, $company, [ApiTokenAbility::CompanyRead->value]);

    $this->artisan('nordipass:prune-api-tokens', ['--days' => 30])->assertSuccessful();

    expect($expired->accessToken->fresh())->toBeNull()
        ->and($active->accessToken->fresh())->not->toBeNull();
});
