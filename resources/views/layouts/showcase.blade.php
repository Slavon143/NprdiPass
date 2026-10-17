<!doctype html>
<html lang="{{ $showcaseLocale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $copy['intro'] }}">
    @isset($demoView)<meta name="robots" content="noindex, follow">@endisset
    <title>NordiPass — {{ isset($demoView) ? $copy['demo'] : $copy['hero_accent'] }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="np-prototype">
<div class="np-site" data-showcase @isset($demoView) data-showcase-demo data-demo-view="{{ $demoView }}" data-demo-lang="{{ $showcaseLocale }}" @endisset>
    <a href="#main" class="skip-link">{{ $copy['main'] }}</a>
        @include('showcase.partials.header')
        <nav class="mobile-nav" data-public-menu hidden aria-label="{{ $copy['how'] }}">
            <a href="{{ route('home', ['lang' => $showcaseLocale]) }}#how-it-works">{{ $copy['how'] }}</a>
            <a href="{{ route('home', ['lang' => $showcaseLocale]) }}#integrations">{{ $copy['integrations'] }}</a>
            <a href="{{ route('home', ['lang' => $showcaseLocale]) }}#pricing">{{ $copy['reference']['Pricing'] }}</a>
            <a href="{{ route('demo', ['lang' => $showcaseLocale, 'view' => 'passport']) }}">{{ $copy['example'] }}</a>
            <a href="{{ route('login', $showcaseLocale === 'en' ? [] : ['lang' => $showcaseLocale]) }}">{{ $copy['reference']['Sign in'] }}</a>
            <a href="{{ route('register', $showcaseLocale === 'en' ? [] : ['lang' => $showcaseLocale]) }}">{{ $copy['reference']['Sign up'] }}</a>
        </nav>
    <div id="main">@yield('content')</div>
    <footer class="site-footer" data-demo-shell="public" @if(isset($demoView) && $demoView !== 'passport') hidden @endif>
        <div class="footer-inner page-width">
            <a class="brand brand-small" href="{{ route('home', ['lang' => $showcaseLocale]) }}">NordiPass<span class="brand-period">.</span></a>
            <p>{{ $copy['reference']['Product information, connected.'] }}</p>
            <div><span>{{ $copy['reference']['Public demo'] }} · {{ $copy['reference']['Sample data'] }}</span>
                @isset($demoView)<button type="button" data-demo-action="reset">{{ $copy['reset'] }}</button>@endisset
            </div>
        </div>
    </footer>
    <dialog class="language-dialog" data-language-dialog aria-labelledby="showcase-language-title">
        <button type="button" class="dialog-close" data-dialog-close aria-label="{{ $copy['reference']['Close'] }}">×</button>
        <h2 id="showcase-language-title">{{ $copy['language'] }}</h2>
        <nav class="language-options" aria-label="{{ $copy['language'] }}">
            @foreach(\App\Support\UiLocale::LABELS as $locale => $name)
                <a href="{{ route(isset($demoView) ? 'demo' : 'home', array_filter(['lang' => $locale, 'view' => $demoView ?? null])) }}" data-demo-locale="{{ $locale }}" lang="{{ $locale }}" hreflang="{{ $locale }}" @if($locale === $showcaseLocale) aria-current="true" @endif>
                    <span class="company-avatar">{{ strtoupper($locale) }}</span><strong>{{ $name }}</strong>
                </a>
            @endforeach
        </nav>
    </dialog>
    <dialog class="info-dialog" data-info-dialog aria-labelledby="showcase-info-title">
        <button type="button" class="dialog-close" data-dialog-close aria-label="{{ $copy['reference']['Close'] }}">×</button>
        <h2 id="showcase-info-title">{{ $copy['demo'] }}</h2>
        <p>{{ $copy['reference']['This section belongs to a real workspace. The demo covers products, readiness, sample imports and passport previews.'] }}</p>
        <p>{{ $copy['demo_detail'] }}</p>
        <a class="btn btn-primary" href="{{ route('register', $showcaseLocale === 'en' ? [] : ['lang' => $showcaseLocale]) }}">{{ $copy['reference']['Create a workspace'] }}</a>
        <a class="text-button" href="{{ route('login', $showcaseLocale === 'en' ? [] : ['lang' => $showcaseLocale]) }}">{{ $copy['signin'] }}</a>
    </dialog>
    <script type="application/json" id="showcase-copy" nonce="@cspNonce">@json($copy)</script>
</div>
</body>
</html>
