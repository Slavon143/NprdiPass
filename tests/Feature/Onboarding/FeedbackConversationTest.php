<?php

use App\Actions\Platform\ReplyToFeedbackAction;
use App\Audit\AuditLogger;
use App\Enums\Notifications\NotificationType;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Notifications\Notification;
use App\Models\Notifications\NotificationPreference;
use App\Models\Platform\FeedbackReply;
use App\Models\Platform\PlatformIncident;
use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use App\Tenancy\Contracts\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

function conversationFixture(): array
{
    test()->seed(RolePermissionSeeder::class);
    $sender = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->viewer()->create(['user_id' => $sender->id, 'company_id' => $company->id]);
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole(PlatformRole::SupportAdmin->value);
    $feedback = PlatformIncident::query()->create(['company_id' => $company->id, 'reported_by' => $sender->id, 'type' => 'customer_feedback', 'title' => 'Conversation test', 'description' => 'Original customer question.']);
    test()->actingAs($sender);
    app(CurrentCompany::class)->set($company);

    return [$sender, $company, $admin, $feedback];
}

test('support replies notify only the sender and customer opening the conversation marks the visible reply read', function () {
    [$sender, $company, $admin, $feedback] = conversationFixture();
    $other = User::factory()->create();
    CompanyMembership::factory()->viewer()->create(['user_id' => $other->id, 'company_id' => $company->id]);
    $input = ['submission_id' => Str::uuid()->toString(), 'message' => 'The support answer is here.'];
    $this->actingAs($admin)->post(route('platform.operations.feedback.reply', $feedback), $input)->assertRedirect(route('platform.operations.feedback.show', $feedback));
    $this->post(route('platform.operations.feedback.reply', $feedback), $input)->assertRedirect();
    $reply = FeedbackReply::query()->sole();
    expect($reply->author_type)->toBe('support')->and($reply->authored_by)->toBe($admin->id);
    $notification = Notification::query()->sole();
    expect($notification->user_id)->toBe($sender->id)->and($notification->company_id)->toBe($company->id)
        ->and($notification->read_at)->toBeNull()->and($notification->type)->toBe(NotificationType::FeedbackReply)
        ->and($notification->action_url)->toBe(route('onboarding.feedback.show', $feedback));
    $audit = AuditLog::query()->where('properties->action', 'feedback.replied')->sole();
    expect($audit->company_id)->toBeNull()->and(json_encode($audit->properties))->not->toContain($input['message']);
    $this->actingAs($sender);
    app(CurrentCompany::class)->set($company);
    $this->get($notification->action_url)->assertOk()->assertSee($input['message'])->assertSee('Support');
    expect($notification->refresh()->read_at)->not->toBeNull();
});

test('feedback viewers can read conversations but cannot post support replies', function () {
    [$sender, $company, $admin, $feedback] = conversationFixture();
    $admin->removeRole(PlatformRole::SupportAdmin->value);
    $admin->givePermissionTo(['platform.access', 'platform.feedback.view']);
    $this->actingAs($admin)->get(route('platform.operations.feedback.show', $feedback))->assertOk()->assertDontSee('feedback-reply-form');
    $this->post(route('platform.operations.feedback.reply', $feedback), ['submission_id' => Str::uuid()->toString(), 'message' => 'Read only cannot reply.'])->assertForbidden();
    expect(FeedbackReply::query()->count())->toBe(0);
});

test('new replies bring older conversations to the first page and open requests precede resolved requests', function () {
    [$sender, $company, $admin, $feedback] = conversationFixture();
    for ($i = 0; $i < 21; $i++) {
        PlatformIncident::query()->create(['company_id' => $company->id, 'reported_by' => $sender->id, 'type' => 'customer_feedback', 'title' => 'Closed request '.$i, 'status' => 'resolved']);
    }
    $this->travel(1)->minutes();
    app(ReplyToFeedbackAction::class)->support($admin, $feedback->uuid, Str::uuid()->toString(), 'Recent support answer.');
    $this->actingAs($admin)->get(route('platform.operations.feedback'))->assertOk()
        ->assertViewHas('feedback', fn ($items): bool => $items->first()->uuid === $feedback->uuid);
    $this->actingAs($sender);
    app(CurrentCompany::class)->set($company);
    $this->get(route('onboarding.support'))->assertOk()
        ->assertViewHas('feedback', fn ($items): bool => $items->first()->uuid === $feedback->uuid);
});

test('customer followup is scoped to its sender and reopens a resolved request without becoming a support reply', function () {
    [$sender, $company, $admin, $feedback] = conversationFixture();
    $feedback->forceFill(['status' => 'resolved', 'resolved_by' => $admin->id, 'resolved_at' => now()])->save();
    $this->post(route('onboarding.feedback.reply', $feedback), ['submission_id' => Str::uuid()->toString(), 'message' => 'I need another explanation.', 'author_type' => 'support', 'authored_by' => $admin->id, 'company_id' => 999])
        ->assertRedirect(route('onboarding.feedback.show', $feedback));
    expect($feedback->refresh()->status->value)->toBe('open')->and($feedback->resolved_by)->toBeNull()->and($feedback->resolved_at)->toBeNull();
    $reply = FeedbackReply::query()->sole();
    expect($reply->authored_by)->toBe($sender->id)->and($reply->author_type)->toBe('customer')->and(Notification::query()->count())->toBe(0);
    expect(AuditLog::query()->where('properties->action', 'feedback.replied')->sole()->company_id)->toBe($company->id);
    $this->actingAs($admin)->get(route('platform.operations.feedback.show', $feedback))->assertOk()->assertSee($reply->message)->assertSee('Customer');
});

test('conversation reads and replies exclude other senders and companies', function () {
    [$sender, $company, $admin, $feedback] = conversationFixture();
    $other = User::factory()->create(['email_verified_at' => now()]);
    CompanyMembership::factory()->viewer()->create(['user_id' => $other->id, 'company_id' => $company->id]);
    $input = ['submission_id' => Str::uuid()->toString(), 'message' => 'Unauthorized reply.'];
    $this->actingAs($other)->get(route('onboarding.feedback.show', $feedback))->assertNotFound();
    $this->post(route('onboarding.feedback.reply', $feedback), $input)->assertNotFound();
    $foreign = Company::factory()->create();
    CompanyMembership::factory()->viewer()->create(['user_id' => $sender->id, 'company_id' => $foreign->id]);
    $this->actingAs($sender);
    app(CurrentCompany::class)->set($foreign);
    $this->get(route('onboarding.feedback.show', $feedback))->assertNotFound();
    $this->post(route('onboarding.feedback.reply', $feedback), $input)->assertNotFound();
    expect(FeedbackReply::query()->count())->toBe(0);
});

test('support endpoints require platform permission and reject operational incidents', function () {
    [$sender, $company, $admin, $feedback] = conversationFixture();
    $input = ['submission_id' => Str::uuid()->toString(), 'message' => 'Support answer.'];
    $this->get(route('platform.operations.feedback.show', $feedback))->assertForbidden();
    $this->post(route('platform.operations.feedback.reply', $feedback), $input)->assertForbidden();
    $incident = PlatformIncident::query()->create(['company_id' => $company->id, 'type' => 'operational_failure', 'title' => 'Operational incident']);
    $this->actingAs($admin)->get(route('platform.operations.feedback.show', $incident))->assertNotFound();
    $this->post(route('platform.operations.feedback.reply', $incident), $input)->assertNotFound();
    app(CurrentCompany::class)->set($company);
    expect(fn () => app(ReplyToFeedbackAction::class)->customer($admin, $company, $feedback->uuid, $input['submission_id'], $input['message']))->toThrow(AuthorizationException::class);
    expect(FeedbackReply::query()->count())->toBe(0);
});

test('reply nonce is immutable and cannot be reused across conversations', function () {
    [$sender, $company, $admin, $feedback] = conversationFixture();
    $input = ['submission_id' => Str::uuid()->toString(), 'message' => 'First reply stays unchanged.'];
    $this->post(route('onboarding.feedback.reply', $feedback), $input)->assertRedirect();
    $this->post(route('onboarding.feedback.reply', $feedback), $input)->assertRedirect();
    $this->post(route('onboarding.feedback.reply', $feedback), array_replace($input, ['message' => 'Changed text is rejected.']))->assertSessionHasErrors('submission_id');
    $second = PlatformIncident::query()->create(['company_id' => $company->id, 'reported_by' => $sender->id, 'type' => 'customer_feedback', 'title' => 'Other request']);
    $this->post(route('onboarding.feedback.reply', $second), $input)->assertSessionHasErrors('submission_id');
    $this->actingAs($admin)->post(route('platform.operations.feedback.reply', $feedback), $input)->assertSessionHasErrors('submission_id');
    expect(FeedbackReply::query()->sole()->message)->toBe($input['message']);
    expect(AuditLog::query()->where('properties->action', 'feedback.replied')->count())->toBe(1);
});

test('reply audit and notification failures roll back replies and reopened status', function () {
    [$sender, $company, $admin, $feedback] = conversationFixture();
    $feedback->forceFill(['status' => 'resolved', 'resolved_by' => $admin->id, 'resolved_at' => now()])->save();
    $this->mock(AuditLogger::class)->shouldReceive('logTenant')->once()->andThrow(new RuntimeException('Audit unavailable'));
    expect(fn () => app(ReplyToFeedbackAction::class)->customer($sender, $company, $feedback->uuid, Str::uuid()->toString(), 'Follow up'))
        ->toThrow(RuntimeException::class);
    expect($feedback->refresh()->status->value)->toBe('resolved')->and(FeedbackReply::query()->count())->toBe(0);
    $this->app->forgetInstance(AuditLogger::class);
    $this->mock(NotificationCenter::class)->shouldReceive('notifyUser')->once()->andThrow(new RuntimeException('Notification unavailable'));
    expect(fn () => app(ReplyToFeedbackAction::class)->support($admin, $feedback->uuid, Str::uuid()->toString(), 'Support response'))
        ->toThrow(RuntimeException::class);
    expect(FeedbackReply::query()->count())->toBe(0)->and(AuditLog::query()->where('properties->action', 'feedback.replied')->count())->toBe(0);
});

test('live revocation and suspension prevent replies even after permission was cached', function () {
    [$sender, $company, $admin, $feedback] = conversationFixture();
    expect($admin->can('platform.feedback.manage'))->toBeTrue();
    $admin->removeRole(PlatformRole::SupportAdmin->value);
    expect(fn () => app(ReplyToFeedbackAction::class)->support($admin, $feedback->uuid, Str::uuid()->toString(), 'Unauthorized'))
        ->toThrow(AuthorizationException::class);
    $admin->assignRole(PlatformRole::SupportAdmin->value);
    $admin->forceFill(['status' => UserStatus::Suspended])->save();
    expect(fn () => app(ReplyToFeedbackAction::class)->support($admin, $feedback->uuid, Str::uuid()->toString(), 'Unauthorized'))
        ->toThrow(AuthorizationException::class);
    CompanyMembership::query()->where('company_id', $company->id)->where('user_id', $sender->id)->delete();
    expect(fn () => app(ReplyToFeedbackAction::class)->customer($sender, $company, $feedback->uuid, Str::uuid()->toString(), 'Unauthorized'))
        ->toThrow(AuthorizationException::class);
    expect(FeedbackReply::query()->count())->toBe(0);
});

test('notification opt out is respected while replies stay visible in the conversation', function () {
    [$sender, $company, $admin, $feedback] = conversationFixture();
    NotificationPreference::query()->create(['company_id' => $company->id, 'user_id' => $sender->id, 'type' => NotificationType::FeedbackReply, 'in_app_enabled' => false]);
    app(ReplyToFeedbackAction::class)->support($admin, $feedback->uuid, Str::uuid()->toString(), 'Visible despite notification opt out.');
    expect(Notification::query()->count())->toBe(0);
    $this->get(route('onboarding.feedback.show', $feedback))->assertOk()->assertSee('Visible despite notification opt out.');
});

test('conversation pagination only marks notifications for displayed replies read', function () {
    [$sender, $company, $admin, $feedback] = conversationFixture();
    for ($i = 0; $i < 21; $i++) {
        app(ReplyToFeedbackAction::class)->support($admin, $feedback->uuid, Str::uuid()->toString(), 'Reply number '.$i);
    }
    $this->get(route('onboarding.feedback.show', $feedback))->assertOk()->assertViewHas('replies', fn ($replies): bool => $replies->count() === 20 && ! $replies->getCollection()->contains('message', 'Reply number 0'))->assertSee('Reply number 20');
    expect(Notification::query()->whereNull('read_at')->count())->toBe(1);
    expect(Notification::query()->oldest('id')->first()->read_at)->toBeNull();
    $this->get(route('onboarding.feedback.show', $feedback).'?page=2')->assertOk()->assertSee('Reply number 0');
    expect(Notification::query()->whereNull('read_at')->count())->toBe(0);
});

test('reply text is escaped and blank oversize input is rejected', function () {
    [$sender, $company, $admin, $feedback] = conversationFixture();
    $input = ['submission_id' => Str::uuid()->toString(), 'message' => '<script>alert("test")</script>'];
    $this->post(route('onboarding.feedback.reply', $feedback), $input)->assertRedirect();
    $this->get(route('onboarding.feedback.show', $feedback))->assertOk()->assertSee($input['message'])->assertDontSee($input['message'], false);
    $this->actingAs($admin)->get(route('platform.operations.feedback.show', $feedback))->assertOk()->assertSee($input['message'])->assertDontSee($input['message'], false);
    foreach ([' ', str_repeat('a', 2001)] as $invalid) {
        $this->post(route('platform.operations.feedback.reply', $feedback), array_replace($input, ['message' => $invalid]))->assertSessionHasErrors('message');
    }
    expect(FeedbackReply::query()->count())->toBe(1);
});

test('reply posting is throttled without producing duplicates', function () {
    [$sender, $company, $admin, $feedback] = conversationFixture();
    $input = ['submission_id' => Str::uuid()->toString(), 'message' => 'One unique reply'];
    for ($i = 0; $i < 10; $i++) {
        $this->post(route('onboarding.feedback.reply', $feedback), $input)->assertRedirect();
    }
    $this->post(route('onboarding.feedback.reply', $feedback), $input)->assertStatus(429);
    expect(FeedbackReply::query()->count())->toBe(1);
});
