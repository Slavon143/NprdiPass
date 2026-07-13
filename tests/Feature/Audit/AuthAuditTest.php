<?php

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;

test('successful login is a platform event with the request id', function () {
    $user = User::factory()->create(['password' => 'password']);

    $this->withHeader('X-Request-ID', 'login-request')
        ->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

    $log = AuditLog::query()->where('event', AuditEvent::AuthLogin->value)->sole();
    expect($log->company_id)->toBeNull()
        ->and($log->causer_id)->toBe($user->getKey())
        ->and($log->request_id)->toBe('login-request')
        ->and($log->getProperty('actor_email'))->toBe($user->email);
});

test('failed login stores only a normalized email and never the password', function () {
    $knownPassword = 'known-wrong-password';

    $this->withHeader('X-Request-ID', 'failed-request')
        ->post('/login', [
            'email' => '  Missing@Example.COM ',
            'password' => $knownPassword,
        ])->assertSessionHasErrors('email');

    $log = AuditLog::query()->where('event', AuditEvent::AuthLoginFailed->value)->sole();
    $serialized = $log->properties?->toJson() ?? '';

    expect($log->company_id)->toBeNull()
        ->and($log->request_id)->toBe('failed-request')
        ->and($serialized)->toContain('missing@example.com')
        ->and($serialized)->not->toContain('password')
        ->and($serialized)->not->toContain($knownPassword);
});

test('failed login audit writes are bounded per ip', function () {
    config()->set('audit.failed_login_per_minute', 5);

    foreach (range(1, 8) as $attempt) {
        $this->post('/login', [
            'email' => "missing{$attempt}@example.com",
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');
    }

    expect(AuditLog::query()
        ->where('event', AuditEvent::AuthLoginFailed->value)
        ->count())->toBe(5);
});

test('logout is tenant scoped when a current company exists and clears the selection', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $company,
        'user_id' => $user,
    ]);
    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $this->withHeader('X-Request-ID', 'logout-request')
        ->post('/logout')
        ->assertRedirect('/');

    $log = AuditLog::query()->where('event', AuditEvent::AuthLogout->value)->sole();
    expect($log->company_id)->toBe($company->getKey())
        ->and($log->request_id)->toBe('logout-request')
        ->and(session()->has(config('tenancy.session_key')))->toBeFalse();
});
