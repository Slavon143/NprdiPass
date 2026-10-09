<x-app-layout>
    <x-slot name="header">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-sm font-medium text-indigo-600">{{ __('Public Beta') }}</p>
                    <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Onboarding') }}</h1>
                    <p class="mt-1 text-sm text-slate-500">{{ $snapshot['completed_steps'] }} / {{ $snapshot['total_steps'] }} {{ __('steps complete') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-3 py-1 text-sm font-semibold {{ $snapshot['complete'] ? 'bg-emerald-100 text-emerald-800' : 'bg-indigo-100 text-indigo-800' }}">
                        {{ $snapshot['complete'] ? __('Ready for beta') : __('In progress') }}
                    </span>
                    <a href="{{ route('onboarding.help') }}" class="rounded-lg border border-slate-200 px-3 py-1 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        {{ __('Help') }}
                    </a>
                </div>
            </div>
        </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        @unless($snapshot['public_passports_enabled'])
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="font-semibold text-amber-900">{{ __('Plan upgrade required') }}</h2>
                        <p class="mt-1 text-sm text-amber-800">{{ __('Publication and QR steps need the public passports entitlement. Activate billing to continue.') }}</p>
                    </div>
                    <a href="{{ route('settings.billing.index') }}" class="inline-flex items-center justify-center rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2">
                        {{ __('Open billing') }}
                    </a>
                </div>
            </section>
        @endunless

        <section class="grid gap-4 md:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-slate-500">{{ __('Products') }}</p>
                <p class="mt-2 text-2xl font-bold text-slate-900">{{ $snapshot['product_count'] }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-slate-500">{{ __('Passports') }}</p>
                <p class="mt-2 text-2xl font-bold text-slate-900">{{ $snapshot['passport_count'] }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-slate-500">{{ __('Published') }}</p>
                <p class="mt-2 text-2xl font-bold text-slate-900">{{ $snapshot['published_passport_count'] }}</p>
            </div>
        </section>

        <section class="rounded-2xl border border-emerald-100 bg-emerald-50/60 p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Add your first product') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('Choose the source that matches your workflow. You can connect the others later.') }}</p>
            <div class="mt-5 grid gap-3 md:grid-cols-3">
                @if(Route::has('settings.integrations.fortnox.index'))
                    <a href="{{ route('settings.integrations.fortnox.index') }}" class="rounded-xl border border-emerald-200 bg-white p-4 hover:border-emerald-400">
                        <span class="font-semibold text-slate-900">{{ __('Connect Fortnox') }}</span>
                        <span class="mt-1 block text-sm text-slate-500">{{ __('Import existing articles') }}</span>
                    </a>
                @else
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <span class="font-semibold text-slate-500">{{ __('Fortnox') }}</span>
                        <span class="mt-1 block text-sm text-slate-400">{{ __('Integration is not enabled') }}</span>
                    </div>
                @endif
                <a href="{{ route('catalog.imports.index') }}" class="rounded-xl border border-emerald-200 bg-white p-4 hover:border-emerald-400">
                    <span class="font-semibold text-slate-900">{{ __('Import file') }}</span>
                    <span class="mt-1 block text-sm text-slate-500">{{ __('CSV or Excel catalogue') }}</span>
                </a>
                <a href="{{ route('catalog.products.create') }}" class="rounded-xl border border-emerald-200 bg-white p-4 hover:border-emerald-400">
                    <span class="font-semibold text-slate-900">{{ __('Create manually') }}</span>
                    <span class="mt-1 block text-sm text-slate-500">{{ __('Start with one product') }}</span>
                </a>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Launch path') }}</h2>
            </div>
            <div class="divide-y divide-slate-200">
                @foreach($snapshot['steps'] as $key => $step)
                    <div class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-7 w-7 items-center justify-center rounded-full text-sm font-bold {{ $step['complete'] ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $step['complete'] ? 'OK' : $loop->iteration }}
                                </span>
                                <h3 class="font-semibold text-slate-900">{{ __($step['label']) }}</h3>
                                @if(!empty($step['entitlement_required']))
                                    <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">{{ __('Plan required') }}</span>
                                @endif
                            </div>
                            <p class="mt-2 text-sm text-slate-500">{{ __($step['detail']) }}</p>
                        </div>
                        @if(($step['method'] ?? 'get') === 'post')
                            <form method="POST" action="{{ $step['url'] }}">
                                @csrf
                                <button class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                    {{ $step['complete'] ? __('Open') : __('Create sample') }}
                                </button>
                            </form>
                        @else
                            <a href="{{ $step['url'] }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                {{ $step['complete'] ? __('Review') : __('Continue') }}
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</x-app-layout>
