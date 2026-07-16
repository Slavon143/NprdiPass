<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }}</p>
            <div class="flex items-center justify-between">
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Catalog Audit Detail') }}</h1>
                <a href="{{ route('catalog.audit.index', request()->query()) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">{{ __('← Back to catalog audit') }}</a>
            </div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-6 py-5">
                <h2 class="text-lg font-semibold text-slate-900">{{ $auditLog->eventLabel() }}</h2>
                <p class="mt-1 font-mono text-sm text-indigo-700">{{ $auditLog->event }}</p>
            </div>

            <div class="divide-y divide-slate-100 px-6 py-5">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Timestamp') }}</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $auditLog->created_at?->format('Y-m-d H:i:s') }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Company') }}</dt>
                        <dd class="mt-1 text-sm text-slate-700">{{ $company->name }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Actor') }}</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">{{ $auditLog->actorLabel() }}</dd>
                        @if ($auditLog->getProperty('actor_email'))
                            <dd class="mt-1 text-xs text-slate-500">{{ $auditLog->getProperty('actor_email') }}</dd>
                        @endif
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Subject') }}</dt>
                        <dd class="mt-1 text-sm text-slate-700">{{ $auditLog->subjectLabel() }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Request ID') }}</dt>
                        <dd class="mt-1 break-all font-mono text-xs text-slate-500">{{ $auditLog->request_id ?: __('None') }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('IP Address') }}</dt>
                        <dd class="mt-1 font-mono text-xs text-slate-500">{{ $auditLog->ip_address ?: __('None') }}</dd>
                    </div>
                </dl>

                @if (is_array($changes) && $changes !== [])
                    <div class="mt-6">
                        <h3 class="text-base font-semibold text-slate-900">{{ __('Changed fields') }}</h3>
                        <dl class="mt-3 space-y-3">
                            @foreach ($changes as $field => $change)
                                <div class="rounded-lg bg-slate-50 p-3">
                                    <dt class="font-mono text-xs text-slate-500">{{ $field }}</dt>
                                    <dd class="mt-1 break-words text-sm text-slate-700">
                                        <span class="text-slate-400">{{ $change['old'] ?? __('Not set') }}</span>
                                        <span aria-hidden="true"> → </span>
                                        <span class="font-semibold">{{ $change['new'] ?? __('Not set') }}</span>
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif

                @if ($safeProperties !== [])
                    <div class="mt-6">
                        <h3 class="text-base font-semibold text-slate-900">{{ __('Safe metadata') }}</h3>
                        <dl class="mt-3 space-y-2">
                            @foreach ($safeProperties as $key => $value)
                                @if (is_scalar($value) || $value === null)
                                    <div class="flex gap-2 text-sm">
                                        <dt class="font-mono text-slate-500">{{ $key }}:</dt>
                                        <dd class="break-all text-slate-700">{{ is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES) }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>
                    </div>
                @endif
            </div>
        </section>
    </div>
</x-app-layout>
