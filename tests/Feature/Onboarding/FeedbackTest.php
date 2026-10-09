<?php

use App\Actions\Onboarding\SubmitFeedbackAction;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Platform\PlatformIncident;
use App\Models\User;
use App\Services\Platform\TenantHealthService;
use App\Tenancy\Contracts\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

function feedbackTenant(): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->viewer()->create(['user_id' => $user->id, 'company_id' => $company->id]);
    test()->actingAs($user);
    app(CurrentCompany::class)->set($company);

    return [$user, $company];
}

function feedbackInput(): array
{
    return ['submission_id' => Str::uuid()->toString(), 'category' => 'bug', 'subject' => 'Problem with a product image', 'message' => 'The image does not display as expected.'];
}

test('viewer feedback is tenant scoped, idempotent and audited without message text', function () {
    [$user, $company] = feedbackTenant();
    $foreign = Company::factory()->create();
    $input = feedbackInput() + ['company_id' => $foreign->id, 'reported_by' => 999];
    $this->post(route('onboarding.feedback.store'), $input)->assertRedirect(route('onboarding.support'));
    $this->post(route('onboarding.feedback.store'), $input)->assertRedirect(route('onboarding.support'));
    $item = PlatformIncident::query()->sole();
    expect($item->company_id)->toBe($company->id)->and($item->reported_by)->toBe($user->id);
    $audit = AuditLog::query()->where('event', AuditEvent::FeedbackSubmitted->value)->sole();
    expect($audit->company_id)->toBe($company->id)
        ->and(json_encode($audit->properties))->not->toContain($input['message'])
        ->and(app(TenantHealthService::class)->snapshot($company)['status'])->toBe('healthy');
    $this->get(route('onboarding.support'))->assertOk()->assertSee($input['subject'])->assertSee('Received');
});

test('feedback history excludes other users, other companies and operational incidents', function () {
    [$user, $company] = feedbackTenant();
    $other = User::factory()->create();
    $foreign = Company::factory()->create();
    foreach ([[$company->id, $other->id, 'customer_feedback'], [$foreign->id, $user->id, 'customer_feedback'], [$company->id, $user->id, 'service_incident']] as [$companyId, $userId, $type]) {
        PlatformIncident::query()->create(['company_id' => $companyId, 'reported_by' => $userId, 'type' => $type, 'title' => 'Hidden confidential message']);
    }
    $this->get(route('onboarding.support'))->assertOk()->assertDontSee('Hidden confidential message');
});

test('feedback validation rejects blank messages and unknown categories', function () {
    feedbackTenant();
    $this->post(route('onboarding.feedback.store'), array_replace(feedbackInput(), ['message' => ' ', 'category' => 'critical']))
        ->assertSessionHasErrors(['message', 'category']);
    expect(PlatformIncident::query()->count())->toBe(0);
});

test('feedback nonce cannot overwrite a submitted or foreign message', function () {
    [$user, $company] = feedbackTenant();
    $input = feedbackInput();
    $this->post(route('onboarding.feedback.store'), $input)->assertRedirect();
    $this->post(route('onboarding.feedback.store'), array_replace($input, ['message' => 'A different message must use a new form.']))->assertSessionHasErrors('submission_id');
    $foreign = Company::factory()->create();
    $foreignId = Str::uuid()->toString();
    $foreignMessage = PlatformIncident::query()->create(['company_id' => $foreign->id, 'reported_by' => $user->id, 'type' => 'customer_feedback', 'title' => 'Foreign secret']);
    $foreignMessage->forceFill(['uuid' => $foreignId])->save();
    $this->post(route('onboarding.feedback.store'), array_replace($input, ['submission_id' => $foreignId]))->assertSessionHasErrors('submission_id');
    expect(PlatformIncident::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('failed feedback audit rolls the message back', function () {
    [$user, $company] = feedbackTenant();
    $this->mock(AuditLogger::class)->shouldReceive('logTenant')->once()->andThrow(new RuntimeException('Audit unavailable'));
    $input = feedbackInput();
    expect(fn () => app(SubmitFeedbackAction::class)->execute($user, $company, $input['submission_id'], $input['category'], $input['subject'], $input['message']))
        ->toThrow(RuntimeException::class);
    expect(PlatformIncident::query()->count())->toBe(0);
});

test('feedback is escaped and requests are rate limited', function () {
    feedbackTenant();
    $input = array_replace(feedbackInput(), ['message' => '<script>alert("test")</script>']);
    $this->post(route('onboarding.feedback.store'), $input)->assertRedirect();
    $this->get(route('onboarding.support'))->assertDontSee($input['message'], false)->assertSee($input['message']);
    for ($i = 0; $i < 4; $i++) {
        $this->post(route('onboarding.feedback.store'), feedbackInput())->assertRedirect();
    }
    $this->post(route('onboarding.feedback.store'), feedbackInput())->assertStatus(429);
});

test('platform feedback inbox requires platform permission and exposes received messages', function () {
    [$sender, $company] = feedbackTenant();
    $input = feedbackInput();
    $this->post(route('onboarding.feedback.store'), $input)->assertRedirect();
    $this->get(route('platform.operations.feedback'))->assertForbidden();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole(PlatformRole::SuperAdmin->value);
    $this->actingAs($admin)->get(route('platform.operations.feedback'))->assertOk()->assertSee($input['message']);
    $item = PlatformIncident::query()->sole();
    $this->post(route('platform.operations.incidents.resolve', $item))->assertRedirect();
    $this->actingAs($sender);
    app(CurrentCompany::class)->set($company);
    $this->get(route('onboarding.support'))->assertOk()->assertSee('Resolved');
});

test('platform administrator cannot submit feedback for a company without membership', function () {
    [, $company] = feedbackTenant();
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole(PlatformRole::SuperAdmin->value);
    $input = feedbackInput();
    expect(fn () => app(SubmitFeedbackAction::class)->execute($admin, $company, $input['submission_id'], $input['category'], $input['subject'], $input['message']))
        ->toThrow(AuthorizationException::class);
    expect(PlatformIncident::query()->count())->toBe(0);
});
