<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="NordiPass helps teams create, manage, and publish Digital Product Passports from product data, documents, readiness checks, and QR links.">
    <title>NordiPass - Digital Product Passports without the complexity</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        html {
            scroll-behavior: smooth;
        }

        .landing-qr svg {
            display: block;
            height: auto;
            width: 100%;
        }
    </style>
</head>
<body class="bg-[#f6f8f7] font-sans text-[#06112f] antialiased">
<div x-data="{ mobileMenuOpen: false }" class="min-h-screen overflow-x-hidden" @keydown.escape.window="mobileMenuOpen = false">
    <header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
        <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-5 sm:px-6 lg:px-8">
            <a href="{{ url('/') }}" class="flex items-center gap-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">
                <x-application-logo class="h-8 w-8 text-emerald-700" aria-hidden="true" />
                <span class="text-2xl font-bold tracking-tight text-[#06112f]">NordiPass</span>
            </a>

            <nav class="hidden items-center gap-8 text-sm font-semibold text-slate-700 md:flex" aria-label="Primary navigation">
                <a href="#how-it-works" class="transition hover:text-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-4">How it works</a>
                <a href="#integrations" class="transition hover:text-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-4">Integrations</a>
                <a href="#pricing" class="transition hover:text-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-4">Pricing</a>
                <a href="#resources" class="inline-flex items-center gap-1 transition hover:text-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-4">
                    Resources
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                    </svg>
                </a>
            </nav>

            <div class="hidden items-center gap-3 md:flex">
                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-2 text-sm font-semibold text-[#06112f] transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2" aria-label="Select language">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                        <circle cx="10" cy="10" r="7.5" />
                        <path stroke-linecap="round" d="M2.5 10h15M10 2.5c2 2.1 3 4.6 3 7.5s-1 5.4-3 7.5M10 2.5c-2 2.1-3 4.6-3 7.5s1 5.4 3 7.5" />
                    </svg>
                    EN
                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                    </svg>
                </button>
                <a href="{{ route('login') }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-[#06112f] transition hover:border-slate-300 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">Sign in</a>
                <a href="{{ route('register') }}" class="rounded-lg bg-emerald-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">Start free trial</a>
            </div>

            <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-700 md:hidden" @click="mobileMenuOpen = !mobileMenuOpen" :aria-expanded="mobileMenuOpen.toString()" aria-controls="mobile-landing-menu">
                <span class="sr-only">Toggle navigation</span>
                <svg x-show="!mobileMenuOpen" class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M3 5h14M3 10h14M3 15h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
                <svg x-cloak x-show="mobileMenuOpen" class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="m5 5 10 10M15 5 5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
            </button>
        </div>

        <div x-cloak x-show="mobileMenuOpen" x-transition id="mobile-landing-menu" class="border-t border-slate-200 bg-white px-5 py-4 md:hidden">
            <nav class="grid gap-2 text-sm font-semibold text-slate-700" aria-label="Mobile navigation">
                <a href="#how-it-works" @click="mobileMenuOpen = false" class="rounded-lg px-3 py-2 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-700">How it works</a>
                <a href="#integrations" @click="mobileMenuOpen = false" class="rounded-lg px-3 py-2 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-700">Integrations</a>
                <a href="#pricing" @click="mobileMenuOpen = false" class="rounded-lg px-3 py-2 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-700">Pricing</a>
                <a href="#resources" @click="mobileMenuOpen = false" class="rounded-lg px-3 py-2 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-700">Resources</a>
                <button type="button" class="flex items-center gap-2 rounded-lg px-3 py-2 text-left hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-700">
                    <span>Language</span>
                    <span class="ml-auto font-bold">EN</span>
                </button>
                <a href="{{ route('login') }}" class="rounded-lg px-3 py-2 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-700">Sign in</a>
                <a href="{{ route('register') }}" class="mt-2 rounded-lg bg-emerald-900 px-3 py-3 text-center text-white focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">Start free trial</a>
            </nav>
        </div>
    </header>

    <main>
        <section class="relative isolate border-b border-slate-200 bg-white">
            <div class="mx-auto grid max-w-7xl gap-12 px-5 py-16 sm:px-6 md:py-20 lg:grid-cols-[0.9fr_1.1fr] lg:items-center lg:px-8 lg:py-24">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Digital Product Passports</p>
                    <h1 class="mt-5 max-w-4xl text-4xl font-semibold leading-[1.04] tracking-tight text-[#06112f] sm:text-5xl lg:text-6xl">
                        Digital Product Passports without the complexity
                    </h1>
                    <p class="mt-6 max-w-2xl text-lg leading-8 text-slate-600">
                        Create, manage and publish digital product passports. Import product data, attach documentation, track readiness and publish via QR.
                    </p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a href="{{ route('register') }}" class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-900 px-6 py-3.5 text-base font-semibold text-white shadow-sm transition hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">
                            Start free trial
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <path d="M4 10h12m0 0-4.5-4.5M16 10l-4.5 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                        <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-6 py-3.5 text-base font-semibold text-[#06112f] transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">Sign in</a>
                    </div>
                    <p class="mt-5 text-sm font-medium text-slate-600">14-day pilot &middot; No credit card required</p>
                </div>

                <div class="relative lg:min-h-[520px]" role="img" aria-label="NordiPass product passport interface preview">
                    <div class="absolute right-0 top-0 hidden h-full w-[78%] overflow-hidden bg-slate-100 lg:block" aria-hidden="true">
                        <div class="absolute inset-y-0 left-0 w-[72%] bg-slate-100">
                            <div class="h-full w-full bg-[linear-gradient(90deg,rgba(148,163,184,0.16)_1px,transparent_1px),linear-gradient(0deg,rgba(148,163,184,0.12)_1px,transparent_1px)] bg-[size:56px_56px]"></div>
                        </div>
                        <div class="absolute inset-y-0 right-0 w-[34%] overflow-hidden">
                            <img src="{{ asset('images/marketing/nordic-forest-panel.jpg') }}" alt="" class="h-full w-full object-cover">
                            <div class="absolute inset-0 bg-emerald-950/10"></div>
                        </div>
                    </div>
                    <div class="absolute right-5 top-10 z-10 hidden max-w-[150px] text-right text-xs font-semibold uppercase leading-5 tracking-[0.28em] text-white lg:block">
                        A more transparent tomorrow
                        <span class="ml-auto mt-3 block h-px w-20 bg-white/80"></span>
                    </div>

                    <div class="relative rounded-2xl border border-slate-200 bg-white shadow-lg shadow-slate-200/60 lg:ml-4 lg:mr-24 lg:mt-4">
                        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3 sm:px-5">
                            <div class="flex items-center gap-2">
                                <x-application-logo class="h-5 w-5 text-emerald-700" aria-hidden="true" />
                                <span class="text-sm font-bold">NordiPass</span>
                            </div>
                            <div class="flex gap-1.5 text-slate-400" aria-hidden="true">
                                <span class="h-2 w-2 rounded-full bg-slate-300"></span>
                                <span class="h-2 w-2 rounded-full bg-slate-300"></span>
                            </div>
                        </div>

                        <div class="px-4 py-5 sm:px-5">
                            <p class="text-xs font-medium text-slate-500">Products / Industrial Storage Case</p>
                            <div class="mt-3 flex flex-wrap items-center gap-3">
                                <h2 class="text-xl font-bold text-[#06112f]">Industrial Storage Case</h2>
                                <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">Draft</span>
                            </div>
                            <p class="mt-1 text-sm font-medium text-slate-500">ISC-001</p>

                            <div class="mt-5 flex gap-6 border-b border-slate-200 text-sm font-semibold">
                                <span class="border-b-2 border-emerald-800 pb-3 text-[#06112f]">Overview</span>
                                <span class="pb-3 text-slate-500">Compliance</span>
                                <span class="pb-3 text-slate-500">Documents</span>
                                <span class="hidden pb-3 text-slate-500 sm:inline">History</span>
                            </div>

                            <div class="mt-5 grid gap-4 lg:grid-cols-[1fr_0.75fr]">
                                <div class="rounded-lg border border-slate-200 bg-white p-4">
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="font-medium text-slate-600">Passport readiness</span>
                                        <span class="font-bold text-[#06112f]">89%</span>
                                    </div>
                                    <div class="mt-3 h-2.5 rounded-full bg-slate-100">
                                        <div class="h-2.5 w-[89%] rounded-full bg-emerald-600"></div>
                                    </div>
                                    <div class="mt-5 space-y-3 text-sm">
                                        <div class="flex items-center gap-3 text-slate-700">
                                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-xs font-bold text-white">&check;</span>
                                            <span>Manufacturer information</span>
                                        </div>
                                        <div class="flex items-center gap-3 text-slate-700">
                                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-xs font-bold text-white">&check;</span>
                                            <span>Technical documentation</span>
                                        </div>
                                        <div class="flex items-center gap-3 text-slate-700">
                                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-amber-400 text-xs font-bold text-amber-950">!</span>
                                            <span>Fire rating missing</span>
                                        </div>
                                        <div class="flex items-center gap-3 text-slate-700">
                                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-amber-400 text-xs font-bold text-amber-950">!</span>
                                            <span>Declaration expires in 17 days</span>
                                        </div>
                                    </div>
                                    <div class="mt-5 grid grid-cols-2 gap-3">
                                        <button type="button" class="rounded-lg bg-emerald-900 px-3 py-3 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">Publish passport</button>
                                        <button type="button" class="rounded-lg border border-slate-300 bg-white px-3 py-3 text-sm font-semibold text-[#06112f] focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">Preview</button>
                                    </div>
                                </div>

                                <div class="flex min-h-56 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-5 py-6">
                                    <img src="{{ asset('images/marketing/industrial-storage-case-hd.png') }}" alt="Industrial Storage Case" class="h-auto w-full max-w-[245px] object-contain" width="1330" height="1183">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="relative mx-auto mt-6 w-full max-w-xs rounded-[1.75rem] border border-slate-900 bg-slate-950 p-2 shadow-lg shadow-slate-300/50 lg:absolute lg:bottom-0 lg:right-2 lg:mx-0 lg:mt-0 lg:w-48">
                        <div class="rounded-[1.25rem] bg-white px-3 py-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-1.5">
                                    <x-application-logo class="h-4 w-4 shrink-0 text-emerald-700" aria-hidden="true" />
                                    <span class="text-xs font-bold">NordiPass</span>
                                </div>
                                <span class="text-[10px] font-semibold text-slate-500">EN</span>
                            </div>
                            <p class="mt-4 text-[10px] font-medium text-slate-500">Product Passport</p>
                            <h3 class="mt-1 text-sm font-bold leading-tight">Industrial Storage Case</h3>
                            <div class="mt-4 rounded-lg bg-slate-100 px-2 py-3">
                                <img src="{{ asset('images/marketing/industrial-storage-case-hd.png') }}" alt="Industrial Storage Case" class="mx-auto h-auto w-28 object-contain" width="1330" height="1183">
                            </div>
                            <ul class="mt-4 space-y-2 text-[10px] font-medium text-slate-600">
                                <li>Manufacturer</li>
                                <li>Technical data</li>
                                <li>Sustainability</li>
                                <li>Documents</li>
                            </ul>
                            <div class="landing-qr mx-auto mt-4 w-20 overflow-hidden rounded-md border border-slate-200 bg-white p-1">
                                {!! $landingQrSvg !!}
                            </div>
                            <p class="mt-2 text-center text-[9px] font-medium text-slate-500">{{ $landingQrIsPassport ? 'Scan to view passport' : 'Scan to start a trial' }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="resources" aria-label="NordiPass advantages" class="border-b border-slate-200 bg-white">
            <div class="mx-auto grid max-w-7xl gap-4 px-5 py-8 sm:grid-cols-2 sm:px-6 lg:grid-cols-5 lg:px-8">
                @foreach ([
                    'Import products from Fortnox / CSV',
                    'See what data is missing',
                    'Manage documents and product data',
                    'Publish immutable passport versions',
                    'Share via QR / public link',
                ] as $value)
                    <div class="flex items-start gap-3 text-sm font-semibold text-slate-700">
                        <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-700">&check;</span>
                        <span>{{ $value }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section id="how-it-works" class="mx-auto max-w-7xl px-5 py-16 sm:px-6 lg:px-8">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8 lg:p-10">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">How it works</p>
                <h2 class="mt-4 max-w-4xl text-3xl font-semibold tracking-tight text-[#06112f] sm:text-4xl">
                    From product data to a published passport &mdash; in a few simple steps
                </h2>

                <div class="mt-10 grid gap-8 lg:grid-cols-[1fr_1fr_1fr_0.9fr] lg:items-stretch">
                    <article class="border-slate-200 lg:border-r lg:pr-8">
                        <div class="flex items-center gap-4">
                            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-emerald-100 text-lg font-bold text-[#06112f]">1</span>
                            <div>
                                <h3 class="font-bold">Import your products</h3>
                                <p class="mt-1 text-sm leading-6 text-slate-600">Fortnox / CSV to Product</p>
                            </div>
                        </div>
                        <div class="mt-8 flex items-center gap-4">
                            <div class="grid h-20 w-24 place-items-center rounded-lg border border-slate-200 bg-slate-50">
                                <span class="rounded-md bg-[#65ad2f] px-3 py-2 text-lg font-bold text-white">F</span>
                            </div>
                            <svg class="h-6 w-10 text-slate-400" viewBox="0 0 40 24" fill="none" aria-hidden="true">
                                <path d="M3 12h31m0 0-6-6m6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <div class="min-w-0 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                                <img src="{{ asset('images/marketing/industrial-storage-case-hd.png') }}" alt="" class="h-12 w-16 object-contain" width="1330" height="1183" loading="lazy" aria-hidden="true">
                                <p class="mt-2 truncate text-xs font-bold">Industrial Storage Case</p>
                                <p class="text-xs text-slate-500">ISC-001</p>
                            </div>
                        </div>
                    </article>

                    <article class="border-slate-200 lg:border-r lg:pr-8">
                        <div class="flex items-center gap-4">
                            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-emerald-100 text-lg font-bold text-[#06112f]">2</span>
                            <div>
                                <h3 class="font-bold">Complete and manage</h3>
                                <p class="mt-1 text-sm leading-6 text-slate-600">Attach data, evidence, and documents.</p>
                            </div>
                        </div>
                        <div class="mt-8 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="space-y-3 text-sm">
                                <p class="flex items-center gap-2"><span class="text-emerald-600">&check;</span> Manufacturer information</p>
                                <p class="flex items-center gap-2"><span class="text-emerald-600">&check;</span> Technical documentation</p>
                                <p class="flex items-center gap-2"><span class="text-amber-600">!</span> Fire rating missing</p>
                                <p class="flex items-center gap-2"><span class="text-amber-600">!</span> Declaration expires in 17 days</p>
                            </div>
                        </div>
                    </article>

                    <article class="border-slate-200 lg:border-r lg:pr-8">
                        <div class="flex items-center gap-4">
                            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-emerald-100 text-lg font-bold text-[#06112f]">3</span>
                            <div>
                                <h3 class="font-bold">Publish and share</h3>
                                <p class="mt-1 text-sm leading-6 text-slate-600">Product to published passport and QR.</p>
                            </div>
                        </div>
                        <div class="mt-8 flex items-center gap-4">
                            <div class="landing-qr w-20 rounded-lg border border-slate-200 bg-white p-2 shadow-sm">{!! $landingQrSvg !!}</div>
                            <svg class="h-6 w-10 text-slate-400" viewBox="0 0 40 24" fill="none" aria-hidden="true">
                                <path d="M3 12h31m0 0-6-6m6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <p class="text-xs font-semibold text-slate-500">Product Passport</p>
                                <span class="mt-3 inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Published</span>
                            </div>
                        </div>
                    </article>

                    <aside class="rounded-xl bg-emerald-50 p-6">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-900">Built for compliance. Designed for real business.</p>
                        <p class="mt-6 text-2xl font-semibold leading-tight text-[#06112f]">Turn regulatory requirements into a manageable Digital Product Passport workflow.</p>
                        <a href="#integrations" class="mt-6 inline-flex items-center gap-2 text-sm font-semibold text-emerald-900 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-4">
                            Learn more
                            <span aria-hidden="true">&rarr;</span>
                        </a>
                    </aside>
                </div>
            </div>
        </section>

        <section id="integrations" class="border-y border-slate-200 bg-white">
            <div class="mx-auto max-w-7xl px-5 py-16 text-center sm:px-6 lg:px-8">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Integrations</p>
                <h2 class="mt-3 text-3xl font-semibold tracking-tight text-[#06112f]">Works with your existing tools</h2>
                <div class="mt-10 grid gap-4 text-left md:grid-cols-3">
                    <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex items-center gap-4">
                            <span class="grid h-12 w-12 place-items-center rounded-lg bg-[#65ad2f] text-xl font-bold text-white">F</span>
                            <div>
                                <h3 class="font-bold">Fortnox</h3>
                                <p class="mt-1 text-sm text-slate-600">Import products and keep data in sync.</p>
                            </div>
                        </div>
                    </article>
                    <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex items-center gap-4">
                            <span class="grid h-12 w-12 place-items-center rounded-lg border border-slate-200 text-sm font-bold text-slate-700">CSV</span>
                            <div>
                                <h3 class="font-bold">CSV Import</h3>
                                <p class="mt-1 text-sm text-slate-600">Import from Excel or CSV files.</p>
                            </div>
                        </div>
                    </article>
                    <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex items-center gap-4">
                            <span class="grid h-12 w-12 place-items-center rounded-lg border border-slate-200 text-lg font-bold text-emerald-900">&lt;/&gt;</span>
                            <div>
                                <h3 class="font-bold">API</h3>
                                <p class="mt-1 text-sm text-slate-600">Use the documented API for custom integrations.</p>
                            </div>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <section id="pricing" class="mx-auto max-w-7xl px-5 py-16 sm:px-6 lg:px-8">
            <div class="grid gap-8 rounded-xl border border-slate-200 bg-white p-6 shadow-sm lg:grid-cols-[0.8fr_1fr] lg:items-center lg:p-10">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Pricing</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-[#06112f]">Start with a focused pilot.</h2>
                    <p class="mt-4 max-w-xl leading-7 text-slate-600">Validate your Digital Product Passport workflow with real products, documents, readiness checks, and QR sharing before scaling.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <article class="rounded-lg border border-slate-200 p-6">
                        <h3 class="text-lg font-bold">Pilot</h3>
                        <p class="mt-2 text-sm text-slate-600">14-day pilot &middot; No credit card required</p>
                        <a href="{{ route('register') }}" class="mt-6 inline-flex w-full justify-center rounded-lg bg-emerald-900 px-4 py-3 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">Start free trial</a>
                    </article>
                    <article class="rounded-lg border border-slate-200 p-6">
                        <h3 class="text-lg font-bold">Already have an account?</h3>
                        <p class="mt-2 text-sm text-slate-600">Sign in to continue managing products, passports, and documents.</p>
                        <a href="{{ route('login') }}" class="mt-6 inline-flex w-full justify-center rounded-lg border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-[#06112f] focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">Sign in</a>
                    </article>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl flex-col gap-4 px-5 py-8 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
            <p>&copy; {{ date('Y') }} NordiPass</p>
            <nav class="flex flex-wrap gap-x-5 gap-y-2" aria-label="Footer navigation">
                <a href="#how-it-works" class="hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-4">How it works</a>
                <a href="#integrations" class="hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-4">Integrations</a>
                <a href="#pricing" class="hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-4">Pricing</a>
                <a href="{{ route('login') }}" class="hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-4">Sign in</a>
            </nav>
        </div>
    </footer>
</div>
</body>
</html>
