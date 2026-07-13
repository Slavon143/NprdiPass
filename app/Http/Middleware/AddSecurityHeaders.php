<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    private const BASELINE_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Frame-Options' => 'SAMEORIGIN',
    ];

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::BASELINE_HEADERS as $header => $value) {
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        if (config('security.hsts_enabled', false)
            && $request->isSecure()
            && app()->environment('production')) {
            $maxAge = (int) config('security.hsts_max_age', 31536000);
            $includeSub = config('security.hsts_include_subdomains', false) ? '; includeSubDomains' : '';
            $preload = config('security.hsts_preload', false) ? '; preload' : '';

            $response->headers->set(
                'Strict-Transport-Security',
                "max-age={$maxAge}{$includeSub}{$preload}",
            );
        }

        return $response;
    }
}
