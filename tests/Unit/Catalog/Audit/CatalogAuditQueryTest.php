<?php

use App\Data\Catalog\Audit\CatalogAuditSearchCriteria;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Queries\Catalog\CatalogAuditQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

test('query filters only catalog events', function () {
    $companyId = Company::factory()->create()->getKey();
    $user = User::factory()->create();

    AuditLog::create([
        'company_id' => $companyId,
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogCategoryCreated->value,
        'description' => 'Catalog event',
        'causer_type' => (new User)->getMorphClass(),
        'causer_id' => $user->getKey(),
    ]);
    AuditLog::create([
        'company_id' => $companyId,
        'log_name' => 'tenant',
        'event' => AuditEvent::CompanyUpdated->value,
        'description' => 'Non-catalog event',
        'causer_type' => (new User)->getMorphClass(),
        'causer_id' => $user->getKey(),
    ]);

    $criteria = new CatalogAuditSearchCriteria;
    $query = new CatalogAuditQuery;
    $results = $query->build($criteria, $companyId);

    expect($results->total())->toBe(1)
        ->and($results->first()->event)->toBe(AuditEvent::CatalogCategoryCreated->value);
});

test('query applies event filter', function () {
    $companyId = Company::factory()->create()->getKey();
    $user = User::factory()->create();

    AuditLog::create([
        'company_id' => $companyId,
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogCategoryCreated->value,
        'description' => 'Category created',
        'causer_type' => (new User)->getMorphClass(),
        'causer_id' => $user->getKey(),
    ]);
    AuditLog::create([
        'company_id' => $companyId,
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogProductCreated->value,
        'description' => 'Product created',
        'causer_type' => (new User)->getMorphClass(),
        'causer_id' => $user->getKey(),
    ]);

    $criteria = new CatalogAuditSearchCriteria(event: AuditEvent::CatalogCategoryCreated);
    $query = new CatalogAuditQuery;
    $results = $query->build($criteria, $companyId);

    expect($results->total())->toBe(1)
        ->and($results->first()->event)->toBe(AuditEvent::CatalogCategoryCreated->value);
});

test('query applies resource type filter', function () {
    $companyId = Company::factory()->create()->getKey();
    $user = User::factory()->create();

    AuditLog::create([
        'company_id' => $companyId,
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogCategoryCreated->value,
        'description' => 'Category event',
        'causer_type' => (new User)->getMorphClass(),
        'causer_id' => $user->getKey(),
    ]);
    AuditLog::create([
        'company_id' => $companyId,
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogProductCreated->value,
        'description' => 'Product event',
        'causer_type' => (new User)->getMorphClass(),
        'causer_id' => $user->getKey(),
    ]);

    $criteria = new CatalogAuditSearchCriteria(resourceType: 'category');
    $query = new CatalogAuditQuery;
    $results = $query->build($criteria, $companyId);

    expect($results->total())->toBe(1)
        ->and($results->first()->event)->toBe(AuditEvent::CatalogCategoryCreated->value);
});

test('query applies resource UUID filter', function () {
    $companyId = Company::factory()->create()->getKey();
    $user = User::factory()->create();
    $targetUuid = (string) Str::uuid();

    AuditLog::create([
        'company_id' => $companyId,
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogCategoryCreated->value,
        'description' => 'Target category',
        'causer_type' => (new User)->getMorphClass(),
        'causer_id' => $user->getKey(),
        'properties' => ['category_uuid' => $targetUuid],
    ]);
    AuditLog::create([
        'company_id' => $companyId,
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogCategoryUpdated->value,
        'description' => 'Other category',
        'causer_type' => (new User)->getMorphClass(),
        'causer_id' => $user->getKey(),
        'properties' => ['category_uuid' => (string) Str::uuid()],
    ]);

    $criteria = new CatalogAuditSearchCriteria(resourceUuid: $targetUuid);
    $query = new CatalogAuditQuery;
    $results = $query->build($criteria, $companyId);

    expect($results->total())->toBe(1)
        ->and($results->first()->description)->toBe('Target category');
});

test('query applies date range filter', function () {
    $companyId = Company::factory()->create()->getKey();
    $user = User::factory()->create();

    AuditLog::create([
        'company_id' => $companyId,
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogCategoryCreated->value,
        'description' => 'Recent catalog event',
        'causer_type' => (new User)->getMorphClass(),
        'causer_id' => $user->getKey(),
    ]);
    AuditLog::create([
        'company_id' => $companyId,
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogCategoryUpdated->value,
        'description' => 'Old catalog event',
        'causer_type' => (new User)->getMorphClass(),
        'causer_id' => $user->getKey(),
        'created_at' => now()->subDays(30),
        'updated_at' => now()->subDays(30),
    ]);

    $criteria = new CatalogAuditSearchCriteria(
        dateFrom: CarbonImmutable::parse(now()->subDays(5)->format('Y-m-d')),
        dateTo: CarbonImmutable::parse(now()->format('Y-m-d')),
    );
    $query = new CatalogAuditQuery;
    $results = $query->build($criteria, $companyId);

    expect($results->total())->toBe(1)
        ->and($results->first()->description)->toBe('Recent catalog event');
});

test('query applies request ID filter', function () {
    $companyId = Company::factory()->create()->getKey();
    $user = User::factory()->create();
    $requestId = (string) Str::uuid();

    AuditLog::create([
        'company_id' => $companyId,
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogCategoryCreated->value,
        'description' => 'Matching request',
        'request_id' => $requestId,
        'causer_type' => (new User)->getMorphClass(),
        'causer_id' => $user->getKey(),
    ]);
    AuditLog::create([
        'company_id' => $companyId,
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogCategoryUpdated->value,
        'description' => 'Other request',
        'request_id' => (string) Str::uuid(),
        'causer_type' => (new User)->getMorphClass(),
        'causer_id' => $user->getKey(),
    ]);

    $criteria = new CatalogAuditSearchCriteria(requestId: $requestId);
    $query = new CatalogAuditQuery;
    $results = $query->build($criteria, $companyId);

    expect($results->total())->toBe(1)
        ->and($results->first()->description)->toBe('Matching request');
});

test('query applies actor filter', function () {
    $companyId = Company::factory()->create()->getKey();
    $actorA = User::factory()->create();
    $actorB = User::factory()->create();
    $userMorphType = (new User)->getMorphClass();

    AuditLog::create([
        'company_id' => $companyId,
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogCategoryCreated->value,
        'description' => 'Actor A event',
        'causer_type' => $userMorphType,
        'causer_id' => $actorA->getKey(),
    ]);
    AuditLog::create([
        'company_id' => $companyId,
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogCategoryUpdated->value,
        'description' => 'Actor B event',
        'causer_type' => $userMorphType,
        'causer_id' => $actorB->getKey(),
    ]);

    $criteria = new CatalogAuditSearchCriteria(actorUuid: $actorA->uuid);
    $query = new CatalogAuditQuery;
    $results = $query->build($criteria, $companyId);

    expect($results->total())->toBe(1)
        ->and($results->first()->description)->toBe('Actor A event');
});

test('query returns correct per_page', function () {
    $companyId = Company::factory()->create()->getKey();
    $user = User::factory()->create();

    foreach (range(1, 10) as $number) {
        AuditLog::create([
            'company_id' => $companyId,
            'log_name' => 'tenant',
            'event' => AuditEvent::CatalogCategoryCreated->value,
            'description' => "Event {$number}",
            'causer_type' => (new User)->getMorphClass(),
            'causer_id' => $user->getKey(),
        ]);
    }

    $criteria = new CatalogAuditSearchCriteria(perPage: 3);
    $query = new CatalogAuditQuery;
    $results = $query->build($criteria, $companyId);

    expect($results->total())->toBe(10)
        ->and($results->perPage())->toBe(3)
        ->and($results->count())->toBe(3);
});

test('query is tenant scoped', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::factory()->create();

    AuditLog::create([
        'company_id' => $companyA->getKey(),
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogCategoryCreated->value,
        'description' => 'Company A catalog event',
        'causer_type' => (new User)->getMorphClass(),
        'causer_id' => $user->getKey(),
    ]);
    AuditLog::create([
        'company_id' => $companyB->getKey(),
        'log_name' => 'tenant',
        'event' => AuditEvent::CatalogCategoryCreated->value,
        'description' => 'Company B catalog event',
        'causer_type' => (new User)->getMorphClass(),
        'causer_id' => $user->getKey(),
    ]);

    $criteria = new CatalogAuditSearchCriteria;
    $query = new CatalogAuditQuery;
    $results = $query->build($criteria, $companyA->getKey());

    expect($results->total())->toBe(1)
        ->and($results->first()->description)->toBe('Company A catalog event');
});
