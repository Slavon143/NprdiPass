<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Catalog Audit History') }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('All catalog-changing activity for :company.', ['company' => $company->name]) }}</p>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="audit-filters-heading">
            <div class="mb-4">
                <h2 id="audit-filters-heading" class="text-base font-semibold text-slate-900">{{ __('Filter catalog audit events') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('Date ranges are limited to :days days. Filters never include another company.', ['days' => config('catalog.audit.max_date_range_days', 366)]) }}</p>
            </div>

            <form method="GET" action="{{ route('catalog.audit.index') }}" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <label for="event" class="block text-sm font-medium text-slate-700">{{ __('Event') }}</label>
                    <select id="event" name="event" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">{{ __('All catalog events') }}</option>
                        @foreach ($events as $eventOption)
                            <option value="{{ $eventOption->value }}" @selected(request('event') === $eventOption->value)>{{ $eventOption->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="actor" class="block text-sm font-medium text-slate-700">{{ __('Actor') }}</label>
                    <select id="actor" name="actor" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">{{ __('All actors') }}</option>
                        @foreach ($actors as $actorOption)
                            <option value="{{ $actorOption->uuid }}" @selected(request('actor') === $actorOption->uuid)>{{ $actorOption->name }} — {{ $actorOption->email }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="resource_type" class="block text-sm font-medium text-slate-700">{{ __('Resource type') }}</label>
                    <select id="resource_type" name="resource_type" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">{{ __('All types') }}</option>
                        @foreach ($resourceTypes as $type)
                            <option value="{{ $type }}" @selected(request('resource_type') === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="resource_uuid" class="block text-sm font-medium text-slate-700">{{ __('Resource UUID') }}</label>
                    <input id="resource_uuid" name="resource_uuid" type="text" value="{{ request('resource_uuid') }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="UUID">
                </div>

                <div>
                    <label for="request_id" class="block text-sm font-medium text-slate-700">{{ __('Request ID') }}</label>
                    <input id="request_id" name="request_id" type="text" value="{{ request('request_id') }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Request ID">
                </div>

                <div>
                    <label for="q" class="block text-sm font-medium text-slate-700">{{ __('Search') }}</label>
                    <input id="q" name="q" type="text" value="{{ request('q') }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="{{ __('Event name or request ID') }}">
                </div>

                <div>
                    <label for="date_from" class="block text-sm font-medium text-slate-700">{{ __('From') }}</label>
                    <input id="date_from" name="date_from" type="date" value="{{ request('date_from') }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="date_to" class="block text-sm font-medium text-slate-700">{{ __('To') }}</label>
                    <input id="date_to" name="date_to" type="date" value="{{ request('date_to') }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div class="flex items-end gap-2">
                    <div>
                        <label for="per_page" class="block text-sm font-medium text-slate-700">{{ __('Per page') }}</label>
                        <select id="per_page" name="per_page" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($perPageOptions as $option)
                                <option value="{{ $option }}" @selected((int) request('per_page', 50) === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Apply') }}</button>
                    <a href="{{ route('catalog.audit.index') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Clear') }}</a>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="audit-list-heading">
            <div class="border-b border-slate-200 px-5 py-5 sm:px-6">
                <h2 id="audit-list-heading" class="text-lg font-semibold text-slate-900">{{ __('Catalog audit events') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ trans_choice(':count event shown|:count events shown', $auditLogs->count(), ['count' => $auditLogs->count()]) }}</p>
            </div>

            @if ($auditLogs->isEmpty())
                <div class="px-6 py-16 text-center">
                    <p class="font-semibold text-slate-900">{{ __('No catalog audit events match these filters.') }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Only catalog-changing actions are recorded here.') }}</p>
                </div>
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($auditLogs as $auditLog)
                        @php
                            $eventLabel = $auditLog->eventLabel();
                            $summary = trim($auditLog->summary());
                            $showSummary = $summary !== '' && strcasecmp($summary, $eventLabel) !== 0;
                        @endphp
                        <article class="grid gap-4 px-5 py-5 sm:px-6 lg:grid-cols-[11rem_minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1fr)]">
                            <div>
                                <time datetime="{{ $auditLog->created_at?->toAtomString() }}" class="text-sm font-semibold text-slate-900">{{ $auditLog->created_at?->format('Y-m-d H:i:s') }}</time>
                                <p class="mt-1 break-all font-mono text-xs text-slate-400">{{ $auditLog->request_id ?: __('No request ID') }}</p>
                            </div>

                            <div class="min-w-0">
                                <p class="font-semibold text-slate-900">{{ $eventLabel }}</p>
                                @if ($showSummary)
                                    <p class="mt-2 text-sm text-slate-600">{{ $summary }}</p>
                                @endif
                            </div>

                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Actor') }}</p>
                                <p class="mt-1 break-words text-sm font-semibold text-slate-800">{{ $auditLog->actorLabel() }}</p>
                                @if ($auditLog->getProperty('actor_email'))
                                    <p class="mt-1 break-all text-xs text-slate-500">{{ $auditLog->getProperty('actor_email') }}</p>
                                @endif
                            </div>

                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Details') }}</p>
                                <p class="mt-1 break-all text-sm text-slate-700">{{ $auditLog->subjectLabel() }}</p>
                                <a href="{{ route('catalog.audit.show', $auditLog->getKey()) }}" class="mt-2 inline-block text-xs font-medium text-indigo-600 hover:text-indigo-500">{{ __('View details') }} →</a>
                            </div>
                        </article>
                    @endforeach
                </div>

                @if ($auditLogs->hasPages())
                    <div class="border-t border-slate-200 px-5 py-4 sm:px-6">{{ $auditLogs->links() }}</div>
                @endif
            @endif
        </section>
    </div>
</x-app-layout>
