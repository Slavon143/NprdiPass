<?php

namespace App\Http\Controllers\Passports;

use App\Http\Controllers\Controller;
use App\Services\Passports\Public\PublicPassportResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicPassportController extends Controller
{
    public function __invoke(
        Request $request,
        PublicPassportResolver $resolver,
        string $publicId,
    ): View|Response {
        $requestedLocale = $request->query('lang');
        $viewModel = $resolver->resolve($publicId, $requestedLocale);

        $etag = '"'.$viewModel->snapshotChecksum.':'.$viewModel->requestedLocale.'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=3600, s-maxage=86400',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $headers = [
            'Cache-Control' => 'public, max-age=3600, s-maxage=86400',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($viewModel->publishedAt !== '') {
            $headers['Last-Modified'] = $viewModel->publishedAt;
        }

        return response(
            view('passports.public.show', [
                'passport' => $viewModel,
            ])->render(),
            200,
            $headers,
        );
    }
}
