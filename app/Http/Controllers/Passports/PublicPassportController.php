<?php

namespace App\Http\Controllers\Passports;

use App\Enums\Analytics\AnalyticsEventType;
use App\Http\Controllers\Controller;
use App\Jobs\Analytics\RecordAnalyticsEventJob;
use App\Services\Analytics\AnalyticsClassifier;
use App\Services\Passports\Public\PublicPassportData;
use App\Services\Passports\Public\PublicPassportResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicPassportController extends Controller
{
    public function __invoke(
        Request $request,
        PublicPassportResolver $resolver,
        AnalyticsClassifier $analyticsClassifier,
        string $publicId,
    ): View|Response {
        $requestedLocale = $request->query('lang');
        $viewModel = $resolver->resolve($publicId, $requestedLocale);

        $json = $request->wantsJson();
        $etag = '"'.$viewModel->snapshotChecksum.':'.$viewModel->requestedLocale.($json ? ':json-v1' : '').'"';
        $headers = $this->publicHeaders($etag, $viewModel->publishedAt);
        $headers['Content-Language'] = $viewModel->requestedLocale;

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, $headers)
                ->withoutCookie((string) config('session.cookie'));
        }

        if ($json) {
            return response()->json(app(PublicPassportData::class)->serialize($viewModel), 200, $headers)
                ->withoutCookie((string) config('session.cookie'));
        }

        if (config('features.analytics', false)) {
            $classified = $analyticsClassifier->classify($request);

            RecordAnalyticsEventJob::dispatch(
                $viewModel->passportPublicId,
                AnalyticsEventType::PassportView->value,
                $classified['country_code'],
                $classified['device_category'],
                $classified['referrer_category'],
                $classified['bot_flag'],
                CarbonImmutable::now()->toIso8601String(),
            );
        }

        $previousLocale = app()->getLocale();
        app()->setLocale($headers['Content-Language']);
        try {
            return response(view('passports.public.show', ['passport' => $viewModel])->render(), 200, $headers)
                ->withoutCookie((string) config('session.cookie'));
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    /**
     * @return array<string, string>
     */
    private function publicHeaders(string $etag, string $publishedAt): array
    {
        $headers = [
            'Cache-Control' => 'public, max-age=3600, s-maxage=86400',
            'ETag' => $etag,
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Vary' => 'Accept, Accept-Encoding',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'index, follow',
        ];

        if ($publishedAt !== '') {
            $headers['Last-Modified'] = $publishedAt;
        }

        return $headers;
    }
}
