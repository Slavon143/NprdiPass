<?php

namespace App\Http\Controllers;

use App\Support\UiLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UiLocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(array_keys(UiLocale::LABELS))]]);
        $request->session()->put('ui_locale', $data['locale']);
        $referer = $request->headers->get('referer', '');
        $target = route('home', ['lang' => $data['locale']]);
        // Never let an external Referer turn this preference form into an open redirect.
        if (is_string($referer) && str_starts_with($referer, $request->getSchemeAndHttpHost().'/')) {
            $parts = parse_url($referer);
            parse_str($parts['query'] ?? '', $query);
            unset($query['lang']);
            $target = $request->getSchemeAndHttpHost().($parts['path'] ?? '/').($query === [] ? '' : '?'.http_build_query($query));
        }

        return redirect()->to($target);
    }
}
