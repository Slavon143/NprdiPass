<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ __('Billing') }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $company->name }}</p>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ session('error') }}</div>
        @endif
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
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Products') }}</p>
                    <p class="mt-1 text-lg font-semibold text-slate-900">
                        @if ($subscription?->grantsEntitlements())
                            {{ $productCount }} / {{ $productLimit ?? __('unlimited') }}
                        @else
                            —
                        @endif
                    </p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        @if ($subscription?->status?->value === 'trialing')
                            {{ __('Trial ends') }}
                        @else
                            {{ __('Renews') }}
                        @endif
                    </p>
                    <p class="mt-1 text-lg font-semibold text-slate-900">
                        @if ($subscription?->status?->value === 'trialing')
                            {{ $subscription->trial_ends_at?->format('Y-m-d') ?? '—' }}
                        @else
                            {{ $subscription?->current_period_end?->format('Y-m-d') ?? '—' }}
                        @endif
                    </p>
                </div>
            </div>

            @if ($subscription?->stripe_customer_id)
                <form class="mt-6" method="POST" action="{{ route('settings.billing.portal') }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2">
                        {{ __('Open customer portal') }}
                    </button>
                </form>
            @endif
        </section>

        <section>
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ __('Plans') }}</h2>
            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($plans as $key => $plan)
                    @php
                        $isCurrentPlan = ($subscription?->plan_key ?? null) === $key;
                        $status = $subscription?->status?->value;
                        $isPending = $status === 'pending';
                        $isSubscribed = $isCurrentPlan && in_array($status, ['trialing', 'active', 'past_due'], true);
                        $hasNonTerminal = $subscription?->isNonTerminal() ?? false;
                    @endphp
                    <article class="rounded-lg border border-slate-200 bg-white p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900">{{ $plan['name'] ?? $key }}</h3>
                                <p class="mt-1 text-sm text-slate-600">{{ __('Trial') }}: {{ $plan['trial_days'] ?? 0 }} {{ __('days') }}</p>
                            </div>
                            @if ($isSubscribed)
                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">{{ __('Current plan') }}</span>
                            @endif
                        </div>
                        <dl class="mt-4 space-y-2 text-sm text-slate-700">
                            @foreach (($plan['entitlements'] ?? []) as $entitlement => $value)
                                <div class="flex justify-between gap-4">
                                    <dt>{{ __("billing_ui.entitlements.{$entitlement}") }}</dt>
                                    <dd class="font-medium">{{ is_bool($value) ? ($value ? __('Yes') : __('No')) : $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        @if ($isSubscribed)
                            <button type="button" disabled class="mt-6 w-full cursor-not-allowed rounded-lg bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-500">
                                {{ __('Current plan') }}
                            </button>
                        @elseif ($isPending)
                            <button type="button" disabled class="mt-6 w-full cursor-not-allowed rounded-lg bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-500">
                                {{ __('Confirming subscription…') }}
                            </button>
                        @elseif ($hasNonTerminal)
                            <button type="button" disabled class="mt-6 w-full cursor-not-allowed rounded-lg bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-500">
                                {{ __('Manage in customer portal') }}
                            </button>
                        @else
                            <form class="mt-6" method="POST" action="{{ route('settings.billing.checkout') }}">
                                @csrf
                                <input type="hidden" name="plan" value="{{ $key }}">
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
