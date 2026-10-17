<?php

namespace App\Http\Middleware;

use App\Support\UiLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseUiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // Public passports resolve language from their immutable snapshot.
        if ($request->is('p/*') || $request->routeIs('home', 'demo', 'stripe.webhook')) {
            return $next($request);
        }
        $previous = app()->getLocale();
        $preferred = $request->hasSession() ? $request->session()->get('ui_locale', $previous) : $previous;
        $uiQuery = ! $request->routeIs('catalog.products.passport.preview') ? $request->query('lang') : null;
        $locale = UiLocale::supports($uiQuery) ? $uiQuery : $preferred;
        if (! UiLocale::supports($locale)) {
            $locale = UiLocale::supports($previous) ? $previous : 'en';
        }
        if (UiLocale::supports($uiQuery) && $request->hasSession()) {
            $request->session()->put('ui_locale', $locale);
        }
        app()->setLocale($locale);
        try {
            $response = $next($request);
            if (! $response->headers->has('Content-Language')) {
                $response->headers->set('Content-Language', $locale);
            }

            return $response;
        } finally {
            app()->setLocale($previous);
        }
    }
}
