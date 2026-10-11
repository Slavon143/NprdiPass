@php
    $text = [\App\Support\DisplayText::class, 'make'];
    $companyName = fn ($company, string $fallback = ''): string => $text($company?->name ?? null, $fallback);
@endphp

<aside id="desktop-navigation" class="desktop-sidebar fixed inset-y-0 left-0 z-40 hidden w-64 flex-col border-r border-slate-200 bg-white lg:flex" aria-label="Primary navigation" data-testid="primary-navigation">
    <div class="sidebar-brand flex h-[72px] shrink-0 items-center justify-between gap-2 border-b border-slate-200 px-4">
        <a href="{{ route('dashboard') }}" aria-label="NordiPass" class="flex min-w-0 items-center gap-2 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">
            <x-application-logo class="h-8 w-8 shrink-0 text-emerald-700" aria-hidden="true" />
            <span class="sidebar-brand-name text-2xl font-bold tracking-tight text-slate-900">NordiPass<span class="text-emerald-800">.</span></span>
        </a>
        <button type="button" @click="toggleSidebar" :aria-expanded="!sidebarCollapsed" aria-controls="desktop-navigation" title="{{ __('Toggle sidebar') }}" class="sidebar-toggle rounded-lg p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-700" data-testid="sidebar-toggle">
            <span class="sr-only">{{ __('Toggle sidebar') }}</span>
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="m12 5-5 5 5 5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
    </div>

    @if($currentCompany)
        <a href="{{ route('settings.company.edit') }}" class="company-switch auth-company-switch">
            <span class="company-avatar">{{ mb_strtoupper(mb_substr($companyName($currentCompany), 0, 2)) }}</span>
            <span><strong>{{ $companyName($currentCompany) }}</strong><small>{{ $currentMembership?->role->value }}</small></span>
        </a>
    @endif
    @include('layouts.partials.sidebar-nav')
</aside>

<div x-cloak x-show="sidebarOpen" x-transition.opacity id="mobile-navigation" class="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Navigation">
    <div class="fixed inset-0 bg-slate-900/50" @click="sidebarOpen = false" aria-hidden="true"></div>

    <div class="fixed inset-y-0 left-0 flex w-72 max-w-[85%] flex-col bg-white shadow-xl" @click.away="sidebarOpen = false">
        <div class="flex h-16 shrink-0 items-center justify-between border-b border-slate-200 px-5">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">
                <x-application-logo class="h-8 w-8 text-emerald-700" />
                <span class="text-lg font-bold tracking-tight text-slate-900">NordiPass</span>
            </a>
            <button type="button" @click="sidebarOpen = false" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-700">
                <span class="sr-only">{{ __('Close navigation') }}</span>
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        @include('layouts.partials.sidebar-nav')

        @if ($availableCompanies->count() > 1)
            <div class="shrink-0 border-t border-slate-200 px-4 py-3">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Switch company') }}</p>
                <div class="space-y-1">
                    @foreach ($availableCompanies as $companyOption)
                        @if ($currentCompany?->is($companyOption))
                            <div class="flex items-center justify-between rounded-lg bg-indigo-50 px-3 py-2 text-sm" aria-current="true">
                                <span class="truncate font-semibold text-indigo-900">{{ $companyName($companyOption) }}</span>
                                <span class="ml-2 shrink-0 text-xs font-semibold text-indigo-700">{{ __('Current') }}</span>
                            </div>
                        @else
                            <form method="POST" action="{{ route('companies.switch', $companyOption) }}">
                                @csrf
                                <button type="submit" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500">
                                    <span class="block truncate">{{ $companyName($companyOption) }}</span>
                                    <span class="block text-xs capitalize text-slate-500">{{ $companyOption->pivot->role->value }}</span>
                                </button>
                            </form>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        <div class="shrink-0 border-t border-slate-200 px-4 py-4">
            @if ($currentCompany)
                <p class="mb-2 truncate text-sm font-semibold text-slate-700" data-testid="mobile-current-company">{{ $companyName($currentCompany) }}</p>
            @endif
            <p class="break-words font-semibold text-slate-900">{{ $text(Auth::user()->name, Auth::user()->email) }}</p>
            <p class="break-all text-sm text-slate-500">{{ Auth::user()->email }}</p>
            @if ($currentMembership)
                <p class="mt-2 text-xs text-slate-500">{{ __('Role') }}: <span class="font-semibold capitalize text-slate-700">{{ $currentMembership->role->value }}</span></p>
            @endif
            <div class="mt-3 flex items-center gap-4 text-sm">
                <a href="{{ route('profile.edit') }}" class="font-medium text-indigo-700 hover:text-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500">{{ __('Profile') }}</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="font-medium text-slate-600 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500">{{ __('Log out') }}</button>
                </form>
            </div>
        </div>
    </div>
</div>
