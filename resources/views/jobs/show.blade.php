<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Job center') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ $job->name }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $job->uuid }}</p>
            </div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm font-medium text-slate-500">{{ __('Status') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $job->status->value }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-slate-500">{{ __('Queue') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $job->queue }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-slate-500">{{ __('Attempts') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $job->attempts }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-slate-500">{{ __('Progress') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $job->progress_current }}@if($job->progress_total !== null)/{{ $job->progress_total }}@endif</dd>
                </div>
            </dl>
        </section>

        @if($job->failure_message)
            <section class="rounded-2xl border border-rose-200 bg-rose-50 p-5">
                <h2 class="text-sm font-semibold text-rose-900">{{ $job->failure_code ?: __('Failure') }}</h2>
                <p class="mt-2 text-sm text-rose-800">{{ $job->failure_message }}</p>
            </section>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Payload summary') }}</h2>
            <pre class="mt-4 overflow-x-auto rounded-xl bg-slate-950 p-4 text-xs text-slate-100">{{ json_encode($job->payload_summary ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </section>
    </div>
</x-app-layout>
