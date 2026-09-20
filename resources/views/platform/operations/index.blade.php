<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Platform') }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Operations') }}</h1>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Tenant health') }}</h2>
            </div>
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Company') }}</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Health') }}</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Jobs') }}</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Open incidents') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @foreach($companies as $company)
                        @php($snapshot = $health[$company->uuid])
                        <tr>
                            <td class="px-5 py-4">
                                <a href="{{ route('platform.operations.companies.show', $company) }}" class="font-semibold text-indigo-700 hover:text-indigo-900">{{ $company->name }}</a>
                                <p class="mt-1 text-xs text-slate-500">{{ $company->uuid }}</p>
                            </td>
                            <td class="px-5 py-4 text-sm text-slate-700">{{ $snapshot['status'] }}</td>
                            <td class="px-5 py-4 text-sm text-slate-700">{{ __(':failed failed, :pending pending', ['failed' => $snapshot['failed_jobs'], 'pending' => $snapshot['pending_jobs']]) }}</td>
                            <td class="px-5 py-4 text-right text-sm text-slate-700">{{ $snapshot['open_incidents'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Recent open incidents') }}</h2>
            <div class="mt-4 divide-y divide-slate-200">
                @forelse($openIncidents as $incident)
                    <div class="flex items-center justify-between gap-3 py-3">
                        <div>
                            <p class="font-semibold text-slate-900">{{ $incident->title }}</p>
                            <p class="text-sm text-slate-500">{{ $incident->severity->value }} · {{ $incident->type }}</p>
                        </div>
                        <form method="POST" action="{{ route('platform.operations.incidents.resolve', $incident) }}">
                            @csrf
                            <button class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">{{ __('Resolve') }}</button>
                        </form>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __('No open incidents.') }}</p>
                @endforelse
            </div>
        </section>
    </div>
</x-app-layout>
