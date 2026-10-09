<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ __('Billing') }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $company->name }}</p>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if ($checkoutStatus === 'success')
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ __('Checkout completed. Stripe will confirm your subscription shortly and the status below will update automatically.') }}</div>
        @elseif ($checkoutStatus === 'cancelled')
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('Checkout was cancelled. You can start a new checkout at any time.') }}</div>
        @elseif ($checkoutStatus === 'fake')
            <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">{{ __('Simulated checkout completed. Local mode does not create a real subscription.') }}</div>
        @endif

        <section class="rounded-lg border border-slate-200 bg-white p-6">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Status') }}</p>
                    <p class="mt-1 text-lg font-semibold capitalize text-slate-900">
                        @if ($subscription === null)
                            {{ __('No subscription') }}
                        @elseif ($subscription->status->value === 'pending')
                            {{ __('Confirming subscription…') }}
                        @else
                            {{ $subscription->status->value }}
                        @endif
                    </p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Plan') }}</p>
                    <p class="mt-1 text-lg font-semibold capitalize text-slate-900">{{ $subscription?->plan_key ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ ($commercial ?? false) ? __('Managed products') : __('Products') }}</p>
                    <p class="mt-1 text-lg font-semibold text-slate-900">
                        @if ($subscription?->grantsEntitlements())
                            {{ $productCount }} / {{ $productLimit ?? ($company->commercial_model_version === 1 ? __('Unavailable') : __('unlimited')) }}
                        @elseif($commercial ?? false)
                            {{ $productCount }} · {{ __('read-only') }}
                        @else
                            —
                        @endif
                    </p>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Drafts count. Archived and deleted products do not.') }}</p>
                    @if(($commercial ?? false) && $productLimit !== null && $productLimit > 0 && $productCount >= $productLimit * 0.9)
                        <p class="mt-2 text-sm font-semibold text-amber-800">{{ __('Managed product capacity is almost full. Archive unused products or upgrade before enrolling more.') }}</p>
                    @endif
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        @if (($license ?? $subscription)?->status?->value === 'trialing')
                            {{ __('Trial ends') }}
                        @else
                            {{ __('Renews') }}
                        @endif
                    </p>
                    <p class="mt-1 text-lg font-semibold text-slate-900">
                        @if (($license ?? $subscription)?->status?->value === 'trialing')
                            {{ ($license ?? $subscription)->trial_ends_at?->format('Y-m-d') ?? '—' }}
                        @else
                            {{ $subscription?->current_period_end?->format('Y-m-d') ?? '—' }}
                        @endif
                    </p>
                </div>
            </div>

            @if ($subscription?->status?->value === 'pending' && ! $subscription?->stripe_customer_id && ! $subscription?->stripe_subscription_id)
                <form class="mt-6" method="POST" action="{{ route('settings.billing.checkout.cancel-pending') }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-100 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2">
                        {{ __('Cancel pending checkout') }}
                    </button>
                </form>
            @elseif ($subscription?->stripe_customer_id)
                <form class="mt-6" method="POST" action="{{ route('settings.billing.portal') }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2">
                        {{ __('Open customer portal') }}
                    </button>
                </form>
            @endif
        </section>

        @if($usageOverview !== null)
            @php
                $formatUsage = static function (?int $amount, bool $bytes): string {
                    if ($amount === null) {
                        return __('Not available yet');
                    }
                    if (! $bytes) {
                        return number_format($amount);
                    }
                    foreach ([1000000000 => 'GB', 1000000 => 'MB', 1000 => 'kB'] as $scale => $unit) {
                        if ($amount >= $scale) {
                            return rtrim(rtrim(number_format($amount / $scale, 2, '.', ''), '0'), '.').' '.$unit;
                        }
                    }
                    return $amount.' B';
                };
            @endphp
            <section class="rounded-lg border border-slate-200 bg-white p-6">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500">{{ __('Usage and allowances') }}</h2>
                <dl class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($usageOverview['meters'] as $meter)
                        <div>
                            <dt class="text-sm text-slate-600">{{ __($meter['label']) }}</dt>
                            <dd class="mt-1 font-semibold text-slate-900">{{ $formatUsage($meter['used'], $meter['key'] === 'storage') }}
                                @if($commercialReadOnly)
                                    <span class="text-sm font-normal text-slate-500">· {{ __('Paused') }}</span>
                                @elseif($meter['limit'] === 0)
                                    <span class="text-sm font-normal text-slate-500">· {{ __('Not included') }}</span>
                                @elseif($meter['limit'] !== null)
                                    / {{ $formatUsage($meter['limit'], $meter['key'] === 'storage') }}
                                @endif
                            </dd>
                            @if($meter['near_limit'])
                                <p class="mt-1 text-sm text-amber-800">{{ __(match ($meter['key']) {
                                    'seats' => 'Seat allowance is almost full.',
                                    'storage' => 'Storage allowance is almost full.',
                                    'imports' => 'Import allowance is almost full.',
                                    default => 'API allowance is almost full.',
                                }) }}</p>
                            @endif
                        </div>
                    @endforeach
                </dl>
                @if($usageOverview['window_end'] !== null)
                    <p class="mt-4 text-sm text-slate-500">{{ __('Current import and API quota period ends on :date.', ['date' => $usageOverview['window_end']->format('Y-m-d')]) }}</p>
                @endif
            </section>
        @endif

        <section>
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ __('Plans') }}</h2>
            @if($commercialReadOnly ?? false)
                <p class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ __('Your workspace is read-only. Existing products, documents and published history are retained. Restore your subscription to resume edits and integrations.') }}</p>
            @endif
            @if($commercial ?? false)
                <p class="mb-4 text-sm text-slate-600">{{ __('Monthly or annual billing. Annual prices cover a full year. No automatic overage charges. VAT, if applicable, is separate.') }}</p>
            @endif
            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($plans as $key => $plan)
                    @php
                        $isCurrentPlan = ($subscription?->plan_key ?? null) === $key;
                        $status = $subscription?->status?->value;
                        $isPending = $status === 'pending';
                        $localCommercialTrial = ($commercial ?? false) && $status === 'trialing'
                            && ! $subscription?->stripe_customer_id && ! $subscription?->stripe_subscription_id;
                        $isSubscribed = $isCurrentPlan && in_array($status, ['trialing', 'active', 'past_due'], true) && ! $localCommercialTrial;
                        $hasNonTerminal = $subscription?->isNonTerminal() ?? false;
                        $canSwitchTrial = $status === 'trialing'
                            && ! $subscription?->stripe_customer_id
                            && ! $subscription?->stripe_subscription_id;
                    @endphp
                    <article class="rounded-lg border border-slate-200 bg-white p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900">{{ $plan['name'] ?? $key }}</h3>
                                @if(($commercial ?? false) && isset($plan['monthly_eur'], $plan['annual_eur']))
                                    <p class="mt-1 text-sm text-slate-900">€{{ $plan['monthly_eur'] }} / {{ __('month') }}</p>
                                    <p class="mt-1 text-sm text-slate-600">€{{ $plan['annual_eur'] }} / {{ __('year') }}</p>
                                @elseif(! ($commercial ?? false))
                                    <p class="mt-1 text-sm text-slate-600">{{ __('Trial') }}: {{ $plan['trial_days'] ?? 0 }} {{ __('days') }}</p>
                                @endif
                            </div>
                            @if ($isSubscribed)
                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">{{ __('Current plan') }}</span>
                            @endif
                        </div>
                        <dl class="mt-4 space-y-2 text-sm text-slate-700">
                            @foreach (($plan['entitlements'] ?? []) as $entitlement => $value)
                                <div class="flex justify-between gap-4">
                                    <dt>{{ ($commercial ?? false) ? match ($entitlement) {
                                        'catalog.products.max' => __('Managed products'),
                                        'catalog.total.max' => __('Catalogue pool limit'),
                                        'members.max' => __('Seats, including owner'),
                                        'storage.bytes.max' => __('Retained storage'),
                                        'imports.jobs.max' => __('Imports per quota period'),
                                        'api.requests.max' => __('API requests per quota period'),
                                        'webhooks.enabled' => __('Outgoing webhooks'),
                                        'api.enabled' => __('API access'),
                                        default => __("billing_ui.entitlements.{$entitlement}"),
                                    } : __("billing_ui.entitlements.{$entitlement}") }}</dt>
                                    <dd class="font-medium">{{ $entitlement === 'storage.bytes.max' && is_numeric($value) ? ($value / 1000000000).' GB' : (is_bool($value) ? ($value ? __('Yes') : __('No')) : $value) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        @if(($plan['contact_sales'] ?? false) === true)
                            <p class="mt-6 text-sm font-semibold text-slate-700">{{ __('Contact sales to arrange an Enterprise contract.') }}</p>
                        @elseif ($isSubscribed)
                            <button type="button" disabled class="mt-6 w-full cursor-not-allowed rounded-lg bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-500">
                                {{ __('Current plan') }}
                            </button>
                        @elseif ($isPending)
                            <button type="button" disabled class="mt-6 w-full cursor-not-allowed rounded-lg bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-500">
                                {{ __('Confirming subscription…') }}
                            </button>
                        @elseif ($canSwitchTrial || ! $hasNonTerminal)
                            <form class="mt-6" method="POST" action="{{ route('settings.billing.checkout') }}">
                                @csrf
                                <input type="hidden" name="plan" value="{{ $key }}">
                                @if($commercial ?? false)
                                    @include('billing.period-select', ['plan' => $plan])
                                @endif
                                <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                    {{ ($commercial ?? false) ? __('Start checkout') : ($canSwitchTrial ? __('Switch to :plan', ['plan' => $plan['name'] ?? str($key)->headline()->value()]) : __('Start checkout')) }}
                                </button>
                            </form>
                        @elseif ($hasNonTerminal && $subscription?->stripe_customer_id)
                            <form class="mt-6" method="POST" action="{{ route('settings.billing.portal') }}">
                                @csrf
                                <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2">
                                    {{ __('Manage in customer portal') }}
                                </button>
                            </form>
                        @elseif ($hasNonTerminal)
                            <button type="button" disabled class="mt-6 w-full cursor-not-allowed rounded-lg bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-500">
                                {{ __('Checkout confirmation required') }}
                            </button>
                        @else
                            <form class="mt-6" method="POST" action="{{ route('settings.billing.checkout') }}">
                                @csrf
                                <input type="hidden" name="plan" value="{{ $key }}">
                                @if($commercial ?? false)
                                    @include('billing.period-select', ['plan' => $plan])
                                @endif
                                <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                    {{ __('Start checkout') }}
                                </button>
                            </form>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    </div>
</x-app-layout>
