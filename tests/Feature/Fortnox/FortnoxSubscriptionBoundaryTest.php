<?php

require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/../../Fixtures/FortnoxOAuthTokens.php';

use App\Actions\Fortnox\CompleteFortnoxCallbackAction;
use App\Actions\Fortnox\ConnectFortnoxAction;
use App\Enums\AuditEvent;
use App\Enums\CompanyRole;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Exceptions\Fortnox\FortnoxSubscriptionBlocked;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Fortnox\FortnoxConnection;
use App\Models\Fortnox\FortnoxOAuthState;
use App\Models\User;
use App\Services\Fortnox\Contracts\FortnoxProvider;
use App\Services\Fortnox\FortnoxSyncService;
use App\Services\Fortnox\HttpFortnoxProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    app()->detectEnvironment(fn (): string => 'testing');
    config([
        'fortnox.nonproduction_oauth_enabled' => true,
        'fortnox.nonproduction_tenant_id' => '1854146',
        'fortnox.nonproduction_client_id' => 'fixture-client',
        'fortnox.client_id' => 'fixture-client',
        'fortnox.client_secret' => 'fixture-secret',
    ]);
    app()->instance(FortnoxProvider::class, new HttpFortnoxProvider);
    Http::preventStrayRequests();
});

function fortnoxBoundaryState(User $actor, Company $company): string
{
    $url = app(ConnectFortnoxAction::class)->execute($actor, $company);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) $query['state'];
}

function fortnoxBoundaryConnection(Company $company, ?string $recordedTenant, string $tokenTenant, bool $expired = false): FortnoxConnection
{
    return FortnoxConnection::query()->create([
        'company_id' => $company->getKey(),
        'provider' => 'fortnox',
        'status' => FortnoxConnectionStatus::Active,
        'external_tenant_id' => $recordedTenant,
        'token_payload' => ['access_token' => fortnoxTenantJwt($tokenTenant), 'refresh_token' => 'fixture-refresh'],
        'token_expires_at' => $expired ? now()->subMinute() : now()->addHour(),
        'connected_at' => now()->subDay(),
    ])->refresh();
}

test('wrong subscription callback is rejected without a connection or successful audit', function (string $token) {
    [$actor, $company] = fortnoxContext();
    $state = fortnoxBoundaryState($actor, $company);
    Http::fake(['apps.fortnox.se/oauth-v1/token' => Http::response(['access_token' => $token, 'scope' => 'article'])]);

    $this->get(route('settings.integrations.fortnox.callback', [
        'state' => $state, 'code' => 'fixture-code', 'tenant_id' => '1854146', 'company_id' => 999999,
    ]))->assertRedirect(route('settings.integrations.fortnox.index'))->assertSessionHas('error');

    expect(FortnoxConnection::query()->forCompany($company)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', AuditEvent::FortnoxConnectionConnected->value)->exists())->toBeFalse()
        ->and(FortnoxOAuthState::query()->where('company_id', $company->getKey())->first()->consumed_at)->not->toBeNull();
    Http::assertSentCount(1);
})->with([
    'production' => fn () => fortnoxTenantJwt('1853390'),
    'other account' => fn () => fortnoxTenantJwt('9999999'),
    'unknown identity' => 'opaque-fixture',
]);

test('test subscription callback records tenant identity and keeps secrets encrypted and out of audit', function () {
    [$actor, $company] = fortnoxContext();
    $state = fortnoxBoundaryState($actor, $company);
    $token = fortnoxTenantJwt('1854146');
    Http::fake(['apps.fortnox.se/oauth-v1/token' => Http::response([
        'access_token' => $token, 'refresh_token' => 'fixture-refresh', 'scope' => 'article',
    ])]);

    $connection = app(CompleteFortnoxCallbackAction::class)->execute($actor, $company, $state, 'fixture-code');
    $audit = AuditLog::query()->where('event', AuditEvent::FortnoxConnectionConnected->value)->firstOrFail();
    expect($connection->external_tenant_id)->toBe('1854146')
        ->and($connection->getRawOriginal('token_payload'))->not->toContain($token)->not->toContain('fixture-refresh')
        ->and(json_encode($audit->properties))->toContain('1854146')->not->toContain($token)->not->toContain('fixture-refresh');
    Http::assertSentCount(1);
});

test('reconnect cannot overwrite an existing production connection including legacy rows', function (?string $recordedTenant) {
    [$actor, $company] = fortnoxContext();
    $connection = fortnoxBoundaryConnection($company, $recordedTenant, '1853390');
    $before = $connection->getRawOriginal();
    Http::fake();

    expect(fn () => fortnoxBoundaryState($actor, $company))->toThrow(FortnoxSubscriptionBlocked::class);
    expect($connection->fresh()->getRawOriginal())->toBe($before)
        ->and(FortnoxOAuthState::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['identified production' => '1853390', 'legacy unknown' => null]);

test('callback rechecks an existing production connection created after authorization started', function () {
    [$actor, $company] = fortnoxContext();
    $state = fortnoxBoundaryState($actor, $company);
    $connection = fortnoxBoundaryConnection($company, '1853390', '1853390');
    $before = $connection->getRawOriginal();
    Http::fake();

    expect(fn () => app(CompleteFortnoxCallbackAction::class)->execute($actor, $company, $state, 'fixture-code'))
        ->toThrow(FortnoxSubscriptionBlocked::class);
    expect($connection->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
});

test('another administrator in the same company cannot consume the initiating users OAuth state', function () {
    [$actor, $company] = fortnoxContext();
    $state = fortnoxBoundaryState($actor, $company);
    $other = User::factory()->create();
    CompanyMembership::factory()->create(['company_id' => $company->getKey(), 'user_id' => $other->getKey(), 'role' => CompanyRole::Admin]);
    Http::fake();

    expect(fn () => app(CompleteFortnoxCallbackAction::class)->execute($other, $company, $state, 'fixture-code'))
        ->toThrow(ValidationException::class);
    expect(FortnoxOAuthState::query()->first()->consumed_at)->toBeNull();
    Http::assertNothingSent();
});

test('disabled real OAuth returns a friendly error without creating state or sending a request', function () {
    fortnoxContext();
    config(['fortnox.nonproduction_oauth_enabled' => false]);
    Http::fake();

    $this->post(route('settings.integrations.fortnox.connect'))
        ->assertRedirect(route('settings.integrations.fortnox.index'))->assertSessionHas('error');
    $this->get(route('settings.integrations.fortnox.index'))->assertOk()
        ->assertSee('data-testid="fortnox-authorization-blocked"', false)
        ->assertDontSee('data-testid="fortnox-connect-form"', false);
    expect(FortnoxOAuthState::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('expired production connection cannot refresh or change status through a read operation', function () {
    [$actor, $company] = fortnoxContext();
    $connection = fortnoxBoundaryConnection($company, null, '1853390', true);
    $before = $connection->getRawOriginal();
    Http::fake();

    expect(fn () => app(FortnoxSyncService::class)->listAllArticles($actor, $company, $connection))
        ->toThrow(FortnoxSubscriptionBlocked::class);
    expect($connection->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
});

test('refresh returning a different subscription is rejected before saving credentials or reading articles', function () {
    [$actor, $company] = fortnoxContext();
    $connection = fortnoxBoundaryConnection($company, '1854146', '1854146', true);
    $before = $connection->getRawOriginal();
    Http::fake(['apps.fortnox.se/oauth-v1/token' => Http::response([
        'access_token' => fortnoxTenantJwt('1853390'), 'refresh_token' => 'changed-fixture-refresh',
    ])]);

    expect(fn () => app(FortnoxSyncService::class)->listAllArticles($actor, $company, $connection))
        ->toThrow(FortnoxSubscriptionBlocked::class);
    expect($connection->fresh()->getRawOriginal())->toBe($before);
    Http::assertSentCount(1);
});

test('existing valid test connection can read with new OAuth disabled without changing the connection', function () {
    [$actor, $company] = fortnoxContext();
    $connection = fortnoxBoundaryConnection($company, '1854146', '1854146');
    $before = $connection->getRawOriginal();
    config(['fortnox.nonproduction_oauth_enabled' => false, 'fortnox.nonproduction_client_id' => '']);
    Http::fake(['api.fortnox.se/3/articles*' => Http::response(['Articles' => []])]);

    expect(app(FortnoxSyncService::class)->listAllArticles($actor, $company, $connection))->toBe([])
        ->and($connection->fresh()->getRawOriginal())->toBe($before);
    Http::assertSentCount(1);
});

test('revoked test connection can reconnect while revoked production stays untouched', function (string $tenantId) {
    [$actor, $company] = fortnoxContext();
    $connection = fortnoxBoundaryConnection($company, $tenantId, $tenantId);
    $connection->forceFill(['status' => FortnoxConnectionStatus::Revoked, 'token_payload' => null])->save();
    $before = $connection->getRawOriginal();
    Http::fake();

    if ($tenantId === '1853390') {
        expect(fn () => fortnoxBoundaryState($actor, $company))->toThrow(FortnoxSubscriptionBlocked::class);
    } else {
        expect(fortnoxBoundaryState($actor, $company))->not->toBeEmpty();
    }
    expect($connection->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
})->with(['1854146', '1853390']);
