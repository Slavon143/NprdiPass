<?php

require_once __DIR__.'/Helpers.php';

use App\Actions\Fortnox\ConnectFortnoxAction;
use App\Actions\Fortnox\DisconnectFortnoxAction;
use App\Enums\AuditEvent;
use App\Enums\CompanyRole;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Models\AuditLog;
use App\Models\Fortnox\FortnoxConnection;
use App\Models\Fortnox\FortnoxOAuthState;
use Illuminate\Support\Facades\DB;

test('owner can connect fortnox through the web oauth flow with encrypted token storage', function () {
    [$actor, $company] = fortnoxContext();

    $redirect = test()->post(route('settings.integrations.fortnox.connect'));
    $redirect->assertRedirect();
    $location = (string) $redirect->headers->get('Location');
    expect($location)->toContain(route('settings.integrations.fortnox.callback'))->toContain('state=')->toContain('code=fake-code');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $state = (string) $query['state'];
    $stored = FortnoxOAuthState::query()->where('company_id', $company->getKey())->first();
    expect($stored)->not->toBeNull()
        ->and($stored->state_hash)->toBe(hash('sha256', $state));

    $callback = test()->get(route('settings.integrations.fortnox.callback', ['code' => 'fake-code', 'state' => $state]));
    $callback->assertRedirect(route('settings.integrations.fortnox.index'));

    $connection = FortnoxConnection::query()->forCompany($company)->first();
    expect($connection)->not->toBeNull()
        ->and($connection->status)->toBe(FortnoxConnectionStatus::Active)
        ->and($connection->token_payload['access_token'] ?? null)->toStartWith('fake-access-')
        ->and($connection->token_payload['refresh_token'] ?? null)->toStartWith('fake-refresh-');

    $raw = (string) DB::table('fortnox_connections')->value('token_payload');
    expect($raw)->not->toContain('fake-access-')
        ->and($raw)->not->toContain('fake-refresh-');

    $stored->refresh();
    expect($stored->consumed_at)->not->toBeNull();

    $audit = AuditLog::query()->where('company_id', $company->getKey())
        ->where('event', AuditEvent::FortnoxConnectionConnected->value)->first();
    expect($audit)->not->toBeNull();
});

test('oauth state is single use and rejected on replay', function () {
    [$actor, $company] = fortnoxContext();
    $url = app(ConnectFortnoxAction::class)->execute($actor, $company);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $state = (string) $query['state'];

    $first = test()->get(route('settings.integrations.fortnox.callback', ['code' => 'fake-code', 'state' => $state]));
    $first->assertRedirect(route('settings.integrations.fortnox.index'));
    expect(FortnoxConnection::query()->forCompany($company)->count())->toBe(1);

    $replay = test()->get(route('settings.integrations.fortnox.callback', ['code' => 'fake-code', 'state' => $state]));
    $replay->assertRedirect(route('settings.integrations.fortnox.index'));
    $replay->assertSessionHas('error');
    expect(FortnoxConnection::query()->forCompany($company)->count())->toBe(1)
        ->and(FortnoxConnection::query()->forCompany($company)->first()?->token_payload['access_token'] ?? null)->toStartWith('fake-access-');
});

test('active fortnox connection renders a working reconnect submit form', function () {
    fortnoxConnected();
    $response = $this->get(route('settings.integrations.fortnox.index'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $forms = $xpath->query('//form[@action="'.route('settings.integrations.fortnox.connect').'"]');

    expect($forms->length)->toBe(1)
        ->and($forms->item(0)->getAttribute('method'))->toBe('POST')
        ->and($xpath->query('.//button[@type="submit"]', $forms->item(0))->length)->toBe(1);
});

test('oauth state from another tenant cannot complete a connection', function () {
    [$owner, $first] = fortnoxContext();
    $url = app(ConnectFortnoxAction::class)->execute($owner, $first);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $state = (string) $query['state'];

    app('auth')->forgetGuards();
    [$otherActor, $otherCompany] = fortnoxContext();
    expect($otherCompany->is($first))->toBeFalse();

    $response = test()->get(route('settings.integrations.fortnox.callback', ['code' => 'fake-code', 'state' => $state]));
    $response->assertRedirect(route('settings.integrations.fortnox.index'));
    $response->assertSessionHas('error');

    expect(FortnoxConnection::query()->forCompany($otherCompany)->exists())->toBeFalse()
        ->and(FortnoxConnection::query()->forCompany($first)->exists())->toBeFalse();
});

test('expired oauth state is rejected', function () {
    [$actor, $company] = fortnoxContext();
    $url = app(ConnectFortnoxAction::class)->execute($actor, $company);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $state = (string) $query['state'];

    FortnoxOAuthState::query()->where('company_id', $company->getKey())->update(['expires_at' => now()->subMinute()]);

    $response = test()->get(route('settings.integrations.fortnox.callback', ['code' => 'fake-code', 'state' => $state]));
    $response->assertRedirect(route('settings.integrations.fortnox.index'));
    $response->assertSessionHas('error');
    expect(FortnoxConnection::query()->forCompany($company)->exists())->toBeFalse();
});

test('viewers and editors cannot manage fortnox', function (CompanyRole $role) {
    [$actor, $company] = fortnoxContext($role);

    $response = test()->post(route('settings.integrations.fortnox.connect'));
    $response->assertForbidden();
})->with([CompanyRole::Viewer, CompanyRole::Editor]);

test('disconnect revokes tokens and keeps the connection row', function () {
    [$actor, $company, $connection] = fortnoxConnected();

    $response = test()->post(route('settings.integrations.fortnox.disconnect'));
    $response->assertRedirect();

    $connection->refresh();
    expect($connection->status)->toBe(FortnoxConnectionStatus::Revoked)
        ->and($connection->token_payload)->toBeNull()
        ->and($connection->token_expires_at)->toBeNull()
        ->and(FortnoxConnection::query()->forCompany($company)->count())->toBe(1);

    $audit = AuditLog::query()->where('company_id', $company->getKey())
        ->where('event', AuditEvent::FortnoxConnectionDisconnected->value)->first();
    expect($audit)->not->toBeNull();
});

test('reconnect reactivates the revoked connection row', function () {
    [$actor, $company, $connection] = fortnoxConnected();
    app(DisconnectFortnoxAction::class)->execute($actor, $company);

    $url = app(ConnectFortnoxAction::class)->execute($actor, $company);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $response = test()->get(route('settings.integrations.fortnox.callback', ['code' => 'fake-code', 'state' => (string) $query['state']]));
    $response->assertRedirect(route('settings.integrations.fortnox.index'));

    $connection->refresh();
    expect(FortnoxConnection::query()->forCompany($company)->count())->toBe(1)
        ->and($connection->status)->toBe(FortnoxConnectionStatus::Active);
});

test('fortnox routes are hidden when the feature flag is off', function () {
    [$actor, $company] = fortnoxContext();
    config(['features.fortnox_integration' => false]);

    test()->get(route('settings.integrations.fortnox.index'))->assertNotFound();
    test()->post(route('settings.integrations.fortnox.connect'))->assertNotFound();
});
