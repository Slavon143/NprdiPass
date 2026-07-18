<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Security') }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Audit history') }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('Security-relevant activity for :company.', ['company' => $company->name]) }}</p>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="audit-filters-heading">
            <div class="mb-4">
                <h2 id="audit-filters-heading" class="text-base font-semibold text-slate-900">{{ __('Filter audit events') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('Date ranges are limited to 366 days. Filters never include another company.') }}</p>
            </div>

            <form method="GET" action="{{ route('audit.index') }}" class="grid gap-4 md:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_10rem_10rem_auto] xl:items-end">
                <div>
                    <label for="event" class="block text-sm font-medium text-slate-700">{{ __('Event') }}</label>
                    <select id="event" name="event" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">{{ __('All events') }}</option>
                        @foreach ($events as $eventOption)
                            <option value="{{ $eventOption->value }}" @selected(request('event') === $eventOption->value)>{{ $eventOption->label() }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('event')" class="mt-2" />
                </div>

                <div>
                    <label for="actor" class="block text-sm font-medium text-slate-700">{{ __('Actor') }}</label>
                    <select id="actor" name="actor" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">{{ __('All actors') }}</option>
                        @foreach ($actors as $actorOption)
                            <option value="{{ $actorOption->uuid }}" @selected(request('actor') === $actorOption->uuid)>{{ $actorOption->name }} — {{ $actorOption->email }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('actor')" class="mt-2" />
                </div>

                <div>
                    <label for="date_from" class="block text-sm font-medium text-slate-700">{{ __('From') }}</label>
                    <input id="date_from" name="date_from" type="date" value="{{ request('date_from') }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <x-input-error :messages="$errors->get('date_from')" class="mt-2" />
                </div>

                <div>
                    <label for="date_to" class="block text-sm font-medium text-slate-700">{{ __('To') }}</label>
                    <input id="date_to" name="date_to" type="date" value="{{ request('date_to') }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <x-input-error :messages="$errors->get('date_to')" class="mt-2" />
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Apply') }}</button>
                    <a href="{{ route('audit.index') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Clear') }}</a>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="audit-list-heading">
            <div class="border-b border-slate-200 px-5 py-5 sm:px-6">
                <h2 id="audit-list-heading" class="text-lg font-semibold text-slate-900">{{ __('Recorded events') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ trans_choice(':count event shown|:count events shown', $auditLogs->count(), ['count' => $auditLogs->count()]) }}</p>
            </div>

            @if ($auditLogs->isEmpty())
                <div class="px-6 py-16 text-center">
                    <p class="font-semibold text-slate-900">{{ __('No audit events match these filters.') }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Only security-relevant actions are recorded.') }}</p>
                </div>
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($auditLogs as $auditLog)
                        @php
                            $changes = $auditLog->getProperty('changes', []);
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
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Subject') }}</p>
                                <p class="mt-1 break-all text-sm text-slate-700">{{ $auditLog->subjectLabel() }}</p>

                                @if (is_array($changes) && $changes !== [])
                                    <details class="mt-3 rounded-lg bg-slate-50 p-3 text-sm">
                                        <summary class="cursor-pointer font-semibold text-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">{{ __('View safe changes') }}</summary>
                                        <dl class="mt-3 space-y-3">
                                            @foreach ($changes as $field => $change)
                                                <div>
                                                    <dt class="font-mono text-xs text-slate-500">{{ $field }}</dt>
                                                    <dd class="mt-1 break-words text-slate-700">
                                                        <span class="text-slate-400">{{ $change['old'] ?? __('Not set') }}</span>
                                                        <span aria-hidden="true"> → </span>
                                                        <span>{{ $change['new'] ?? __('Not set') }}</span>
                                                    </dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    </details>
                                @endif
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
