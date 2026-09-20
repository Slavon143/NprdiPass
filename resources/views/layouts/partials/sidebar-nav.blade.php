@php
    $itemClass = fn (bool $active) => $active
        ? 'flex items-center gap-3 rounded-lg bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500'
        : 'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500';
@endphp

<nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4">
    <div class="space-y-1">
        <a href="{{ route('dashboard') }}" class="{{ $itemClass(request()->routeIs('dashboard')) }}" data-testid="navigation-dashboard">
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
            </svg>
            <span class="truncate">{{ __('Dashboard') }}</span>
        </a>
    </div>

    @if ($currentCompany)
        <div>
            <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Catalog') }}</p>
            <div class="space-y-1">
                @can('viewAny', [\App\Models\Catalog\Product::class, $currentCompany])
                    <a href="{{ route('catalog.products.index') }}" class="{{ $itemClass(request()->routeIs('catalog.products.*')) }}" data-testid="navigation-products">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                        </svg>
                        <span class="truncate">{{ __('Products') }}</span>
                    </a>
                @endcan

                @can(\App\Enums\CompanyPermission::CatalogView->value, $currentCompany)
                    @if(config('features.production_imports'))
                    <a href="{{ route('catalog.imports.index') }}" class="{{ $itemClass(request()->routeIs('catalog.imports.*')) }}" data-testid="navigation-imports">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V3m0 0L7.5 7.5M12 3l4.5 4.5M4.5 14.25v4.5A2.25 2.25 0 006.75 21h10.5a2.25 2.25 0 002.25-2.25v-4.5" />
                        </svg>
                        <span class="truncate">{{ __('Import & export') }}</span>
                    </a>
                    @endif
                @endcan

                @can('viewAny', [\App\Models\Catalog\Category::class, $currentCompany])
                    <a href="{{ route('catalog.categories.index') }}" class="{{ $itemClass(request()->routeIs('catalog.categories.*')) }}" data-testid="navigation-categories">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" />
                        </svg>
                        <span class="truncate">{{ __('Categories') }}</span>
                    </a>
                @endcan

                @can('viewAny', [\App\Models\Catalog\AttributeDefinition::class, $currentCompany])
                    <a href="{{ route('catalog.attributes.index') }}" class="{{ $itemClass(request()->routeIs('catalog.attributes.*')) }}" data-testid="navigation-attributes">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />
                        </svg>
                        <span class="truncate">{{ __('Attributes') }}</span>
                    </a>
                @endcan
            </div>
        </div>

        <div>
            <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Company') }}</p>
            <div class="space-y-1">
                @can('viewAny', [\App\Models\CompanyMembership::class, $currentCompany])
                    <a href="{{ route('settings.members.index') }}" class="{{ $itemClass(request()->routeIs('settings.members.*')) }}" data-testid="navigation-members">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                        </svg>
                        <span class="truncate">{{ __('Members') }}</span>
                    </a>
                @endcan

                <a href="{{ route('settings.company.edit') }}" class="{{ $itemClass(request()->routeIs('settings.company.*')) }}" data-testid="navigation-company-settings">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                    </svg>
                    <span class="truncate">{{ __('Company settings') }}</span>
                </a>

                @if(config('features.billing'))
                    @can(\App\Enums\CompanyPermission::BillingManage->value, $currentCompany)
                        <a href="{{ route('settings.billing.index') }}" class="{{ $itemClass(request()->routeIs('settings.billing.*')) }}" data-testid="navigation-billing">
                            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M3.75 6h16.5A1.5 1.5 0 0121.75 7.5v9A1.5 1.5 0 0120.25 18H3.75a1.5 1.5 0 01-1.5-1.5v-9A1.5 1.5 0 013.75 6zM6 14.25h3.75" />
                            </svg>
                            <span class="truncate">{{ __('Billing') }}</span>
                        </a>
                    @endcan
                @endif
            </div>
        </div>

        <div>
            <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('System') }}</p>
            <div class="space-y-1">
                <a href="{{ route('notifications.index') }}" class="{{ $itemClass(request()->routeIs('notifications.*')) }}" data-testid="navigation-notifications">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a3 3 0 11-5.714 0" />
                    </svg>
                    <span class="truncate">{{ __('Notifications') }}</span>
                </a>

                <a href="{{ route('job-center.index') }}" class="{{ $itemClass(request()->routeIs('job-center.*')) }}" data-testid="navigation-job-center">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 7.5h10.5M6.75 12h10.5m-10.5 4.5h7.5M4.5 5.25A2.25 2.25 0 016.75 3h10.5a2.25 2.25 0 012.25 2.25v13.5A2.25 2.25 0 0117.25 21H6.75a2.25 2.25 0 01-2.25-2.25V5.25z" />
                    </svg>
                    <span class="truncate">{{ __('Job center') }}</span>
                </a>

                @can('viewAny', [\App\Models\AuditLog::class, $currentCompany])
                    <a href="{{ route('audit.index') }}" class="{{ $itemClass(request()->routeIs('audit.*')) }}" data-testid="navigation-audit">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                        </svg>
                        <span class="truncate">{{ __('Audit') }}</span>
                    </a>
                @endcan

                @can(\App\Enums\CompanyPermission::ApiTokensView->value, $currentCompany)
                    <a href="{{ route('settings.api-tokens.index') }}" class="{{ $itemClass(request()->routeIs('settings.api-tokens.*')) }}" data-testid="navigation-api-tokens">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                        </svg>
                        <span class="truncate">{{ __('API tokens') }}</span>
                    </a>

                    @if(config('features.fortnox_integration'))
                    <a href="{{ route('settings.integrations.fortnox.index') }}" class="{{ $itemClass(request()->routeIs('settings.integrations.fortnox.*')) }}" data-testid="navigation-fortnox">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                        </svg>
                        <span class="truncate">{{ __('Fortnox') }}</span>
                    </a>
                    @endif
                @endcan
            </div>
        </div>
    @endif
</nav>
