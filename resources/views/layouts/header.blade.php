@php
    $text = [\App\Support\DisplayText::class, 'make'];
    $companyName = fn ($company, string $fallback = ''): string => $text($company?->name ?? null, $fallback);
    $userName = $text(Auth::user()->name, Auth::user()->email);
    $nameParts = preg_split('/\s+/', trim($userName)) ?: [];
    $initials = strtoupper(mb_substr($nameParts[0] ?? '', 0, 1)) . (isset($nameParts[1]) ? strtoupper(mb_substr($nameParts[1], 0, 1)) : '');
    $unreadNotificationCount = 0;
    if ($currentCompany && auth()->check()) {
        $unreadNotificationCount = \App\Models\Notifications\Notification::query()
            ->forCompany($currentCompany)
            ->where('user_id', auth()->id())
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->count();
    }
@endphp

<header class="sticky top-0 z-30 flex h-[72px] items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 sm:px-6 lg:px-8">
    <div class="flex min-w-0 items-center gap-3">
        <button type="button" @click="sidebarOpen = !sidebarOpen" :aria-expanded="sidebarOpen" aria-controls="mobile-navigation" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 lg:hidden">
            <span class="sr-only">{{ __('Toggle navigation') }}</span>
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path x-show="!sidebarOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                <path x-cloak x-show="sidebarOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>

        <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-2 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 lg:hidden">
            <x-application-logo class="h-8 w-8 text-emerald-800" />
            <span class="text-lg font-bold tracking-tight text-slate-900">NordiPass</span>
        </a>
    </div>

    <form action="{{ route('catalog.products.index') }}" method="GET" role="search" class="hidden min-w-0 max-w-xl flex-1 md:block" x-data="globalSearch" x-ref="form" data-suggestions-url="{{ route('catalog.products.suggestions') }}" @submit.prevent="go" @click.outside="close" @keydown.escape="close">
        <label for="global-search" class="sr-only">{{ __('Search products') }}</label>
        <div class="relative">
            <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" d="m14.5 14.5 3 3" />
                <circle cx="8.75" cy="8.75" r="5.75" />
            </svg>
            <input id="global-search" name="q" type="search" value="{{ request()->routeIs('catalog.products.index') ? $text(request('q')) : '' }}" placeholder="{{ __('Search products...') }}" class="h-11 w-full rounded-lg border-slate-200 bg-slate-50 pl-12 pr-4 text-sm text-slate-700 shadow-sm placeholder:text-slate-400 focus:border-emerald-700 focus:ring-emerald-700" x-ref="input" @input="onInput($event)" @focus="onFocus" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" :aria-expanded="open" aria-controls="global-search-results" autocomplete="off">
            <div id="global-search-results" x-cloak x-show="open" class="absolute left-0 right-0 top-full z-50 mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg" aria-live="polite">
                <p x-show="loading" class="px-4 py-3 text-sm text-slate-500">{{ __('Searching...') }}</p>
                <p x-show="!loading && error" class="px-4 py-3 text-sm text-red-700">{{ __('Search unavailable') }}</p>
                <p x-show="!loading && !error && items.length === 0" class="px-4 py-3 text-sm text-slate-500">{{ __('No matching products') }}</p>
                <div x-show="!loading && !error" class="max-h-80 overflow-y-auto">
                    <template x-for="(item, index) in items" :key="item.url">
                        <a :href="item.url" class="block px-4 py-2.5 hover:bg-slate-50 focus:bg-slate-50 focus:outline-none" :class="{ 'bg-slate-50': activeIndex === index }">
                            <span class="block truncate text-sm font-semibold text-slate-900" x-text="item.name"></span>
                            <span x-show="item.brand" class="block truncate text-xs text-slate-500" x-text="item.brand"></span>
                        </a>
                    </template>
                </div>
                <a :href="resultsUrl()" class="block border-t border-slate-100 px-4 py-2.5 text-sm font-semibold text-emerald-800 hover:bg-slate-50">{{ __('View all results') }}</a>
            </div>
        </div>
    </form>

    <div class="flex shrink-0 items-center gap-3">
        <a href="{{ route('notifications.index') }}" class="relative hidden rounded-lg p-2 text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-700 sm:inline-flex" aria-label="{{ __('Notifications') }}">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022 23.848 23.848 0 0 0 5.455 1.31m5.714 0a3 3 0 1 1-5.714 0" />
            </svg>
            @if ($unreadNotificationCount > 0)
                <span class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-red-500 ring-2 ring-white" data-testid="header-notification-unread-indicator"></span>
            @endif
        </a>

        <a href="{{ route('onboarding.help') }}" class="hidden rounded-lg p-2 text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-700 sm:inline-flex" aria-label="{{ __('Help') }}">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                <circle cx="12" cy="12" r="8.5" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.5a2.4 2.4 0 1 1 3.95 1.85c-.95.78-1.45 1.2-1.45 2.15M12 16.75h.01" />
            </svg>
        </a>

        @if ($currentCompany && $availableCompanies->count() > 1)
            <div class="relative hidden lg:block" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                <button type="button" @click="open = !open" :aria-expanded="open" aria-haspopup="menu" class="flex max-w-64 items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm transition hover:border-slate-300 hover:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-700">
                    <span class="truncate font-semibold text-slate-900">{{ $companyName($currentCompany) }}</span>
                    <svg class="h-4 w-4 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </button>

                <div x-cloak x-show="open" x-transition class="absolute right-0 z-50 mt-2 w-72 overflow-hidden rounded-xl border border-slate-200 bg-white p-2 shadow-xl" role="menu">
                    <p class="px-3 py-2 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Switch company') }}</p>
                    @foreach ($availableCompanies as $companyOption)
                        @if ($currentCompany->is($companyOption))
                            <div class="flex items-center justify-between rounded-lg bg-indigo-50 px-3 py-2 text-sm" aria-current="true">
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold text-indigo-900">{{ $companyName($companyOption) }}</span>
                                    <span class="block text-xs capitalize text-indigo-600">{{ $companyOption->pivot->role->value }}</span>
                                </span>
                                <span class="ml-2 shrink-0 text-xs font-semibold text-indigo-700">{{ __('Current') }}</span>
                            </div>
                        @else
                            <form method="POST" action="{{ route('companies.switch', $companyOption) }}" role="none">
                                @csrf
                                <button type="submit" class="block w-full rounded-lg px-3 py-2 text-left text-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500" role="menuitem">
                                    <span class="block truncate font-semibold text-slate-800">{{ $companyName($companyOption) }}</span>
                                    <span class="block text-xs capitalize text-slate-500">{{ $companyOption->pivot->role->value }}</span>
                                </button>
                            </form>
                        @endif
                    @endforeach
                </div>
            </div>
        @elseif ($currentCompany)
            <div class="hidden max-w-56 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm lg:block">
                <span class="block truncate font-semibold text-slate-900">{{ $companyName($currentCompany) }}</span>
            </div>
        @endif

        <x-dropdown align="right" width="w-64" contentClasses="bg-white">
            <x-slot name="trigger">
                <button type="button" data-testid="user-menu" aria-label="User menu" class="inline-flex items-center gap-2.5 rounded-xl p-1.5 pr-2 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-700 sm:pr-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-700 text-sm font-semibold text-white" aria-hidden="true">{{ $initials }}</span>
                    <span class="hidden max-w-40 text-left sm:block">
                        <span class="block truncate text-sm font-semibold leading-4 text-slate-900">{{ $userName }}</span>
                        <span class="mt-0.5 block truncate text-xs leading-4 text-slate-500">{{ $currentCompany ? $companyName($currentCompany) : Auth::user()->email }}</span>
                    </span>
                    <svg class="hidden h-4 w-4 shrink-0 text-slate-400 sm:block" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </button>
            </x-slot>

            <x-slot name="content">
                <div class="px-4 py-3">
                    <p class="break-words text-sm font-semibold text-slate-900">{{ $userName }}</p>
                    <p class="mt-0.5 break-all text-xs text-slate-500">{{ Auth::user()->email }}</p>
                    @if ($currentMembership)
                        <p class="mt-2 text-xs text-slate-500">{{ __('Role') }}: <span class="font-semibold capitalize text-slate-700">{{ $currentMembership->role->value }}</span></p>
                    @endif
                </div>
                <div class="border-t border-slate-200 py-1">
                    <x-dropdown-link :href="route('profile.edit')">{{ __('Profile') }}</x-dropdown-link>
                </div>
                <div class="border-t border-slate-200 py-1">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="block w-full px-4 py-2 text-left text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none">{{ __('Log out') }}</button>
                    </form>
                </div>
            </x-slot>
        </x-dropdown>
    </div>
</header>
