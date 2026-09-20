<?php

namespace App\Jobs\Analytics;

use App\Enums\Analytics\AnalyticsEventType;
use App\Services\Analytics\AnalyticsRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordAnalyticsEventJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $publicId,
        public readonly string $eventType,
        public readonly string $countryCode,
        public readonly string $deviceCategory,
        public readonly string $referrerCategory,
        public readonly bool $botFlag,
        public readonly string $occurredAt,
    ) {
        $this->onQueue('analytics');
    }

    public function handle(AnalyticsRecorder $recorder): void
    {
        $eventType = AnalyticsEventType::tryFrom($this->eventType);

        if (! $eventType instanceof AnalyticsEventType) {
            return;
        }

        $recorder->recordPassportEvent(
            $this->publicId,
            $eventType,
            $this->countryCode,
            $this->deviceCategory,
            $this->referrerCategory,
            $this->botFlag,
            CarbonImmutable::parse($this->occurredAt),
        );
    }
}
