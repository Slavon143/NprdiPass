<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Company overview') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ $company->name }}</h1>
            </div>
            <x-badge :tone="$company->status->value === 'active' ? 'emerald' : 'amber'">{{ $company->status->value }}</x-badge>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
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
            <dl class="grid gap-x-8 gap-y-6 px-5 py-6 sm:grid-cols-2 sm:px-6 lg:grid-cols-3">
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
                <div>
                    <dt class="text-sm font-medium text-slate-500">{{ __('Signed in as') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ Auth::user()->name }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-slate-500">{{ __('Company reference') }}</dt>
                    <dd class="mt-1 break-all font-mono text-xs text-slate-600">{{ $company->uuid }}</dd>
                </div>
            </dl>
        </section>

        <x-alert type="success">
            {{ __('The NordiPass foundation is ready. Company settings and member access are available from the navigation.') }}
        </x-alert>
    </div>
</x-app-layout>
