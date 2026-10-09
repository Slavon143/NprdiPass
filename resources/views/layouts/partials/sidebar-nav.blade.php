@php
    $text = [\App\Support\DisplayText::class, 'make'];
    $itemClass = fn (bool $active) => $active
        ? 'flex items-center gap-3 rounded-lg bg-slate-100 px-3 py-2.5 text-sm font-semibold text-emerald-950 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-emerald-700'
        : 'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-950 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-emerald-700';
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

<nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4">
    <div class="space-y-1">
        <a href="{{ route('dashboard') }}" class="{{ $itemClass(request()->routeIs('dashboard')) }}" title="{{ __('Dashboard') }}" data-testid="navigation-dashboard">
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
            </svg>
            <span class="truncate">{{ __('Dashboard') }}</span>
        </a>
    </div>

    @if ($currentCompany)
        <div>
            <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Workspace') }}</p>
            <div class="space-y-1">
                <a href="{{ route('onboarding.index') }}" class="{{ $itemClass(request()->routeIs('onboarding.index')) }}" title="{{ __('Onboarding') }}" data-testid="navigation-onboarding">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
                    </svg>
                    <span class="truncate">{{ __('Onboarding') }}</span>
                </a>
            </div>
        </div>

        <div>
            <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Catalog') }}</p>
            <div class="space-y-1">
                @can('viewAny', [\App\Models\Catalog\Product::class, $currentCompany])
                    <a href="{{ route('catalog.products.index') }}" class="{{ $itemClass(request()->routeIs('catalog.products.*')) }}" title="{{ __('Products') }}" data-testid="navigation-products">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                        </svg>
                        <span class="truncate">{{ __('Products') }}</span>
                    </a>
                @endcan

                @can(\App\Enums\CompanyPermission::CatalogView->value, $currentCompany)
                    @if(config('features.production_imports'))
                    <a href="{{ route('catalog.imports.index') }}" class="{{ $itemClass(request()->routeIs('catalog.imports.*')) }}" title="{{ __('Imports') }}" data-testid="navigation-imports">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V3m0 0L7.5 7.5M12 3l4.5 4.5M4.5 14.25v4.5A2.25 2.25 0 006.75 21h10.5a2.25 2.25 0 002.25-2.25v-4.5" />
                        </svg>
                        <span class="truncate">{{ __('Imports') }}</span>
                    </a>
                    @endif
                @endcan

                @can('viewAny', [\App\Models\Catalog\Category::class, $currentCompany])
                    <a href="{{ route('catalog.categories.index') }}" class="{{ $itemClass(request()->routeIs('catalog.categories.*')) }}" title="{{ __('Categories') }}" data-testid="navigation-categories">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" />
                        </svg>
                        <span class="truncate">{{ __('Categories') }}</span>
                    </a>
                @endcan

                @can('viewAny', [\App\Models\Catalog\AttributeDefinition::class, $currentCompany])
                    <a href="{{ route('catalog.attributes.index') }}" class="{{ $itemClass(request()->routeIs('catalog.attributes.*')) }}" title="{{ __('Attributes') }}" data-testid="navigation-attributes">
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
                    <a href="{{ route('settings.members.index') }}" class="{{ $itemClass(request()->routeIs('settings.members.*')) }}" title="{{ __('Members') }}" data-testid="navigation-members">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                        </svg>
                        <span class="truncate">{{ __('Members') }}</span>
                    </a>
                @endcan

                <a href="{{ route('settings.company.edit') }}" class="{{ $itemClass(request()->routeIs('settings.company.*')) }}" title="{{ __('Settings') }}" data-testid="navigation-company-settings">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                    </svg>
                    <span class="truncate">{{ __('Settings') }}</span>
                </a>

                @if(config('features.billing'))
                    @can(\App\Enums\CompanyPermission::BillingManage->value, $currentCompany)
                        <a href="{{ route('settings.billing.index') }}" class="{{ $itemClass(request()->routeIs('settings.billing.*')) }}" title="{{ __('Billing') }}" data-testid="navigation-billing">
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
                <a href="{{ route('onboarding.help') }}" class="{{ $itemClass(request()->routeIs('onboarding.help')) }}" title="{{ __('Help') }}" data-testid="navigation-help">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M9.5 9a2.5 2.5 0 1 1 4 2c-1 .75-1.5 1.25-1.5 2.5M12 17h.01" />
                    </svg>
                    <span class="truncate">{{ __('Help') }}</span>
                </a>
                <a href="{{ route('onboarding.support') }}" class="{{ $itemClass(request()->routeIs('onboarding.support')) }}" title="{{ __('Feedback') }}" data-testid="navigation-feedback">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5H4l-3 2V11.5A8.5 8.5 0 0 1 9.5 3h3a8.5 8.5 0 0 1 8.5 8.5Z" /><path stroke-linecap="round" d="M7 11h10M7 15h6" />
                    </svg>
                    <span class="truncate">{{ __('Feedback') }}</span>
                </a>
                <a href="{{ route('notifications.index') }}" class="{{ $itemClass(request()->routeIs('notifications.*')) }}" title="{{ __('Notifications') }}" data-testid="navigation-notifications">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a3 3 0 11-5.714 0" />
                    </svg>
                    <span class="truncate">{{ __('Notifications') }}</span>
                    @if($unreadNotificationCount > 0)
                        <span class="sidebar-unread-badge ml-auto inline-flex min-w-5 items-center justify-center rounded-full bg-rose-100 px-1.5 py-0.5 text-xs font-bold text-rose-700" aria-label="{{ $unreadNotificationCount }} unread notifications">
                            {{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}
                        </span>
                    @endif
                </a>

                <a href="{{ route('job-center.index') }}" class="{{ $itemClass(request()->routeIs('job-center.*')) }}" title="{{ __('Job center') }}" data-testid="navigation-job-center">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 7.5h10.5M6.75 12h10.5m-10.5 4.5h7.5M4.5 5.25A2.25 2.25 0 016.75 3h10.5a2.25 2.25 0 012.25 2.25v13.5A2.25 2.25 0 0117.25 21H6.75a2.25 2.25 0 01-2.25-2.25V5.25z" />
                    </svg>
                    <span class="truncate">{{ __('Job center') }}</span>
                </a>

                @can('viewAny', [\App\Models\AuditLog::class, $currentCompany])
                    <a href="{{ route('audit.index') }}" class="{{ $itemClass(request()->routeIs('audit.*')) }}" title="{{ __('Audit') }}" data-testid="navigation-audit">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                        </svg>
                        <span class="truncate">{{ __('Audit') }}</span>
                    </a>
                @endcan

                @can(\App\Enums\CompanyPermission::ApiTokensView->value, $currentCompany)
                    <a href="{{ route('settings.api-tokens.index') }}" class="{{ $itemClass(request()->routeIs('settings.api-tokens.*')) }}" title="{{ __('API tokens') }}" data-testid="navigation-api-tokens">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                        </svg>
                        <span class="truncate">{{ __('API tokens') }}</span>
                    </a>

                    @if(config('features.fortnox_integration'))
                    <a href="{{ route('settings.integrations.fortnox.index') }}" class="{{ $itemClass(request()->routeIs('settings.integrations.fortnox.*')) }}" title="{{ __('Fortnox') }}" data-testid="navigation-fortnox">
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
    @if (config('features.platform_operations') && auth()->user()?->status === \App\Enums\UserStatus::Active)
        @can(\App\Enums\PlatformPermission::PlatformAccess->value)
            @can(\App\Enums\PlatformPermission::FeedbackView->value)
                @php
                    $openFeedbackCount = \App\Models\Platform\PlatformIncident::query()->where('type', 'customer_feedback')->where('status', 'open')->count();
                @endphp
                <div data-testid="navigation-platform-management">
                    <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('NordiPass management') }}</p>
                    <a href="{{ route('platform.operations.feedback') }}" class="{{ $itemClass(request()->routeIs('platform.operations.feedback*')) }}" title="{{ __('Customer feedback') }}" data-testid="navigation-platform-feedback">
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3 3 7v5c0 5 9 9 9 9s9-4 9-9V7l-9-4Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6M12 9v6" />
                        </svg>
                        <span class="truncate">{{ __('Customer feedback') }}</span>
                        @if ($openFeedbackCount > 0)
                            <span class="sidebar-unread-badge ml-auto inline-flex min-w-5 items-center justify-center rounded-full bg-rose-100 px-1.5 py-0.5 text-xs font-bold text-rose-700" aria-label="{{ __(':count open requests', ['count' => $openFeedbackCount]) }}" data-testid="platform-feedback-count">{{ $openFeedbackCount > 99 ? '99+' : $openFeedbackCount }}</span>
                        @endif
                    </a>
                </div>
            @endcan
        @endcan
    @endif
</nav>

@if ($currentCompany)
    @php
        $sidebarSubscription = \App\Models\Billing\CompanySubscription::query()->forCompany($currentCompany)->first();
        $sidebarPlanKey = $sidebarSubscription?->plan_key;
        $sidebarPlans = (array) config('billing.plans');
        $sidebarPlanName = $sidebarPlanKey !== null ? $text($sidebarPlans[$sidebarPlanKey]['name'] ?? str($sidebarPlanKey)->headline()->value()) : __('No subscription');
        $sidebarProductCount = app(\App\Services\Catalog\CatalogProductCapacity::class)->count($currentCompany);
        $sidebarPlanLimit = app(\App\Services\Billing\EntitlementService::class)->limit($currentCompany, 'catalog.products.max');
        $sidebarUsage = $sidebarPlanLimit > 0 ? min(100, (int) round(($sidebarProductCount / $sidebarPlanLimit) * 100)) : 0;
        $sidebarLimitLabel = $sidebarSubscription?->grantsEntitlements() === true
            ? ($sidebarPlanLimit === null ? __('unlimited') : (string) $sidebarPlanLimit)
            : __('—');
        $sidebarPeriodEnd = $sidebarSubscription?->status?->value === 'trialing'
            ? $sidebarSubscription?->trial_ends_at
            : $sidebarSubscription?->current_period_end;
        $sidebarPeriodLabel = $sidebarSubscription?->status?->value === 'trialing' ? __('Trial ends') : __('Renews');
    @endphp
    <details class="sidebar-plan mx-4 mb-4 max-h-[50vh] shrink-0 overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-sm" data-testid="sidebar-plan">
        <summary class="flex cursor-pointer items-center justify-between gap-2 rounded-lg p-3 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-emerald-700">
            <span class="min-w-0">
                <span class="block truncate text-sm font-bold text-slate-900">{{ $text($currentCompany->name) }}</span>
                <span class="mt-1 block text-sm text-slate-600">{{ __('Plan') }}: {{ $sidebarPlanName }}</span>
                <span class="sr-only">{{ __('Company plan details') }}</span>
            </span>
            <svg class="sidebar-plan-chevron h-4 w-4 shrink-0 text-slate-500" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="m5 7 5 5 5-5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </summary>
        <div class="border-t border-slate-200 px-3 pb-3" data-testid="sidebar-plan-details">
            <p class="mt-4 text-sm font-medium text-slate-700">{{ $sidebarProductCount }} / {{ $sidebarLimitLabel }} products</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('Drafts count. Archived and deleted products do not.') }}</p>
            <div class="mt-2 h-2 rounded-full bg-slate-100">
                <div class="h-2 rounded-full bg-emerald-700" style="width: {{ $sidebarUsage }}%"></div>
            </div>
            <p class="mt-4 text-xs text-slate-500">
                @if ($sidebarPeriodEnd)
                    {{ $sidebarPeriodLabel }} {{ $sidebarPeriodEnd->format('Y-m-d') }}
                @else
                    {{ __('Billing period unavailable') }}
                @endif
            </p>
            <a href="{{ route('settings.billing.index') }}" class="mt-3 inline-flex w-full justify-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-100">Upgrade plan</a>
        </div>
    </details>
@endif
