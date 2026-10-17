<!DOCTYPE html>
<html lang="{{ $passport->requestedLocale ?? $passport->defaultLanguage }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $passport->pageTitle }}</title>

    @if($passport->metaDescription !== '')
    <meta name="description" content="{{ $passport->metaDescription }}">
    @endif

    @unless($isPreview ?? false)
    <link rel="canonical" href="{{ $passport->canonicalUrl }}">
    @endunless

    <meta property="og:title" content="{{ $passport->pageTitle }}">
    @if($passport->metaDescription !== '')
    <meta property="og:description" content="{{ $passport->metaDescription }}">
    @endif
    <meta property="og:type" content="product">
    <meta property="og:url" content="{{ $passport->canonicalUrl }}">
    @if($passport->ogImageUrl !== null)
    <meta property="og:image" content="{{ $passport->ogImageUrl }}">
    @endif

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $passport->pageTitle }}">
    @if($passport->metaDescription !== '')
    <meta name="twitter:description" content="{{ $passport->metaDescription }}">
    @endif

    <meta name="robots" content="{{ ($isPreview ?? false) ? 'noindex, nofollow' : 'index, follow' }}">
    <meta name="format-detection" content="telephone=no">

    @if(!($isPreview ?? false) && isset($passport->enabledLocales) && count($passport->enabledLocales) > 1)
        @foreach($passport->enabledLocales as $locale)
            <link rel="alternate" hreflang="{{ $locale }}" href="{{ url('p/'.$passport->passportPublicId.'?lang='.$locale) }}">
        @endforeach
        <link rel="alternate" hreflang="x-default" href="{{ url('p/'.$passport->passportPublicId) }}">
    @endif

    @unless($isPreview ?? false)
    <script type="application/ld+json" nonce="@cspNonce">{!! $passport->jsonLd !!}</script>
    @endunless

    @vite(['resources/css/app.css'])
    <style>
        .passport-section { margin-bottom: 2rem; }
        .passport-section h2 { font-size: 1.25rem; font-weight: 600; margin-bottom: 0.75rem; padding-bottom: 0.25rem; border-bottom: 2px solid #e2e8f0; }
        .passport-section h3 { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem; }
        .quick-fact { display: flex; align-items: baseline; gap: 0.5rem; padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9; }
        .quick-fact dt { font-weight: 600; min-width: 10rem; color: #475569; font-size: 0.875rem; }
        .quick-fact dd { color: #1e293b; font-size: 0.875rem; }
        .safety-badge { display: inline-flex; align-items: center; gap: 0.5rem; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 0.75rem 1rem; border-radius: 0.5rem; font-weight: 600; }
        .safety-neutral { display: inline-flex; align-items: center; gap: 0.5rem; background: #f8fafc; border: 1px solid #e2e8f0; color: #475569; padding: 0.75rem 1rem; border-radius: 0.5rem; font-weight: 600; }
        .recycling-badge { display: inline-flex; align-items: center; gap: 0.5rem; background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 0.75rem 1rem; border-radius: 0.5rem; font-weight: 600; }
        .document-card { border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 1rem; margin-bottom: 0.75rem; }
        .document-card dt { font-size: 0.75rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
        .document-card dd { font-size: 0.875rem; color: #334155; }
        .document-card .download-link { display: inline-flex; align-items: center; gap: 0.5rem; background: #2563eb; color: #fff; padding: 0.5rem 1rem; border-radius: 0.375rem; text-decoration: none; font-weight: 500; font-size: 0.875rem; }
        .document-card .download-link:hover { background: #1d4ed8; }
        @media (max-width: 640px) {
            .quick-fact { flex-direction: column; gap: 0.125rem; }
            .quick-fact dt { min-width: auto; }
        }
    </style>
</head>
<body class="np-prototype np-public-passport bg-white antialiased" data-public-passport-v2="true">
<div class="np-site">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:bg-blue-600 focus:text-white focus:px-4 focus:py-2 focus:rounded">{{ __('Skip to main content') }}</a>

    <header class="border-b border-slate-200 bg-slate-50" role="banner">
        <div class="mx-auto max-w-4xl px-4 py-4 sm:px-6 lg:px-8 flex items-center justify-between">
            <a href="{{ route('home') }}" class="np-brand"><x-application-logo class="h-9 w-9 text-emerald-800" aria-hidden="true" /><span>{{ __('NordiPass') }}<span class="text-emerald-800">.</span></span></a>
            <div class="text-xs font-semibold uppercase tracking-wider text-slate-600">{{ __('Digital Product Passport') }}</div>
        </div>
    </header>

    <main id="main-content" class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8 pb-16" tabindex="-1">
        @if($isPreview ?? false)
            <div class="mb-6 rounded-lg border-2 border-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
                <div class="font-semibold">{{ __('Draft preview — not public') }}</div>
                <div>{{ __('This is generated from the current mutable draft. Publishing will create an immutable version after a fresh readiness check.') }}</div>
            </div>
        @endif
        @yield('content')
    </main>

    <footer class="border-t border-slate-200 bg-slate-50 mt-8" role="contentinfo">
        <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8 text-xs text-slate-500 space-y-2">
            <p>{{ __('This Digital Product Passport is based on information published by the responsible company. NordiPass provides the technical platform and does not independently certify the product or its legal compliance.') }}</p>
            @unless($isPreview ?? false)
                <p id="privacy-notice">{{ __('Privacy: this public page does not require sign-in and does not set tracking cookies. Anonymous, aggregate view analytics may be recorded without raw IP addresses, full user-agent strings, or full referrer URLs.') }}</p>
            @endunless
        </div>
    </footer>
</div>
</body>
</html>
