<?php

use App\Audit\AuditContext;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

test('audit schema and custom model expose the required immutable structure', function () {
    $columns = [
        'company_id',
        'ip_address',
        'user_agent',
        'request_id',
        'batch_uuid',
        'properties',
    ];
    $indexNames = collect(Schema::getIndexes('activity_log'))->pluck('name');
    $auditLog = new AuditLog;

    foreach ($columns as $column) {
        expect(Schema::hasColumn('activity_log', $column))->toBeTrue();
    }

    expect(config('activitylog.activity_model'))->toBe(AuditLog::class)
        ->and($auditLog->getTable())->toBe('activity_log')
        ->and(Schema::hasColumn('activity_log', 'deleted_at'))->toBeFalse()
        ->and(method_exists($auditLog, 'trashed'))->toBeFalse()
        ->and($indexNames)->toContain(
            'activity_log_company_created_index',
            'activity_log_event_created_index',
            'activity_log_causer_created_index',
            'activity_log_subject_created_index',
            'activity_log_request_id_index',
        );
});

test('logger separates tenant and platform events and stores safe snapshots', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $logger = app(AuditLogger::class);

    $tenantLog = $logger->logTenant(
        $company,
        AuditEvent::CompanyUpdated,
        $actor,
        $company,
        [
            'changes' => ['name' => ['old' => 'Old', 'new' => 'New']],
            'password' => 'never-store-this',
            'nested' => ['token_hash' => str_repeat('a', 64)],
        ],
    );
    $platformLog = $logger->logPlatform(AuditEvent::PlatformAction, $actor, 'Platform');
    $properties = $tenantLog->properties?->toArray() ?? [];

    expect($tenantLog->company_id)->toBe($company->getKey())
        ->and($tenantLog->event)->toBe(AuditEvent::CompanyUpdated->value)
        ->and($tenantLog->causer_id)->toBe($actor->getKey())
        ->and($tenantLog->subject_id)->toBe($company->getKey())
        ->and($properties['actor_email'])->toBe($actor->email)
        ->and($properties['company_uuid'])->toBe($company->uuid)
        ->and($properties)->not->toHaveKey('password')
        ->and($properties['nested'])->not->toHaveKey('token_hash')
        ->and($platformLog->company_id)->toBeNull()
        ->and($platformLog->log_name)->toBe('platform');
});

test('request id middleware validates headers and supplies bounded request metadata', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $longAgent = str_repeat('A', 700)."\x01";
    Log::spy();

    Route::get('/_audit-context-test', function (AuditLogger $logger) use ($actor, $company) {
        $logger->logTenant($company, AuditEvent::PlatformAction, $actor, $company);

        return response('ok');
    });

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->withHeader('X-Request-ID', 'safe.request_123')
        ->withHeader('User-Agent', $longAgent)
        ->get('/_audit-context-test')
        ->assertOk()
        ->assertHeader('X-Request-ID', 'safe.request_123');

    $log = AuditLog::query()->sole();
    expect($log->request_id)->toBe('safe.request_123')
        ->and($log->ip_address)->toBe('203.0.113.10')
        ->and(mb_strlen((string) $log->user_agent))->toBe(500)
        ->and($log->user_agent)->not->toContain("\x01");
    Log::shouldHaveReceived('withContext')->with(['request_id' => 'safe.request_123']);
    Log::shouldHaveReceived('withoutContext')->with(['request_id']);

    $this->withHeader('X-Request-ID', 'not safe/value')
        ->get('/')
        ->assertOk()
        ->assertHeader('X-Request-ID');
});

test('audit context and logger are safe outside an http request', function () {
    request()->attributes->remove(AuditContext::REQUEST_ID_ATTRIBUTE);
    $metadata = app(AuditContext::class)->metadata();
    $log = app(AuditLogger::class)->logPlatform(AuditEvent::PlatformAction);

    expect($metadata)->toBe([
        'ip_address' => null,
        'user_agent' => null,
        'request_id' => null,
    ])->and($log->request_id)->toBeNull()
        ->and($log->ip_address)->toBeNull();
});

test('audit rows reject model updates and individual model deletion', function () {
    $log = app(AuditLogger::class)->logPlatform(AuditEvent::PlatformAction);

    $log->description = 'changed';
    expect(fn () => $log->save())->toThrow(LogicException::class);

    $fresh = $log->fresh();
    expect($fresh)->not->toBeNull()
        ->and(fn () => $fresh?->delete())->toThrow(LogicException::class);
});
