@php
    $userName = Auth::user()->name;
    $nameParts = preg_split('/\s+/', trim($userName)) ?: [];
    $initials = strtoupper(mb_substr($nameParts[0] ?? '', 0, 1)) . (isset($nameParts[1]) ? strtoupper(mb_substr($nameParts[1], 0, 1)) : '');
@endphp

<header class="sticky top-0 z-30 flex h-16 items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 sm:px-6 lg:px-8">
    <div class="flex min-w-0 items-center gap-3">
        <button type="button" @click="sidebarOpen = !sidebarOpen" :aria-expanded="sidebarOpen" aria-controls="mobile-navigation" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 lg:hidden">
            <span class="sr-only">{{ __('Toggle navigation') }}</span>
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path x-show="!sidebarOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                <path x-cloak x-show="sidebarOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>

        <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-2 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 lg:hidden">
            <x-application-logo class="h-8 w-8 text-indigo-600" />
            <span class="text-lg font-bold tracking-tight text-slate-900">NordiPass</span>
        </a>
    </div>

    <div class="flex shrink-0 items-center gap-3">
        @if ($currentCompany && $availableCompanies->count() > 1)
            <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                <button type="button" @click="open = !open" :aria-expanded="open" aria-haspopup="menu" class="flex max-w-64 items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm transition hover:border-slate-300 hover:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <span class="truncate font-semibold text-slate-900">{{ $currentCompany->name }}</span>
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
                                    <span class="block truncate font-semibold text-indigo-900">{{ $companyOption->name }}</span>
                                    <span class="block text-xs capitalize text-indigo-600">{{ $companyOption->pivot->role->value }}</span>
                                </span>
                                <span class="ml-2 shrink-0 text-xs font-semibold text-indigo-700">{{ __('Current') }}</span>
                            </div>
                        @else
                            <form method="POST" action="{{ route('companies.switch', $companyOption) }}" role="none">
                                @csrf
                                <button type="submit" class="block w-full rounded-lg px-3 py-2 text-left text-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500" role="menuitem">
                                    <span class="block truncate font-semibold text-slate-800">{{ $companyOption->name }}</span>
                                    <span class="block text-xs capitalize text-slate-500">{{ $companyOption->pivot->role->value }}</span>
                                </button>
                            </form>
                        @endif
                    @endforeach
                </div>
            </div>
        @elseif ($currentCompany)
            <div class="max-w-56 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                <span class="block truncate font-semibold text-slate-900">{{ $currentCompany->name }}</span>
            </div>
        @endif

        <x-dropdown align="right" width="w-64" contentClasses="bg-white">
            <x-slot name="trigger">
                <button type="button" data-testid="user-menu" aria-label="User menu" class="inline-flex items-center gap-2.5 rounded-xl p-1.5 pr-2 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 sm:pr-3">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-semibold text-white" aria-hidden="true">{{ $initials }}</span>
                    <span class="hidden max-w-40 text-left sm:block">
                        <span class="block truncate text-sm font-semibold leading-4 text-slate-900">{{ $userName }}</span>
                        <span class="mt-0.5 block truncate text-xs leading-4 text-slate-500">{{ Auth::user()->email }}</span>
                    </span>
                    <svg class="hidden h-4 w-4 shrink-0 text-slate-400 sm:block" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </button>
            </x-slot>

            <x-slot name="content">
                <div class="px-4 py-3">
                    <p class="text-sm font-semibold text-slate-900">{{ $userName }}</p>
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
                        <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">{{ __('Log out') }}</x-dropdown-link>
                    </form>
                </div>
            </x-slot>
        </x-dropdown>
    </div>
</header>
