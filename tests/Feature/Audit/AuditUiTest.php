<?php

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\DB;

test('only owners and admins can open the tenant audit page', function (CompanyRole $role, int $status) {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'company_id' => $company,
        'user_id' => $user,
        'role' => $role,
    ]);
    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $this->get(route('audit.index'))->assertStatus($status);
})->with([
    'owner' => [CompanyRole::Owner, 200],
    'admin' => [CompanyRole::Admin, 200],
    'editor' => [CompanyRole::Editor, 403],
    'viewer' => [CompanyRole::Viewer, 403],
]);

test('tenant audit page never exposes another company or platform events', function () {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $companyA = Company::factory()->create(['name' => 'Audit Company A']);
    $companyB = Company::factory()->create(['name' => 'Audit Company B']);
    CompanyMembership::factory()->owner()->create(['company_id' => $companyA, 'user_id' => $ownerA]);
    CompanyMembership::factory()->owner()->create(['company_id' => $companyB, 'user_id' => $ownerB]);
    $logger = app(AuditLogger::class);
    $logger->logTenant($companyA, AuditEvent::CompanyUpdated, $ownerA, 'Visible A subject');
    $logger->logTenant($companyB, AuditEvent::CompanyUpdated, $ownerB, 'Hidden B subject');
    $logger->logPlatform(AuditEvent::PlatformAction, $ownerA, 'Hidden platform subject');

    $this->actingAs($ownerA);
    app(CurrentCompany::class)->set($companyA);
    $this->get(route('audit.index'))
        ->assertOk()
        ->assertSee('Visible A subject')
        ->assertDontSee('Hidden B subject')
        ->assertDontSee('Hidden platform subject')
        ->assertDontSee('App\\Models\\');

    $this->actingAs($ownerB);
    app(CurrentCompany::class)->set($companyB);
    $this->get(route('audit.index'))
        ->assertOk()
        ->assertSee('Hidden B subject')
        ->assertDontSee('Visible A subject');
});

test('audit filters remain tenant scoped and validate event actor and date range', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $foreignActor = User::factory()->create();
    $company = Company::factory()->create();
    $foreignCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $owner]);
    CompanyMembership::factory()->admin()->create(['company_id' => $company, 'user_id' => $admin]);
    CompanyMembership::factory()->owner()->create(['company_id' => $foreignCompany, 'user_id' => $foreignActor]);
    $logger = app(AuditLogger::class);
    $companyEvent = $logger->logTenant($company, AuditEvent::CompanyUpdated, $owner, 'Company filter marker');
    $logger->logTenant($company, AuditEvent::MemberRemoved, $admin, 'Member filter marker');
    $logger->logTenant($foreignCompany, AuditEvent::CompanyUpdated, $foreignActor, 'Foreign marker');
    DB::table('activity_log')->where('id', $companyEvent->getKey())->update([
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ]);
    $this->actingAs($owner);
    app(CurrentCompany::class)->set($company);

    $this->get(route('audit.index', ['event' => AuditEvent::MemberRemoved->value]))
        ->assertOk()
        ->assertSee('Member filter marker')
        ->assertDontSee('Company filter marker', false)
        ->assertDontSee('Foreign marker');

    $this->get(route('audit.index', ['actor' => $admin->uuid]))
        ->assertOk()
        ->assertSee('Member filter marker')
        ->assertDontSee('Company filter marker', false);

    $this->get(route('audit.index', [
        'date_from' => now()->subDays(2)->format('Y-m-d'),
        'date_to' => now()->format('Y-m-d'),
    ]))->assertOk()
        ->assertSee('Member filter marker')
        ->assertDontSee('Company filter marker', false);

    $this->get(route('audit.index', ['event' => 'invalid.event']))
        ->assertSessionHasErrors('event');
    $this->get(route('audit.index', ['actor' => $foreignActor->uuid]))->assertNotFound();
    $this->get(route('audit.index', [
        'date_from' => now()->subDays(367)->format('Y-m-d'),
        'date_to' => now()->format('Y-m-d'),
    ]))->assertSessionHasErrors('date_to');
});

test('audit pagination keeps filters in links and uses fifty rows per page', function () {
    $owner = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $owner]);
    $logger = app(AuditLogger::class);

    foreach (range(1, 51) as $number) {
        $logger->logTenant(
            $company,
            AuditEvent::CompanyUpdated,
            $owner,
            "Pagination event {$number}",
        );
    }

    $this->actingAs($owner);
    app(CurrentCompany::class)->set($company);
    $response = $this->get(route('audit.index', ['event' => AuditEvent::CompanyUpdated->value]));

    $response->assertOk()
        ->assertSee('50 events shown')
        ->assertSee('event=company.updated', false);
});

test('company switch records from and to uuids while foreign substitution stays platform scoped', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $foreign = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $companyA, 'user_id' => $user]);
    CompanyMembership::factory()->owner()->create(['company_id' => $companyB, 'user_id' => $user]);
    $this->actingAs($user);
    app(CurrentCompany::class)->set($companyA);

    $this->post(route('companies.switch', $companyB))->assertRedirect(route('dashboard'));
    $switchLog = AuditLog::query()->where('event', AuditEvent::CompanySwitched->value)->sole();

    expect($switchLog->company_id)->toBe($companyB->getKey())
        ->and($switchLog->getProperty('from_company_uuid'))->toBe($companyA->uuid)
        ->and($switchLog->getProperty('to_company_uuid'))->toBe($companyB->uuid);

    $this->post(route('companies.switch', $foreign))->assertForbidden();
    $denied = AuditLog::query()->where('event', AuditEvent::CompanyAccessDenied->value)->sole();
    expect($denied->company_id)->toBeNull()
        ->and($denied->getProperty('requested_company_uuid'))->toBe($foreign->uuid);
});

test('there is no public route for an individual audit record', function () {
    $this->get('/audit/1')->assertNotFound();
});
