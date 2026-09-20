<?php

use App\Enums\AuditEvent;
use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\Webhooks\WebhookDelivery;
use App\Models\Webhooks\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function r16User(string $password = 'password'): User
{
    return User::factory()->create(['password' => $password, 'email_verified_at' => now()]);
}

function r16Membership(User $user, Company $company, CompanyRole $role): CompanyMembership
{
    return CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'role' => $role,
        'is_owner' => $role === CompanyRole::Owner,
    ]);
}

test('GDPR export requires the account password', function () {
    $user = r16User('correct-horse');

    $this->actingAs($user)
        ->post(route('profile.export'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password');

    expect(AuditLog::query()->where('event', AuditEvent::GdprDataExport->value)->exists())->toBeFalse();
});

test('GDPR export returns personal data without foreign tenant data', function () {
    $user = r16User('correct-horse');
    $company = Company::factory()->create(['name' => 'Own Company']);
    r16Membership($user, $company, CompanyRole::Owner);

    $foreign = Company::factory()->create(['name' => 'Foreign Company']);
    $foreignUser = r16User();
    r16Membership($foreignUser, $foreign, CompanyRole::Owner);

    $response = $this->actingAs($user)
        ->post(route('profile.export'), ['password' => 'correct-horse']);

    $response->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="nordipass-data-export.json"');

    $export = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

    expect($export['format'])->toBe('nordipass-gdpr-export')
        ->and($export['profile']['email'])->toBe($user->email)
        ->and($export['memberships'][0]['company'])->toBe('Own Company')
        ->and($response->getContent())->not->toContain('Foreign Company');

    $audit = AuditLog::query()->where('event', AuditEvent::GdprDataExport->value)->first();

    expect($audit)->not->toBeNull();
});

test('erasure is blocked while the user is the last owner of a company', function () {
    $user = r16User('correct-horse');
    $company = Company::factory()->create();
    r16Membership($user, $company, CompanyRole::Owner);
    $member = r16User();
    r16Membership($member, $company, CompanyRole::Viewer);

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'correct-horse'])
        ->assertRedirect();

    expect(User::query()->find($user->id))->not->toBeNull()
        ->and(User::query()->withTrashed()->find($user->id)?->deleted_at)->toBeNull();
});

test('erasure anonymizes personal data, revokes tokens, and removes memberships', function () {
    $user = r16User('correct-horse');
    $company = Company::factory()->create();
    r16Membership($user, $company, CompanyRole::Viewer);
    $owner = r16User();
    r16Membership($owner, $company, CompanyRole::Owner);

    $user->createToken('api-token', ['catalog.read']);

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'correct-horse'])
        ->assertRedirect('/');

    $erased = User::query()->withTrashed()->find($user->id);

    expect($erased)->not->toBeNull()
        ->and($erased->deleted_at)->not->toBeNull()
        ->and($erased->email)->toStartWith('erased-')
        ->and($erased->name)->toBe('Deleted user')
        ->and(CompanyMembership::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->exists())->toBeFalse();

    $audit = AuditLog::query()->where('event', AuditEvent::GdprErasure->value)->first();

    expect($audit)->not->toBeNull();
});

test('privacy purge hard-deletes erased users past retention and prunes old webhook deliveries', function () {
    $oldUser = r16User();
    $oldUser->delete();
    $oldUser->forceFill(['deleted_at' => now()->subDays(60)])->save();

    $recentUser = r16User();
    $recentUser->delete();

    $company = Company::factory()->create();
    $endpoint = WebhookEndpoint::query()->create([
        'company_id' => $company->id,
        'url' => 'https://hooks.example.com/passport',
        'secret' => 'webhook-secret',
        'events' => ['passport.published'],
    ]);
    $oldDelivery = WebhookDelivery::query()->create([
        'company_id' => $company->id,
        'webhook_endpoint_id' => $endpoint->id,
        'event_type' => 'passport.published',
        'payload' => [],
        'status' => 'delivered',
        'idempotency_key' => 'old-delivery',
    ]);
    $oldDelivery->forceFill(['created_at' => now()->subDays(120)])->save();
    WebhookDelivery::query()->create([
        'company_id' => $company->id,
        'webhook_endpoint_id' => $endpoint->id,
        'event_type' => 'passport.published',
        'payload' => [],
        'status' => 'delivered',
        'idempotency_key' => 'recent-delivery',
    ]);

    $this->artisan('privacy:purge')->assertExitCode(0);

    expect(User::query()->withTrashed()->whereKey($oldUser->id)->exists())->toBeFalse()
        ->and(User::query()->withTrashed()->whereKey($recentUser->id)->exists())->toBeTrue()
        ->and(WebhookDelivery::query()->where('idempotency_key', 'old-delivery')->exists())->toBeFalse()
        ->and(WebhookDelivery::query()->where('idempotency_key', 'recent-delivery')->exists())->toBeTrue();
});
