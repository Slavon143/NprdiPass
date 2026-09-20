<?php

namespace App\Services\Analytics;

use App\Enums\Analytics\AnalyticsDeviceCategory;
use App\Enums\Analytics\AnalyticsEventType;
use App\Enums\Analytics\AnalyticsReferrerCategory;
use App\Models\Analytics\AnalyticsEvent;
use App\Models\Passports\ProductPassport;
use Carbon\CarbonImmutable;

class AnalyticsRecorder
{
    public function recordPassportEvent(
        string $publicId,
        AnalyticsEventType $eventType,
        string $countryCode,
        string $deviceCategory,
        string $referrerCategory,
        bool $botFlag,
        CarbonImmutable $occurredAt,
    ): ?AnalyticsEvent {
        if (! config('features.analytics', false)) {
            return null;
        }

        $passport = ProductPassport::query()
            ->with('currentPublishedVersion')
            ->where('public_id', $publicId)
            ->first();

        if (! $passport instanceof ProductPassport || ! $passport->isPublished() || $passport->current_published_version_id === null) {
            return null;
        }

        return AnalyticsEvent::query()->create([
            'company_id' => $passport->company_id,
            'product_id' => $passport->product_id,
            'published_version_id' => $passport->current_published_version_id,
            'passport_public_id' => $passport->public_id,
            'event_type' => $eventType,
            'country_code' => $this->safeCountryCode($countryCode),
            'device_category' => $this->safeDeviceCategory($deviceCategory)->value,
            'referrer_category' => $this->safeReferrerCategory($referrerCategory)->value,
            'bot_flag' => $botFlag,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function safeCountryCode(string $countryCode): string
    {
        $countryCode = strtoupper($countryCode);

        return preg_match('/^[A-Z]{2}$/', $countryCode) === 1 ? $countryCode : 'XX';
    }

    private function safeDeviceCategory(string $deviceCategory): AnalyticsDeviceCategory
    {
        $category = AnalyticsDeviceCategory::tryFrom($deviceCategory);

        if (! $category instanceof AnalyticsDeviceCategory) {
            return AnalyticsDeviceCategory::Unknown;
        }

        return $category;
    }

    private function safeReferrerCategory(string $referrerCategory): AnalyticsReferrerCategory
    {
        $category = AnalyticsReferrerCategory::tryFrom($referrerCategory);

        if (! $category instanceof AnalyticsReferrerCategory) {
            return AnalyticsReferrerCategory::Unknown;
        }

        return $category;
    }
}
