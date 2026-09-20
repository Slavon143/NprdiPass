<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Company overview') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ $company->name }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ __('Company information and account overview') }}</p>
            </div>
            <x-badge :tone="$company->status->value === 'active' ? 'emerald' : 'amber'">{{ $company->status->value }}</x-badge>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8" data-testid="dashboard-page">
        <div class="grid gap-4 sm:grid-cols-3">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-slate-500">{{ __('Your role') }}</p>
                <p class="mt-2 text-2xl font-bold capitalize text-slate-900">{{ $membership->role->value }}</p>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-slate-500">{{ __('Members') }}</p>
                <p class="mt-2 text-2xl font-bold text-slate-900">{{ $memberCount }}</p>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-slate-500">{{ __('Company since') }}</p>
                <p class="mt-2 text-2xl font-bold text-slate-900">{{ $company->created_at->format('M Y') }}</p>
            </section>
        </div>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Company details') }}</h2>
            </div>
            <dl class="grid gap-x-8 gap-y-6 px-5 py-6 sm:grid-cols-2 sm:px-6">
                <div>
                    <dt class="text-sm font-medium text-slate-500">{{ __('Legal name') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $company->legal_name ?: __('Not provided') }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-slate-500">{{ __('Organization number') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $company->organization_number ?: __('Not provided') }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-slate-500">{{ __('Country') }}</dt>
                    <dd class="mt-1 text-sm font-semibold uppercase text-slate-900">{{ $company->country_code }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-slate-500">{{ __('Billing email') }}</dt>
                    <dd class="mt-1 break-all text-sm font-semibold text-slate-900">{{ $company->billing_email ?: __('Not provided') }}</dd>
                </div>
            </dl>
        </section>

        <section x-data="{ open: false }" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <button type="button" @click="open = !open" :aria-expanded="open" aria-controls="technical-information" class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500 sm:px-6">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Technical information') }}</h2>
                <svg class="h-5 w-5 shrink-0 text-slate-400 transition-transform" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                </svg>
            </button>
            <div id="technical-information" x-cloak x-show="open" class="border-t border-slate-200 px-5 py-4 sm:px-6">
                <dl>
                    <div>
                        <dt class="text-sm font-medium text-slate-500">{{ __('Company ID') }}</dt>
                        <dd class="mt-2 flex items-center gap-3">
                            <code class="min-w-0 flex-1 truncate rounded-lg bg-slate-100 px-3 py-2 font-mono text-xs text-slate-700">{{ $company->uuid }}</code>
                            <button
                                type="button"
                                x-data="{ copied: false }"
                                @click="navigator.clipboard.writeText('{{ $company->uuid }}').then(() => { copied = true; setTimeout(() => copied = false, 2000); })"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            >
                                <span x-show="!copied">{{ __('Copy') }}</span>
                                <span x-show="copied" x-cloak class="text-indigo-700">{{ __('Copied') }}</span>
                            </button>
                        </dd>
                    </div>
                </dl>
            </div>
        </section>
    </div>
</x-app-layout>
