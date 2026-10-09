<?php

use App\Enums\Jobs\JobStatusState;
use App\Enums\Notifications\NotificationSeverity;
use App\Enums\Notifications\NotificationType;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Jobs\JobStatus;
use App\Models\Notifications\Notification;
use App\Models\Notifications\NotificationPreference;
use App\Models\User;
use App\Services\Jobs\JobStatusTracker;
use App\Services\Notifications\NotificationCenter;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Str;

function signedInCompanyUser(): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
    ]);

    test()->actingAs($user);
    app(CurrentCompany::class)->set($company);

    return [$user, $company];
}

test('notification center creates idempotent tenant scoped notifications and honors preferences', function () {
    [$user, $company] = signedInCompanyUser();
    $foreignUser = User::factory()->create(['email_verified_at' => now()]);
    $foreignCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $foreignCompany->getKey(),
        'user_id' => $foreignUser->getKey(),
    ]);

    NotificationPreference::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'type' => NotificationType::ImportCompleted,
        'in_app_enabled' => false,
        'email_enabled' => true,
    ]);

    $center = app(NotificationCenter::class);

    expect($center->notifyUser(
        $company,
        $user,
        NotificationType::ImportCompleted,
        'Import complete',
        'Rows imported',
        'import:1',
    ))->toBeNull();

    $first = $center->notifyUser(
        $company,
        $user,
        NotificationType::ImportFailed,
        'Import failed',
        'Rows rejected',
        'import:2',
        NotificationSeverity::Warning,
    );
    $second = $center->notifyUser(
        $company,
        $user,
        NotificationType::ImportFailed,
        'Import failed duplicate',
        'Rows rejected duplicate',
        'import:2',
        NotificationSeverity::Warning,
    );

    Notification::query()->create([
        'company_id' => $foreignCompany->getKey(),
        'user_id' => $foreignUser->getKey(),
        'type' => NotificationType::ImportFailed,
        'preference_key' => NotificationType::ImportFailed->value,
        'severity' => NotificationSeverity::Critical,
        'title' => 'Foreign marker',
        'idempotency_key' => 'foreign:1',
    ]);

    expect($first?->is($second))->toBeTrue()
        ->and(Notification::query()->forCompany($company)->count())->toBe(1);

    $this->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('Import failed')
        ->assertDontSee('Foreign marker');
});

test('notification actions are scoped to current user and company', function () {
    [$user, $company] = signedInCompanyUser();
    $otherUser = User::factory()->create(['email_verified_at' => now()]);
    CompanyMembership::factory()->viewer()->create([
        'company_id' => $company->getKey(),
        'user_id' => $otherUser->getKey(),
    ]);

    $own = Notification::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'type' => NotificationType::BillingEvent,
        'preference_key' => NotificationType::BillingEvent->value,
        'severity' => NotificationSeverity::Info,
        'title' => 'Own billing notice',
        'idempotency_key' => 'billing:own',
    ]);
    $other = Notification::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $otherUser->getKey(),
        'type' => NotificationType::BillingEvent,
        'preference_key' => NotificationType::BillingEvent->value,
        'severity' => NotificationSeverity::Info,
        'title' => 'Other billing notice',
        'idempotency_key' => 'billing:other',
    ]);

    $this->post(route('notifications.read', $own))->assertRedirect();
    $this->post(route('notifications.read', $other))->assertNotFound();

    expect($own->refresh()->read_at)->not->toBeNull()
        ->and($other->refresh()->read_at)->toBeNull();
});

test('viewing the inbox clears the unread indicator and preserves the first read time', function () {
    [$user, $company] = signedInCompanyUser();

    $this->get(route('notifications.index'))
        ->assertOk()
        ->assertDontSee('data-testid="header-notification-unread-indicator"', false);

    $notification = Notification::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'type' => NotificationType::BillingEvent,
        'preference_key' => NotificationType::BillingEvent->value,
        'severity' => NotificationSeverity::Info,
        'title' => 'Billing notice',
        'idempotency_key' => 'billing:unread-indicator',
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-testid="header-notification-unread-indicator"', false);

    $this->get(route('notifications.index'))
        ->assertOk()
        ->assertDontSee('data-testid="header-notification-unread-indicator"', false)
        ->assertDontSee('Unread');

    $readAt = $notification->refresh()->read_at;
    expect($readAt)->not->toBeNull();

    $this->travel(5)->minutes();
    $this->get(route('notifications.index'))
        ->assertOk()
        ->assertDontSee('data-testid="header-notification-unread-indicator"', false);

    expect($notification->refresh()->read_at?->equalTo($readAt))->toBeTrue();
});

test('inbox viewing leaves unseen dismissed and other recipient notifications unread', function () {
    [$user, $company] = signedInCompanyUser();
    $otherUser = User::factory()->create(['email_verified_at' => now()]);
    CompanyMembership::factory()->viewer()->create([
        'company_id' => $company->getKey(),
        'user_id' => $otherUser->getKey(),
    ]);
    $otherCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $otherCompany->getKey(),
        'user_id' => $user->getKey(),
    ]);

    $create = fn (array $attributes = []): Notification => Notification::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'type' => NotificationType::ImportCompleted,
        'preference_key' => NotificationType::ImportCompleted->value,
        'severity' => NotificationSeverity::Info,
        'title' => 'Import notification',
        'idempotency_key' => (string) Str::uuid(),
        ...$attributes,
    ]);

    $unseen = $create(['created_at' => now()->subHour(), 'title' => 'Older unread notice']);
    $dismissed = $create(['dismissed_at' => now(), 'title' => 'Dismissed marker']);
    $otherRecipient = $create(['user_id' => $otherUser->getKey(), 'title' => 'Other recipient marker']);
    $otherTenant = $create(['company_id' => $otherCompany->getKey(), 'title' => 'Other company marker']);
    $firstReadAt = now()->subDay()->startOfSecond();
    $alreadyRead = $create(['read_at' => $firstReadAt]);
    $displayed = collect(range(1, 24))->map(fn () => $create());

    $this->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('data-testid="header-notification-unread-indicator"', false)
        ->assertDontSee('Older unread notice')
        ->assertDontSee('Dismissed marker')
        ->assertDontSee('Other recipient marker')
        ->assertDontSee('Other company marker');

    expect($displayed->every(fn (Notification $notification): bool => $notification->refresh()->read_at !== null))->toBeTrue()
        ->and($unseen->refresh()->read_at)->toBeNull()
        ->and($dismissed->refresh()->read_at)->toBeNull()
        ->and($otherRecipient->refresh()->read_at)->toBeNull()
        ->and($otherTenant->refresh()->read_at)->toBeNull()
        ->and($alreadyRead->refresh()->read_at?->equalTo($firstReadAt))->toBeTrue();

    $this->get(route('notifications.index', ['page' => 2]))
        ->assertOk()
        ->assertSee('Older unread notice')
        ->assertDontSee('data-testid="header-notification-unread-indicator"', false);

    expect($unseen->refresh()->read_at)->not->toBeNull()
        ->and($dismissed->refresh()->read_at)->toBeNull()
        ->and($otherRecipient->refresh()->read_at)->toBeNull()
        ->and($otherTenant->refresh()->read_at)->toBeNull();
});

test('job center is tenant scoped and exposes status without fake controls', function () {
    [$user, $company] = signedInCompanyUser();
    $foreignCompany = Company::factory()->create();
    $tracker = app(JobStatusTracker::class);

    $job = $tracker->register(
        $company,
        'imports.apply',
        'Apply product import',
        'import-job:1',
        $user,
        'imports',
        ['import_uuid' => '00000000-0000-4000-8000-000000000001'],
        10,
    );
    $foreign = JobStatus::query()->create([
        'company_id' => $foreignCompany->getKey(),
        'name' => 'Foreign sync',
        'type' => 'fortnox.sync',
        'queue' => 'fortnox',
        'status' => JobStatusState::Failed,
        'idempotency_key' => 'foreign-job',
    ]);

    $this->get(route('job-center.index'))
        ->assertOk()
        ->assertSee('Apply product import')
        ->assertDontSee('Foreign sync');

    $this->get(route('job-center.show', $job))
        ->assertOk()
        ->assertDontSee('Retry')
        ->assertDontSee('Cancel');

    expect(JobStatus::query()->forCompany($foreignCompany)->first()?->is($foreign))->toBeTrue();
});

test('completed jobs cannot be cancelled and missing entities do not break job detail pages', function () {
    [$user, $company] = signedInCompanyUser();

    $job = JobStatus::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'name' => 'Document expiry scan',
        'type' => 'documents.expiry_scan',
        'queue' => 'maintenance',
        'status' => JobStatusState::Succeeded,
        'payload_summary' => ['document_uuid' => '00000000-0000-4000-8000-000000000002'],
        'idempotency_key' => 'document-expiry:1',
    ]);

    $this->get(route('job-center.show', $job))
        ->assertOk()
        ->assertSee('Document expiry scan')
        ->assertSee('00000000-0000-4000-8000-000000000002');

    $this->get(route('job-center.show', $job))->assertOk()->assertDontSee('Retry')->assertDontSee('Cancel');
});
