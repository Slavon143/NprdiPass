<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Platform') }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Observability') }}</h1>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">{{ __('SLO snapshot') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $snapshot['captured_at'] }}</p>
                </div>
                <span class="rounded-full px-3 py-1 text-sm font-semibold {{ $snapshot['status'] === 'ok' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                    {{ $snapshot['status'] }}
                </span>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-4">
                @foreach($snapshot['checks'] as $check => $status)
                    <div class="rounded-lg border border-slate-200 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ str_replace('_', ' ', $check) }}</p>
                        <p class="mt-2 text-xl font-bold {{ $status === 'ok' ? 'text-emerald-700' : 'text-amber-700' }}">{{ $status }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Queue') }}</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    @foreach($snapshot['queue'] as $label => $value)
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-slate-500">{{ str_replace('_', ' ', $label) }}</dt>
                            <dd class="font-semibold text-slate-900">{{ $value ?? __('Unavailable') }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Public resolver') }}</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    @foreach($snapshot['public_resolver'] as $label => $value)
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-slate-500">{{ str_replace('_', ' ', $label) }}</dt>
                            <dd class="font-semibold text-slate-900">{{ $value ?? __('Unavailable') }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Storage') }}</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    @foreach($snapshot['storage'] as $label => $value)
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-slate-500">{{ str_replace('_', ' ', $label) }}</dt>
                            <dd class="font-semibold text-slate-900">{{ is_numeric($value) ? number_format((float) $value) : $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Catalog baseline') }}</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    @foreach($snapshot['catalog'] as $label => $value)
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-slate-500">{{ str_replace('_', ' ', $label) }}</dt>
                            <dd class="font-semibold text-slate-900">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </section>
    </div>
</x-app-layout>
