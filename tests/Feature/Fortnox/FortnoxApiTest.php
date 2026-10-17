<?php

require_once __DIR__.'/Helpers.php';

use App\Actions\Fortnox\CompleteFortnoxCallbackAction;
use App\Actions\Fortnox\ConnectFortnoxAction;
use App\Enums\ApiTokenAbility;
use App\Models\Catalog\Product;
use App\Models\Fortnox\FortnoxSyncConflict;
use App\Models\Fortnox\FortnoxSyncRun;
use App\Services\Fortnox\FakeFortnoxProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fortnoxApiUrl(string $path): string
{
    return '/api/v1/fortnox/'.$path;
}

function fortnoxApiConnected(): array
{
    [$user, $company] = fortnoxContext();
    $url = app(ConnectFortnoxAction::class)->execute($user, $company);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    app(CompleteFortnoxCallbackAction::class)->execute($user, $company, (string) $query['state'], 'fake-code');
    app('auth')->forgetGuards();

    return [$user, $company];
}

test('connection status is exposed through the fortnox read ability', function () {
    [$user, $company] = fortnoxApiConnected();

    test()->withToken(issueCompanyApiToken($user, $company, [ApiTokenAbility::FortnoxRead->value])->plainTextToken)
        ->getJson(fortnoxApiUrl('connection'))
        ->assertOk()
        ->assertJsonPath('data.connected', true)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonMissingPath('data.token_payload');
});

test('manual sync runs through the fortnox write ability and is readable', function () {
    [$user, $company] = fortnoxApiConnected();
    app(FakeFortnoxProvider::class)->seedArticles([[
        'id' => '1',
        'number' => 'API-1',
        'name' => 'API Product',
        'lastModified' => now()->subMinutes(5)->toIso8601String(),
    ]]);

    test()->withToken(issueCompanyApiToken($user, $company, [ApiTokenAbility::FortnoxWrite->value])->plainTextToken)
        ->postJson(fortnoxApiUrl('syncs'))
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.articles_created', 1)
        ->assertJsonPath('data.sync_mode', 'full')
        ->assertJsonPath('data.pages_fetched', 1)
        ->assertJsonPath('data.pagination_complete', true)
        ->assertJsonPath('data.next_page', null);

    app('auth')->forgetGuards();
    test()->withToken(issueCompanyApiToken($user, $company, [ApiTokenAbility::FortnoxRead->value])->plainTextToken)
        ->getJson(fortnoxApiUrl('syncs'))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $uuid = FortnoxSyncRun::query()->forCompany($company)->sole()->uuid;
    app('auth')->forgetGuards();
    test()->withToken(issueCompanyApiToken($user, $company, [ApiTokenAbility::FortnoxRead->value])->plainTextToken)
        ->getJson(fortnoxApiUrl("syncs/{$uuid}"))
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.conflicts', []);
});

test('fortnox API requires dedicated abilities', function () {
    [$user, $company] = fortnoxApiConnected();

    test()->withToken(issueCompanyApiToken($user, $company, [ApiTokenAbility::CatalogWrite->value])->plainTextToken)
        ->postJson(fortnoxApiUrl('syncs'))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'token_ability_missing');

    test()->withToken(issueCompanyApiToken($user, $company, [ApiTokenAbility::CatalogRead->value])->plainTextToken)
        ->getJson(fortnoxApiUrl('connection'))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'token_ability_missing');
});

test('fortnox API resources are hidden across tenants', function () {
    [$user, $company] = fortnoxApiConnected();
    app(FakeFortnoxProvider::class)->seedArticles([[
        'id' => '1',
        'number' => 'API-1',
        'name' => 'API Product',
        'lastModified' => now()->subMinutes(5)->toIso8601String(),
    ]]);
    test()->withToken(issueCompanyApiToken($user, $company, [ApiTokenAbility::FortnoxWrite->value])->plainTextToken)
        ->postJson(fortnoxApiUrl('syncs'))->assertOk();
    $uuid = FortnoxSyncRun::query()->forCompany($company)->sole()->uuid;

    app('auth')->forgetGuards();
    [$otherUser, $otherCompany] = fortnoxContext(acting: false);

    test()->withToken(issueCompanyApiToken($otherUser, $otherCompany, [ApiTokenAbility::FortnoxRead->value])->plainTextToken)
        ->getJson(fortnoxApiUrl("syncs/{$uuid}"))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'resource_not_found');

    test()->withToken(issueCompanyApiToken($otherUser, $otherCompany, [ApiTokenAbility::FortnoxRead->value])->plainTextToken)
        ->getJson(fortnoxApiUrl('syncs'))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    test()->withToken(issueCompanyApiToken($otherUser, $otherCompany, [ApiTokenAbility::FortnoxRead->value])->plainTextToken)
        ->getJson(fortnoxApiUrl('mappings'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('fortnox API is hidden when the feature flag is off', function () {
    [$user, $company] = fortnoxApiConnected();
    config(['features.fortnox_integration' => false]);

    test()->withToken(issueCompanyApiToken($user, $company, [ApiTokenAbility::FortnoxRead->value])->plainTextToken)
        ->getJson(fortnoxApiUrl('connection'))
        ->assertNotFound();
});

test('sync without a connection returns a validation error', function () {
    [$user, $company] = fortnoxContext();

    test()->withToken(issueCompanyApiToken($user, $company, [ApiTokenAbility::FortnoxWrite->value])->plainTextToken)
        ->postJson(fortnoxApiUrl('syncs'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_error');
});

test('conflicts are listed and resolved through the API', function () {
    [$user, $company] = fortnoxApiConnected();
    app(FakeFortnoxProvider::class)->seedArticles([[
        'id' => '1',
        'number' => 'API-1',
        'name' => 'First Name',
        'lastModified' => now()->subMinutes(5)->toIso8601String(),
    ]]);
    test()->withToken(issueCompanyApiToken($user, $company, [ApiTokenAbility::FortnoxWrite->value])->plainTextToken)
        ->postJson(fortnoxApiUrl('syncs'))->assertOk();

    $product = Product::query()->forCompany($company)->sole();
    $product->forceFill(['name' => 'Local Edit'])->save();
    app(FakeFortnoxProvider::class)->seedArticles([[
        'id' => '1',
        'number' => 'API-1',
        'name' => 'Second Name',
        'lastModified' => now()->addMinute()->toIso8601String(),
    ]]);
    test()->withToken(issueCompanyApiToken($user, $company, [ApiTokenAbility::FortnoxWrite->value])->plainTextToken)
        ->postJson(fortnoxApiUrl('syncs'))->assertOk()->assertJsonPath('data.conflicts_raised', 1);

    $conflict = FortnoxSyncConflict::query()->forCompany($company)->sole();
    app('auth')->forgetGuards();
    test()->withToken(issueCompanyApiToken($user, $company, [ApiTokenAbility::FortnoxRead->value])->plainTextToken)
        ->getJson(fortnoxApiUrl('conflicts'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.field', 'name')
        ->assertJsonPath('data.0.status', 'open');

    app('auth')->forgetGuards();
    test()->withToken(issueCompanyApiToken($user, $company, [ApiTokenAbility::FortnoxWrite->value])->plainTextToken)
        ->postJson(fortnoxApiUrl("conflicts/{$conflict->uuid}/resolve"), ['resolution' => 'fortnox'])
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved_fortnox');

    expect($product->fresh()->name)->toBe('Second Name');
});
