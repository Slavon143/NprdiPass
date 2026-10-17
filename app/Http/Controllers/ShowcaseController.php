<?php

namespace App\Http\Controllers;

use App\Services\Passports\Qr\PassportQrRenderer;
use App\Support\UiLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;

class ShowcaseController extends Controller
{
    public function home(Request $request, PassportQrRenderer $qr): View
    {
        $locale = $this->locale($request);
        $target = route('demo', ['lang' => $locale, 'view' => 'passport']);

        return view('showcase.home', [
            'showcaseLocale' => $locale,
            'copy' => $this->copy($locale),
            'landingQrSvg' => $qr->renderSvgForUrl($target),
            'landingQrTarget' => $target,
        ]);
    }

    public function demo(Request $request): View
    {
        $view = $request->query('view', 'dashboard');
        abort_unless(is_string($view) && in_array($view, ['dashboard', 'catalogue', 'product', 'readiness', 'import', 'passport'], true), 404);
        $locale = $this->locale($request);

        return view('showcase.demo', [
            'showcaseLocale' => $locale,
            'copy' => $this->copy($locale),
            'demoView' => $view,
        ]);
    }

    private function copy(string $locale): array
    {
        $copy = (array) Lang::get('showcase', [], $locale);
        $copy['reference'] = (array) Lang::get('showcase_reference', [], $locale);

        return $copy;
    }

    private function locale(Request $request): string
    {
        $locale = $request->query('lang', 'en');
        abort_unless(UiLocale::supports($locale), 404);

        return $locale;
    }
}
