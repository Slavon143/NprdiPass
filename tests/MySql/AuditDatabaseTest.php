<?php

use App\Actions\Companies\UpdateCompany;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('mysql audit indexes json and transaction rollback behave correctly', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');

    $indexNames = collect(Schema::getIndexes('activity_log'))->pluck('name');
    expect($indexNames)->toContain(
        'activity_log_company_created_index',
        'activity_log_event_created_index',
        'activity_log_causer_created_index',
        'activity_log_subject_created_index',
        'activity_log_request_id_index',
    );

    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $actor]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $originalName = $company->name;

    try {
        DB::transaction(function () use ($actor, $company): void {
            app(UpdateCompany::class)->execute($actor, $company, ['name' => 'Rolled Back']);
            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('force rollback');
    }

    expect($company->fresh()?->name)->toBe($originalName)
        ->and(AuditLog::query()->count())->toBe(0);

    $log = app(AuditLogger::class)->logTenant(
        $company,
        AuditEvent::CompanyUpdated,
        $actor,
        $company,
        ['changes' => ['name' => ['old' => $originalName, 'new' => 'Committed']]],
    );

    expect(AuditLog::query()
        ->whereKey($log)
        ->whereJsonContains('properties->changes->name->new', 'Committed')
        ->exists())->toBeTrue();
});

test('mysql company physical deletion nulls the audit foreign key without deleting history', function () {
    $company = Company::factory()->create();
    $log = app(AuditLogger::class)->logTenant($company, AuditEvent::CompanyUpdated);

    $company->forceDelete();

    expect($log->fresh())->not->toBeNull()
        ->and($log->fresh()?->company_id)->toBeNull()
        ->and($log->fresh()?->log_name)->toBe('tenant');
});
