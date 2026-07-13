<?php

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

function ageAuditLog(AuditLog $auditLog, int $days): void
{
    DB::table('activity_log')->where('id', $auditLog->getKey())->update([
        'created_at' => now()->subDays($days),
        'updated_at' => now()->subDays($days),
    ]);
}

test('audit pruning dry run reports without deleting', function () {
    $company = Company::factory()->create();
    $old = app(AuditLogger::class)->logTenant($company, AuditEvent::CompanyUpdated);
    ageAuditLog($old, 400);

    $this->artisan('nordipass:prune-audit-logs', ['--dry-run' => true])
        ->expectsOutput('1 audit log record(s) would be pruned.')
        ->assertSuccessful();

    expect($old->fresh())->not->toBeNull();
});

test('company scoped pruning removes only old matching tenant logs', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $logger = app(AuditLogger::class);
    $oldA = $logger->logTenant($companyA, AuditEvent::CompanyUpdated);
    $freshA = $logger->logTenant($companyA, AuditEvent::CompanyUpdated);
    $oldB = $logger->logTenant($companyB, AuditEvent::CompanyUpdated);
    $platform = $logger->logPlatform(AuditEvent::PlatformAction);
    ageAuditLog($oldA, 40);
    ageAuditLog($oldB, 40);
    ageAuditLog($platform, 40);

    $this->artisan('nordipass:prune-audit-logs', [
        '--days' => '30',
        '--company' => $companyA->uuid,
    ])->expectsOutput('1 audit log record(s) pruned.')
        ->assertSuccessful();

    expect($oldA->fresh())->toBeNull()
        ->and($freshA->fresh())->not->toBeNull()
        ->and($oldB->fresh())->not->toBeNull()
        ->and($platform->fresh())->not->toBeNull();
});

test('audit pruning deletes in chunks and preserves platform logs', function () {
    $company = Company::factory()->create();
    $oldTime = now()->subDays(90);
    $rows = [];

    foreach (range(1, 501) as $number) {
        $rows[] = [
            'company_id' => $company->getKey(),
            'log_name' => 'tenant',
            'description' => AuditEvent::CompanyUpdated->value,
            'event' => AuditEvent::CompanyUpdated->value,
            'properties' => json_encode(['sequence' => $number], JSON_THROW_ON_ERROR),
            'created_at' => $oldTime,
            'updated_at' => $oldTime,
        ];
    }

    DB::table('activity_log')->insert($rows);
    $platform = app(AuditLogger::class)->logPlatform(AuditEvent::PlatformAction);
    ageAuditLog($platform, 90);

    $this->artisan('nordipass:prune-audit-logs', ['--days' => '30'])
        ->expectsOutput('501 audit log record(s) pruned.')
        ->assertSuccessful();

    expect(AuditLog::query()->where('log_name', 'tenant')->count())->toBe(0)
        ->and($platform->fresh())->not->toBeNull();
});

test('audit pruning rejects invalid options', function () {
    $this->artisan('nordipass:prune-audit-logs', ['--days' => 'zero'])->assertExitCode(2);
    $this->artisan('nordipass:prune-audit-logs', ['--company' => 'missing'])->assertExitCode(2);
    $this->artisan('nordipass:prune-audit-logs', [
        '--company' => '00000000-0000-4000-8000-000000000000',
    ])->assertFailed();
});
