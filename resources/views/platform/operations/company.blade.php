<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Platform operations') }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ $company->name }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $company->uuid }}</p>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <section class="grid gap-4 sm:grid-cols-4">
            @foreach(['status', 'members', 'products', 'failed_jobs'] as $key)
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-slate-500">{{ str_replace('_', ' ', ucfirst($key)) }}</p>
                    <p class="mt-2 text-2xl font-bold text-slate-900">{{ $snapshot[$key] }}</p>
                </div>
            @endforeach
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Support access') }}</h2>
                <form method="POST" action="{{ route('platform.operations.support-grants.store', $company) }}" class="mt-4 space-y-3">
                    @csrf
                    <textarea name="reason" rows="3" required minlength="10" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="{{ __('Reason') }}"></textarea>
                    <input name="minutes" type="number" min="15" max="1440" value="60" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">{{ __('Grant access') }}</button>
                </form>
                <div class="mt-5 divide-y divide-slate-200">
                    @foreach($supportGrants as $grant)
                        <div class="flex items-center justify-between gap-3 py-3 text-sm">
                            <div>
                                <p class="font-semibold text-slate-900">{{ $grant->expires_at->toDateTimeString() }}</p>
                                <p class="text-slate-500">
                                    @if($grant->revoked_at)
                                        {{ __('Revoked') }}
                                    @elseif($grant->expires_at->isPast())
                                        {{ __('Expired') }}
                                    @else
                                        {{ __('Active until expiry') }}
                                    @endif
                                </p>
                            </div>
                            @if(!$grant->revoked_at && !$grant->expires_at->isPast())
                                <form method="POST" action="{{ route('platform.operations.support-grants.revoke', [$company, $grant]) }}">
                                    @csrf
                                    <button class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">{{ __('Revoke') }}</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Feature flag override') }}</h2>
                <form method="POST" action="{{ route('platform.operations.feature-flags.update', $company) }}" class="mt-4 space-y-3">
                    @csrf
                    <select name="flag" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @foreach($knownFlags as $flag)
                            <option value="{{ $flag }}">{{ $flag }}</option>
                        @endforeach
                    </select>
                    <select name="enabled" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="1">{{ __('Enabled') }}</option>
                        <option value="0">{{ __('Disabled') }}</option>
                    </select>
                    <textarea name="reason" rows="3" required minlength="10" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="{{ __('Reason') }}"></textarea>
                    <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">{{ __('Save override') }}</button>
                </form>
                <div class="mt-5 divide-y divide-slate-200">
                    @foreach($flagOverrides as $override)
                        <div class="py-3 text-sm">
                            <p class="font-semibold text-slate-900">{{ $override->flag }}: {{ $override->enabled ? __('enabled') : __('disabled') }}</p>
                            <p class="text-slate-500">{{ $override->reason }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Incidents') }}</h2>
            <div class="mt-4 divide-y divide-slate-200">
                @forelse($incidents as $incident)
                    <div class="flex items-center justify-between gap-3 py-3 text-sm">
                        <div>
                            <p class="font-semibold text-slate-900">{{ $incident->title }}</p>
                            <p class="text-slate-500">{{ $incident->severity->value }} · {{ $incident->status->value }} · {{ $incident->type }}</p>
                        </div>
                        @if($incident->status->value === 'open')
                            <form method="POST" action="{{ route('platform.operations.incidents.resolve', $incident) }}">
                                @csrf
                                <button class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">{{ __('Resolve') }}</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __('No incidents reported.') }}</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Report incident') }}</h2>
            <form method="POST" action="{{ route('platform.operations.incidents.store', $company) }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                @csrf
                <input name="type" required class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="{{ __('Type') }}">
                <select name="severity" class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="warning">{{ __('Warning') }}</option>
                    <option value="critical">{{ __('Critical') }}</option>
                    <option value="info">{{ __('Info') }}</option>
                </select>
                <input name="title" required class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:col-span-2" placeholder="{{ __('Title') }}">
                <textarea name="description" rows="3" class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:col-span-2" placeholder="{{ __('Description') }}"></textarea>
                <button class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 sm:w-fit">{{ __('Report') }}</button>
            </form>
        </section>
    </div>
</x-app-layout>
