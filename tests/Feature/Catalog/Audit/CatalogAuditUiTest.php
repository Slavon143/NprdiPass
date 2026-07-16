<?php

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('catalog audit page is accessible to owners', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $company,
        'user_id' => $user,
    ]);
    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $this->get(route('catalog.audit.index'))->assertOk();
});

test('catalog audit page is accessible to admins', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->admin()->create([
        'company_id' => $company,
        'user_id' => $user,
    ]);
    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $this->get(route('catalog.audit.index'))->assertOk();
});

test('catalog audit page is denied to editors', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->editor()->create([
        'company_id' => $company,
        'user_id' => $user,
    ]);
    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $this->get(route('catalog.audit.index'))->assertForbidden();
});

test('catalog audit page is denied to viewers', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->viewer()->create([
        'company_id' => $company,
        'user_id' => $user,
    ]);
    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $this->get(route('catalog.audit.index'))->assertForbidden();
});

test('catalog audit page shows only catalog events', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $company,
        'user_id' => $user,
    ]);
    $logger = app(AuditLogger::class);
    $logger->logTenant($company, AuditEvent::CatalogCategoryCreated, $user, 'Catalog category subject');
    $logger->logTenant($company, AuditEvent::CompanyUpdated, $user, 'Company update subject');
    $logger->logTenant($company, AuditEvent::MemberInvited, $user, 'Member invited subject');

    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $this->get(route('catalog.audit.index'))
        ->assertOk()
        ->assertSee('Catalog category subject')
        ->assertDontSee('Company update subject')
        ->assertDontSee('Member invited subject');
});

test('catalog audit filters by event type', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $company,
        'user_id' => $user,
    ]);
    $logger = app(AuditLogger::class);
    $logger->logTenant($company, AuditEvent::CatalogCategoryCreated, $user, 'Category created marker');
    $logger->logTenant($company, AuditEvent::CatalogProductCreated, $user, 'Product created marker');

    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $this->get(route('catalog.audit.index', ['event' => AuditEvent::CatalogCategoryCreated->value]))
        ->assertOk()
        ->assertSee('Category created marker')
        ->assertDontSee('Product created marker');
});

test('catalog audit filters by actor', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $company,
        'user_id' => $user,
    ]);
    CompanyMembership::factory()->admin()->create([
        'company_id' => $company,
        'user_id' => $otherUser,
    ]);
    $logger = app(AuditLogger::class);
    $logger->logTenant($company, AuditEvent::CatalogCategoryCreated, $user, 'Owner actor marker');
    $logger->logTenant($company, AuditEvent::CatalogCategoryCreated, $otherUser, 'Admin actor marker');

    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $this->get(route('catalog.audit.index', ['actor' => $otherUser->uuid]))
        ->assertOk()
        ->assertSee('Admin actor marker')
        ->assertDontSee('Owner actor marker');
});

test('catalog audit filters by resource type', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $company,
        'user_id' => $user,
    ]);
    $logger = app(AuditLogger::class);
    $logger->logTenant($company, AuditEvent::CatalogCategoryCreated, $user, 'Category resource marker');
    $logger->logTenant($company, AuditEvent::CatalogProductCreated, $user, 'Product resource marker');

    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $this->get(route('catalog.audit.index', ['resource_type' => 'category']))
        ->assertOk()
        ->assertSee('Category resource marker')
        ->assertDontSee('Product resource marker');
});

test('catalog audit filters by resource UUID', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $company,
        'user_id' => $user,
    ]);
    $logger = app(AuditLogger::class);
    $categoryUuid = (string) Str::uuid();
    $logger->logTenant($company, AuditEvent::CatalogCategoryCreated, $user, 'Matching resource', [
        'category_uuid' => $categoryUuid,
    ]);
    $logger->logTenant($company, AuditEvent::CatalogCategoryUpdated, $user, 'Non-matching resource', [
        'category_uuid' => (string) Str::uuid(),
    ]);

    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $this->get(route('catalog.audit.index', ['resource_uuid' => $categoryUuid]))
        ->assertOk()
        ->assertSee('Matching resource')
        ->assertDontSee('Non-matching resource');
});

test('catalog audit filters by date range', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $company,
        'user_id' => $user,
    ]);
    $logger = app(AuditLogger::class);
    $logger->logTenant($company, AuditEvent::CatalogCategoryCreated, $user, 'Recent event marker');
    $oldEvent = $logger->logTenant($company, AuditEvent::CatalogCategoryUpdated, $user, 'Old event marker');
    DB::table('activity_log')->where('id', $oldEvent->getKey())->update([
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ]);

    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $this->get(route('catalog.audit.index', [
        'date_from' => now()->subDays(2)->format('Y-m-d'),
        'date_to' => now()->format('Y-m-d'),
    ]))->assertOk()
        ->assertSee('Recent event marker')
        ->assertDontSee('Old event marker');
});

test('catalog audit filters by request ID', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $company,
        'user_id' => $user,
    ]);
    $targetId = (string) Str::uuid();

    AuditLog::create([
        'company_id' => $company->getKey(),
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogCategoryCreated->value,
        'description' => 'Target event',
        'request_id' => $targetId,
        'causer_type' => $user->getMorphClass(),
        'causer_id' => $user->getKey(),
        'properties' => ['subject_label' => 'Target request marker'],
    ]);
    AuditLog::create([
        'company_id' => $company->getKey(),
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogCategoryUpdated->value,
        'description' => 'Other event',
        'request_id' => (string) Str::uuid(),
        'causer_type' => $user->getMorphClass(),
        'causer_id' => $user->getKey(),
        'properties' => ['subject_label' => 'Other request marker'],
    ]);

    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $this->get(route('catalog.audit.index', ['request_id' => $targetId]))
        ->assertOk()
        ->assertSee('Target request marker')
        ->assertDontSee('Other request marker');
});

test('catalog audit supports pagination', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $company,
        'user_id' => $user,
    ]);
    $logger = app(AuditLogger::class);

    foreach (range(1, 55) as $number) {
        $logger->logTenant($company, AuditEvent::CatalogCategoryCreated, $user, "Pagination event {$number}");
    }

    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $this->get(route('catalog.audit.index', ['per_page' => 25]))
        ->assertOk()
        ->assertSee('Pagination event 55')
        ->assertSee('Pagination event 31')
        ->assertDontSee('Pagination event 30');
});

test('catalog audit detail page shows individual event', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $company,
        'user_id' => $user,
    ]);
    $logger = app(AuditLogger::class);
    $event = $logger->logTenant($company, AuditEvent::CatalogCategoryCreated, $user, 'Detail view subject', [
        'category_uuid' => (string) Str::uuid(),
    ]);

    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    $this->get(route('catalog.audit.show', $event->getKey()))
        ->assertOk()
        ->assertSee('Detail view subject');
});

test('catalog audit detail page returns 404 for wrong company', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $companyA,
        'user_id' => $userA,
    ]);
    CompanyMembership::factory()->owner()->create([
        'company_id' => $companyB,
        'user_id' => $userB,
    ]);
    $logger = app(AuditLogger::class);
    $event = $logger->logTenant($companyB, AuditEvent::CatalogCategoryCreated, $userB, 'Cross company detail');

    $this->actingAs($userA);
    app(CurrentCompany::class)->set($companyA);

    $this->get(route('catalog.audit.show', $event->getKey()))->assertNotFound();
});

test('catalog audit page is tenant scoped', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $companyA = Company::factory()->create(['name' => 'Catalog Company A']);
    $companyB = Company::factory()->create(['name' => 'Catalog Company B']);
    CompanyMembership::factory()->owner()->create([
        'company_id' => $companyA,
        'user_id' => $userA,
    ]);
    CompanyMembership::factory()->owner()->create([
        'company_id' => $companyB,
        'user_id' => $userB,
    ]);
    $logger = app(AuditLogger::class);
    $logger->logTenant($companyA, AuditEvent::CatalogCategoryCreated, $userA, 'Company A catalog event');
    $logger->logTenant($companyB, AuditEvent::CatalogCategoryCreated, $userB, 'Company B catalog event');

    $this->actingAs($userA);
    app(CurrentCompany::class)->set($companyA);

    $this->get(route('catalog.audit.index'))
        ->assertOk()
        ->assertSee('Company A catalog event')
        ->assertDontSee('Company B catalog event');

    $this->actingAs($userB);
    app(CurrentCompany::class)->set($companyB);

    $this->get(route('catalog.audit.index'))
        ->assertOk()
        ->assertSee('Company B catalog event')
        ->assertDontSee('Company A catalog event');
});
