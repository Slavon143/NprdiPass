<?php

namespace App\Services\Analytics;

use App\Enums\Analytics\AnalyticsDeviceCategory;
use App\Enums\Analytics\AnalyticsReferrerCategory;
use Illuminate\Http\Request;

class AnalyticsClassifier
{
    /**
     * @return array{country_code: string, device_category: string, referrer_category: string, bot_flag: bool}
     */
    public function classify(Request $request): array
    {
        return [
            'country_code' => $this->countryCode($request->headers->get('CF-IPCountry') ?? $request->headers->get('X-Country-Code')),
            'device_category' => $this->deviceCategory($request->userAgent())->value,
            'referrer_category' => $this->referrerCategory($request->headers->get('referer'), (string) config('app.url'))->value,
            'bot_flag' => $this->isBot($request->userAgent()),
        ];
    }

    public function countryCode(?string $value): string
    {
        $countryCode = strtoupper(trim((string) $value));

        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            return 'XX';
        }

        return $countryCode;
    }

    public function deviceCategory(?string $userAgent): AnalyticsDeviceCategory
    {
        $agent = strtolower((string) $userAgent);

        if ($agent === '') {
            return AnalyticsDeviceCategory::Unknown;
        }

        if (str_contains($agent, 'ipad') || str_contains($agent, 'tablet')) {
            return AnalyticsDeviceCategory::Tablet;
        }

        if (str_contains($agent, 'mobile') || str_contains($agent, 'iphone') || str_contains($agent, 'android')) {
            return AnalyticsDeviceCategory::Mobile;
        }

        return AnalyticsDeviceCategory::Desktop;
    }

    public function referrerCategory(?string $referrer, string $appUrl): AnalyticsReferrerCategory
    {
        $referrer = trim((string) $referrer);

        if ($referrer === '') {
            return AnalyticsReferrerCategory::Direct;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return AnalyticsReferrerCategory::Unknown;
        }

        $host = strtolower($host);
        $appHost = parse_url($appUrl, PHP_URL_HOST);

        if (is_string($appHost) && $appHost !== '' && $host === strtolower($appHost)) {
            return AnalyticsReferrerCategory::Internal;
        }

        foreach (['google.', 'bing.', 'duckduckgo.', 'yahoo.', 'yandex.', 'baidu.'] as $needle) {
            if (str_contains($host, $needle)) {
                return AnalyticsReferrerCategory::Search;
            }
        }

        foreach (['facebook.', 'instagram.', 'linkedin.', 't.co', 'twitter.', 'x.com', 'pinterest.', 'youtube.'] as $needle) {
            if (str_contains($host, $needle)) {
                return AnalyticsReferrerCategory::Social;
            }
        }

        return AnalyticsReferrerCategory::External;
    }

    public function isBot(?string $userAgent): bool
    {
        $agent = strtolower((string) $userAgent);

        if ($agent === '') {
            return false;
        }

        foreach (['bot', 'crawler', 'spider', 'slurp', 'bingpreview', 'headless', 'curl', 'wget', 'python-requests'] as $needle) {
            if (str_contains($agent, $needle)) {
                return true;
            }
        }

        return false;
    }
}
