<?php

namespace Tests\Feature\Infrastructure;

use App\Notifications\CompanyInvitationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Config;

test('database queue connection is configured', function () {
    $config = require base_path('config/queue.php');
    expect($config['connections'])->toHaveKey('database');
});

test('redis queue connection is configured', function () {
    $config = require base_path('config/queue.php');
    expect($config['connections'])->toHaveKey('redis');
});

test('sync queue connection is configured', function () {
    $config = require base_path('config/queue.php');
    expect($config['connections'])->toHaveKey('sync');
});

test('database queue has retry_after greater than worker timeout', function () {
    $retryAfter = (int) Config::get('queue.connections.database.retry_after', 360);
    $workerTimeout = (int) env('QUEUE_WORKER_TIMEOUT', 300);

    expect($retryAfter)->toBeGreaterThan($workerTimeout);
});

test('jobs table migration exists', function () {
    expect(database_path('migrations/0001_01_01_000002_create_jobs_table.php'))->toBeFile();
});

test('failed jobs table is configured with database-uuids driver', function () {
    expect(Config::get('queue.failed.driver'))->toBe('database-uuids')
        ->and(Config::get('queue.failed.table'))->toBe('failed_jobs');
});

test('config file defaults queue connection to database', function () {
    $config = file_get_contents(base_path('config/queue.php'));
    expect($config)->toContain("'default' => env('QUEUE_CONNECTION', 'database')");
});

test('invitation notification implements ShouldQueue', function () {
    $notification = new CompanyInvitationNotification(
        'Test Company',
        'Test Inviter',
        'viewer',
        now()->addDays(3)->toIso8601String(),
        'https://example.com/invitations/test-uuid?token=test-token',
    );

    expect($notification)->toBeInstanceOf(ShouldQueue::class);
});

test('invitation notification has expected job metadata', function () {
    $notification = new CompanyInvitationNotification(
        'Test Company',
        'Test Inviter',
        'viewer',
        now()->addDays(3)->toIso8601String(),
        'https://example.com/invitations/test-uuid?token=test-token',
    );

    expect($notification->tries)->toBe(3)
        ->and($notification->timeout)->toBe(60)
        ->and($notification->backoff())->toBe([60, 300, 900])
        ->and($notification->queue)->toBe('mail');
});

test('CompanyInvitationNotification does not serialize CurrentCompany', function () {
    $reflection = new \ReflectionClass(CompanyInvitationNotification::class);
    $properties = array_map(fn ($p) => $p->getName(), $reflection->getConstructor()->getParameters());

    expect($properties)->not->toContain('currentCompany')
        ->and($properties)->toContain('companyName', 'inviterName', 'role', 'expiresAt', 'acceptUrl');
});
