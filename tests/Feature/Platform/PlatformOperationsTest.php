<?php

use App\Enums\AuditEvent;
use App\Enums\Jobs\JobStatusState;
use App\Enums\Platform\PlatformIncidentSeverity;
use App\Enums\Platform\PlatformIncidentStatus;
use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Jobs\JobStatus;
use App\Models\Platform\FeatureFlagOverride;
use App\Models\Platform\PlatformIncident;
use App\Models\Platform\SupportAccessGrant;
use App\Models\User;
use App\Services\Platform\FeatureFlagManager;
use App\Services\Platform\TenantHealthService;
use Database\Seeders\RolePermissionSeeder;

function platformUser(): User
{
    test()->seed(RolePermissionSeeder::class);

    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(PlatformRole::SuperAdmin->value);

    return $user;
}

test('platform operations require explicit platform permission', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->get(route('platform.operations.index'))
        ->assertForbidden();

    $this->actingAs(platformUser())
        ->get(route('platform.operations.index'))
        ->assertOk();
});

test('support access grant is time boxed and audited without tenant membership bypass', function () {
    $actor = platformUser();
    $company = Company::factory()->create(['name' => 'Support Target']);

    $this->actingAs($actor)
        ->post(route('platform.operations.support-grants.store', $company), [
            'reason' => 'Investigating failed import for customer support ticket.',
            'minutes' => 60,
        ])
        ->assertRedirect();

    $grant = SupportAccessGrant::query()->where('company_id', $company->getKey())->firstOrFail();

    expect($grant->expires_at->isFuture())->toBeTrue()
        ->and($actor->companies()->whereKey($company->getKey())->exists())->toBeFalse();

    $audit = AuditLog::query()
        ->where('event', AuditEvent::PlatformAction->value)
        ->where('log_name', 'platform')
        ->latest()
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->getProperty('action'))->toBe('support_access.granted')
        ->and($audit?->getProperty('company_uuid'))->toBe($company->uuid);
});

test('feature flag override is effective and audited', function () {
    $actor = platformUser();
    $company = Company::factory()->create();

    $this->actingAs($actor)
        ->post(route('platform.operations.feature-flags.update', $company), [
            'flag' => 'billing',
            'enabled' => false,
            'reason' => 'Temporarily disabling billing for incident response.',
        ])
        ->assertRedirect();

    $override = FeatureFlagOverride::query()->where('company_id', $company->getKey())->where('flag', 'billing')->firstOrFail();

    expect($override->enabled)->toBeFalse()
        ->and(app(FeatureFlagManager::class)->enabled('billing', $company))->toBeFalse();

    $audit = AuditLog::query()->where('event', AuditEvent::PlatformAction->value)->latest()->first();

    expect($audit?->getProperty('action'))->toBe('feature_flag.updated')
        ->and($audit?->getProperty('flag'))->toBe('billing');
});

test('tenant health summarizes failed jobs for one company only', function () {
    $company = Company::factory()->create();
    $foreign = Company::factory()->create();

    JobStatus::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Failed import',
        'type' => 'imports.apply',
        'queue' => 'imports',
        'status' => JobStatusState::Failed,
        'idempotency_key' => 'company-failed',
    ]);
    JobStatus::query()->create([
        'company_id' => $foreign->getKey(),
        'name' => 'Foreign failed import',
        'type' => 'imports.apply',
        'queue' => 'imports',
        'status' => JobStatusState::Failed,
        'idempotency_key' => 'foreign-failed',
    ]);

    $snapshot = app(TenantHealthService::class)->snapshot($company);

    expect($snapshot['status'])->toBe('degraded')
        ->and($snapshot['failed_jobs'])->toBe(1);
});

test('platform company page shows tenant health without exposing foreign company markers', function () {
    $actor = platformUser();
    $company = Company::factory()->create(['name' => 'Visible Ops Co']);
    Company::factory()->create(['name' => 'Foreign Hidden Ops Co']);

    $this->actingAs($actor)
        ->get(route('platform.operations.companies.show', $company))
        ->assertOk()
        ->assertSee('Visible Ops Co')
        ->assertDontSee('Foreign Hidden Ops Co');
});

test('platform operations are hidden when the feature flag is off', function () {
    config(['features.platform_operations' => false]);

    $this->actingAs(platformUser())
        ->get(route('platform.operations.index'))
        ->assertNotFound();
});

test('support access grant can be revoked and is audited', function () {
    $actor = platformUser();
    $company = Company::factory()->create();
    $grant = SupportAccessGrant::query()->create([
        'company_id' => $company->getKey(),
        'granted_by' => $actor->getKey(),
        'reason' => 'Investigating a customer support ticket.',
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($actor)
        ->post(route('platform.operations.support-grants.revoke', [$company, $grant]))
        ->assertRedirect();

    $grant->refresh();

    expect($grant->revoked_at)->not->toBeNull()
        ->and($grant->revoked_by)->toBe($actor->getKey());

    $audit = AuditLog::query()->where('event', AuditEvent::PlatformAction->value)->latest()->first();

    expect($audit?->getProperty('action'))->toBe('support_access.revoked');
});

test('platform incident can be reported and resolved with audit trail', function () {
    $actor = platformUser();
    $company = Company::factory()->create();

    $this->actingAs($actor)
        ->post(route('platform.operations.incidents.store', $company), [
            'type' => 'outage',
            'severity' => PlatformIncidentSeverity::Critical->value,
            'title' => 'Import pipeline stalled',
            'description' => 'Import jobs are failing across the tenant.',
        ])
        ->assertRedirect();

    $incident = PlatformIncident::query()->where('company_id', $company->getKey())->firstOrFail();

    expect($incident->status)->toBe(PlatformIncidentStatus::Open)
        ->and($incident->severity)->toBe(PlatformIncidentSeverity::Critical);

    $this->actingAs($actor)
        ->post(route('platform.operations.incidents.resolve', $incident))
        ->assertRedirect();

    $incident->refresh();

    expect($incident->status)->toBe(PlatformIncidentStatus::Resolved)
        ->and($incident->resolved_by)->toBe($actor->getKey());

    $audit = AuditLog::query()->where('event', AuditEvent::PlatformAction->value)->latest()->first();

    expect($audit?->getProperty('action'))->toBe('incident.resolved');
});
