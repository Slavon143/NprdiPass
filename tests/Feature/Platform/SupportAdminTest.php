<?php

use App\Actions\Platform\ResolveFeedbackAction;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\PlatformPermission;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Platform\PlatformIncident;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Role;

function supportAdmin(): User
{
    test()->seed(RolePermissionSeeder::class);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(PlatformRole::SupportAdmin->value);

    return $user;
}

function supportFeedback(Company $company, string $type = 'customer_feedback'): PlatformIncident
{
    return PlatformIncident::query()->create(['company_id' => $company->id, 'type' => $type, 'title' => 'Support test', 'description' => 'Synthetic customer message']);
}

test('support role contains only platform access and feedback permissions', function () {
    $user = supportAdmin();
    expect(Role::findByName(PlatformRole::SupportAdmin->value)->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect([PlatformPermission::PlatformAccess->value, PlatformPermission::FeedbackView->value, PlatformPermission::FeedbackManage->value])->sort()->values()->all());
    $this->actingAs($user)->get(route('platform.operations.feedback'))->assertOk();
    $this->get(route('platform.operations.index'))->assertForbidden();
    $foreign = Company::factory()->create();
    $this->get(route('platform.operations.companies.show', $foreign))->assertForbidden();
    $this->post(route('platform.operations.support-grants.store', $foreign), ['reason' => 'Testing grants are denied', 'minutes' => 30])->assertForbidden();
    $this->get(route('dashboard'))->assertRedirect(route('companies.none'));
});

test('ordinary company owner cannot see or open platform feedback', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $user->id, 'company_id' => $company->id]);
    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);
    $this->get(route('dashboard'))->assertOk()->assertDontSee('navigation-platform-management')->assertDontSee('platform-feedback-count');
    $this->get(route('platform.operations.feedback'))->assertForbidden();
});

test('support navigation counts only open feedback and resolution updates the count', function () {
    $user = supportAdmin();
    $company = Company::factory()->create();
    $open = supportFeedback($company);
    supportFeedback($company, 'operational_failure');
    supportFeedback($company)->forceFill(['status' => 'resolved'])->save();
    $this->actingAs($user);
    $this->get(route('platform.operations.feedback'))->assertOk()->assertSee('navigation-platform-management')->assertSee('1 open requests')->assertDontSee('Review company');
    $this->post(route('platform.operations.feedback.resolve', $open))->assertRedirect(route('platform.operations.feedback'));
    $this->post(route('platform.operations.feedback.resolve', $open))->assertRedirect();
    expect($open->refresh()->status->value)->toBe('resolved');
    expect(AuditLog::query()->where('event', AuditEvent::PlatformAction->value)->where('properties->action', 'feedback.resolved')->count())->toBe(1);
    $this->get(route('platform.operations.feedback'))->assertOk()->assertDontSee('platform-feedback-count');
});

test('support cannot resolve operational incidents through the feedback endpoint', function () {
    $user = supportAdmin();
    $incident = supportFeedback(Company::factory()->create(), 'operational_failure');
    $this->actingAs($user)->post(route('platform.operations.feedback.resolve', $incident))->assertNotFound();
    $this->post(route('platform.operations.incidents.resolve', $incident))->assertForbidden();
    expect($incident->refresh()->status->value)->toBe('open');
});

test('feedback resolution audit failure rolls back the status', function () {
    $user = supportAdmin();
    $feedback = supportFeedback(Company::factory()->create());
    $this->mock(AuditLogger::class)->shouldReceive('logPlatform')->once()->andThrow(new RuntimeException('Audit unavailable'));
    expect(fn () => app(ResolveFeedbackAction::class)->execute($user, $feedback->uuid))->toThrow(RuntimeException::class);
    expect($feedback->refresh()->status->value)->toBe('open')->and($feedback->resolved_at)->toBeNull();
});

test('revoked support role and suspended accounts cannot resolve feedback', function () {
    $user = supportAdmin();
    $feedback = supportFeedback(Company::factory()->create());
    expect($user->can(PlatformPermission::FeedbackManage->value))->toBeTrue();
    $user->removeRole(PlatformRole::SupportAdmin->value);
    expect(fn () => app(ResolveFeedbackAction::class)->execute($user, $feedback->uuid))->toThrow(AuthorizationException::class);
    $user->assignRole(PlatformRole::SupportAdmin->value);
    $user->forceFill(['status' => UserStatus::Suspended])->save();
    $this->actingAs($user)->get(route('platform.operations.feedback'))->assertForbidden();
    expect(fn () => app(ResolveFeedbackAction::class)->execute($user, $feedback->uuid))->toThrow(AuthorizationException::class);
});
