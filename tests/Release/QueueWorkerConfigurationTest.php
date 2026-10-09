<?php

namespace Tests\Release;

use App\Jobs\Analytics\RecordAnalyticsEventJob;
use App\Jobs\Infrastructure\QueueWorkerHeartbeat;
use PHPUnit\Framework\TestCase;

class QueueWorkerConfigurationTest extends TestCase
{
    public function test_supervisor_consumes_analytics_and_worker_heartbeat_jobs(): void
    {
        $configuration = file_get_contents(__DIR__.'/../../deploy/supervisor/nordipass-worker.conf.example');
        self::assertIsString($configuration);
        self::assertSame(1, preg_match('/^command=.*--queue=([^\s]+).*$/m', $configuration, $matches));
        $queues = explode(',', $matches[1]);

        $analytics = new RecordAnalyticsEventJob(
            'acceptance-passport', 'view', 'SE', 'desktop', 'direct', false, '2026-10-07T00:00:00Z',
        );

        self::assertContains($analytics->queue, $queues, 'Published-passport analytics must have a queue consumer.');
        self::assertContains((new QueueWorkerHeartbeat)->queue, $queues, 'Scheduled worker heartbeats must have a queue consumer.');
        self::assertContains('default', $queues);
    }
}
