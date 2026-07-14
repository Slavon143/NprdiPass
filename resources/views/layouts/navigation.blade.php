<nav x-data="{ mobileOpen: false }" class="border-b border-slate-200 bg-white" aria-label="Primary navigation">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between gap-4">
            <div class="flex min-w-0 items-center gap-8">
                <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-2 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    <x-application-logo class="h-9 w-9 text-indigo-600" />
                    <span class="hidden text-lg font-bold tracking-tight text-slate-900 lg:inline">NordiPass</span>
                </a>

                <div class="hidden items-center gap-7 lg:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">{{ __('Dashboard') }}</x-nav-link>

                    @if ($currentCompany)
                        <x-nav-link :href="route('settings.company.edit')" :active="request()->routeIs('settings.company.*')">{{ __('Company settings') }}</x-nav-link>

                        @can('viewAny', [\App\Models\Catalog\Product::class, $currentCompany])
                            <x-nav-link :href="route('catalog.products.index')" :active="request()->routeIs('catalog.products.*')">{{ __('Products') }}</x-nav-link>
                        @endcan

                        @can('viewAny', [\App\Models\Catalog\Category::class, $currentCompany])
                            <x-nav-link :href="route('catalog.categories.index')" :active="request()->routeIs('catalog.categories.*')">{{ __('Categories') }}</x-nav-link>
                        @endcan

                        @can('viewAny', [\App\Models\Catalog\AttributeDefinition::class, $currentCompany])
                            <x-nav-link :href="route('catalog.attributes.index')" :active="request()->routeIs('catalog.attributes.*')">{{ __('Attributes') }}</x-nav-link>
                        @endcan

                        @can('viewAny', [\App\Models\CompanyMembership::class, $currentCompany])
                            <x-nav-link :href="route('settings.members.index')" :active="request()->routeIs('settings.members.*')">{{ __('Members') }}</x-nav-link>
                        @endcan

                        @can('viewAny', [\App\Models\AuditLog::class, $currentCompany])
                            <x-nav-link :href="route('audit.index')" :active="request()->routeIs('audit.*')">{{ __('Audit') }}</x-nav-link>
                        @endcan

                        @can(\App\Enums\CompanyPermission::ApiTokensView->value, $currentCompany)
                            <x-nav-link :href="route('settings.api-tokens.index')" :active="request()->routeIs('settings.api-tokens.*')">{{ __('API tokens') }}</x-nav-link>
                        @endcan
                    @endif
                </div>
            </div>

            <div class="hidden shrink-0 items-center gap-3 lg:flex">
                @if ($currentCompany && $availableCompanies->count() > 1)
                    <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                        <button type="button" @click="open = !open" :aria-expanded="open" aria-haspopup="menu" class="flex max-w-64 items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-left text-sm transition hover:border-slate-300 hover:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <span class="min-w-0">
                                <span class="block truncate font-semibold text-slate-900">{{ $currentCompany->name }}</span>
                                <span class="block text-xs capitalize text-slate-500">{{ $currentMembership?->role->value }}</span>
                            </span>
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
                                        <span class="text-xs font-semibold text-indigo-700">{{ __('Current') }}</span>
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
                        <span class="block text-xs capitalize text-slate-500">{{ $currentMembership?->role->value }}</span>
                    </div>
                @endif

                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button type="button" class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <span class="max-w-36 truncate">{{ Auth::user()->name }}</span>
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">{{ __('Profile') }}</x-dropdown-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">{{ __('Log out') }}</x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <button type="button" @click="mobileOpen = !mobileOpen" :aria-expanded="mobileOpen" aria-controls="mobile-navigation" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 lg:hidden">
                <span class="sr-only">{{ __('Toggle navigation') }}</span>
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path x-show="!mobileOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    <path x-cloak x-show="mobileOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>

    <div id="mobile-navigation" x-cloak x-show="mobileOpen" class="border-t border-slate-200 bg-white lg:hidden">
        <div class="space-y-1 px-3 py-3">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">{{ __('Dashboard') }}</x-responsive-nav-link>
            @if ($currentCompany)
                <x-responsive-nav-link :href="route('settings.company.edit')" :active="request()->routeIs('settings.company.*')">{{ __('Company settings') }}</x-responsive-nav-link>
                @can('viewAny', [\App\Models\Catalog\Product::class, $currentCompany])
                    <x-responsive-nav-link :href="route('catalog.products.index')" :active="request()->routeIs('catalog.products.*')">{{ __('Products') }}</x-responsive-nav-link>
                @endcan
                @can('viewAny', [\App\Models\Catalog\Category::class, $currentCompany])
                    <x-responsive-nav-link :href="route('catalog.categories.index')" :active="request()->routeIs('catalog.categories.*')">{{ __('Categories') }}</x-responsive-nav-link>
                @endcan
                @can('viewAny', [\App\Models\Catalog\AttributeDefinition::class, $currentCompany])
                    <x-responsive-nav-link :href="route('catalog.attributes.index')" :active="request()->routeIs('catalog.attributes.*')">{{ __('Attributes') }}</x-responsive-nav-link>
                @endcan
                @can('viewAny', [\App\Models\CompanyMembership::class, $currentCompany])
                    <x-responsive-nav-link :href="route('settings.members.index')" :active="request()->routeIs('settings.members.*')">{{ __('Members') }}</x-responsive-nav-link>
                @endcan
                @can('viewAny', [\App\Models\AuditLog::class, $currentCompany])
                    <x-responsive-nav-link :href="route('audit.index')" :active="request()->routeIs('audit.*')">{{ __('Audit') }}</x-responsive-nav-link>
                @endcan
                @can(\App\Enums\CompanyPermission::ApiTokensView->value, $currentCompany)
                    <x-responsive-nav-link :href="route('settings.api-tokens.index')" :active="request()->routeIs('settings.api-tokens.*')">{{ __('API tokens') }}</x-responsive-nav-link>
                @endcan
            @endif
        </div>

        @if ($availableCompanies->count() > 1)
            <div class="border-t border-slate-200 px-4 py-3">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Companies') }}</p>
                <div class="space-y-1">
                    @foreach ($availableCompanies as $companyOption)
                        @if ($currentCompany?->is($companyOption))
                            <div class="rounded-lg bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-800" aria-current="true">{{ $companyOption->name }}</div>
                        @else
                            <form method="POST" action="{{ route('companies.switch', $companyOption) }}">
                                @csrf
                                <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500">{{ $companyOption->name }}</button>
                            </form>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        <div class="border-t border-slate-200 px-4 py-4">
            <p class="font-semibold text-slate-900">{{ Auth::user()->name }}</p>
            <p class="break-all text-sm text-slate-500">{{ Auth::user()->email }}</p>
            <div class="mt-3 flex items-center gap-4 text-sm">
                <a href="{{ route('profile.edit') }}" class="font-medium text-indigo-700 hover:text-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500">{{ __('Profile') }}</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="font-medium text-slate-600 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500">{{ __('Log out') }}</button>
                </form>
            </div>
        </div>
    </div>
</nav>
