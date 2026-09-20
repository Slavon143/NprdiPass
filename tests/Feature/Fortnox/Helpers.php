<?php

use App\Actions\Fortnox\CompleteFortnoxCallbackAction;
use App\Actions\Fortnox\ConnectFortnoxAction;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Fortnox\FortnoxConnection;
use App\Models\User;
use App\Services\Fortnox\FakeFortnoxProvider;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{User, Company} */
function fortnoxContext(CompanyRole $role = CompanyRole::Owner, bool $acting = true): array
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'user_id' => $actor->getKey(),
        'company_id' => $company->getKey(),
        'role' => $role,
    ]);

    if ($acting) {
        test()->actingAs($actor);
    }
    app(CurrentCompany::class)->set($company);

    return [$actor, $company];
}

function fortnoxFake(): FakeFortnoxProvider
{
    $provider = app(FakeFortnoxProvider::class);
    assert($provider instanceof FakeFortnoxProvider);

    return $provider;
}

/**
 * Connect through the real OAuth state flow and return the fresh connection.
 *
 * @param  list<array<string, mixed>>  $articles
 * @return array{User, Company, FortnoxConnection}
 */
function fortnoxConnected(array $articles = []): array
{
    [$actor, $company] = fortnoxContext();
    $url = app(ConnectFortnoxAction::class)->execute($actor, $company);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $state = (string) ($query['state'] ?? '');

    fortnoxFake()->seedArticles($articles);

    $connection = app(CompleteFortnoxCallbackAction::class)
        ->execute($actor, $company, $state, 'fake-code');

    return [$actor, $company, $connection];
}
