<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div><p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }}</p><h1 class="text-2xl font-semibold text-slate-900">{{ __('Production import & export') }}</h1></div>
            <div class="flex gap-2">
                <a class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" href="{{ route('catalog.imports.export', 'csv') }}">{{ __('Export CSV') }}</a>
                <a class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" href="{{ route('catalog.imports.export', 'xlsx') }}">{{ __('Export XLSX') }}</a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if ($canWrite)
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Upload product data') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('CSV and XLSX up to :size MB. Every import requires a preview before any catalog write.', ['size' => round(config('imports.max_upload_kilobytes') / 1024)]) }}</p>
                <form class="mt-5 grid gap-4 md:grid-cols-4" method="POST" action="{{ route('catalog.imports.store') }}" enctype="multipart/form-data" data-testid="import-upload-form">
                    @csrf
                    <div class="md:col-span-2"><x-input-label for="file" :value="__('File')" /><input id="file" name="file" type="file" accept=".csv,.xlsx" required class="mt-1 block w-full rounded-lg border border-slate-300 p-2 text-sm"></div>
                    <div><x-input-label for="mode" :value="__('Mode')" /><select id="mode" name="mode" class="mt-1 block w-full rounded-lg border-slate-300"><option value="upsert">{{ __('Create or update') }}</option><option value="create">{{ __('Create only') }}</option><option value="update">{{ __('Update only') }}</option></select></div>
                    <div><x-input-label for="chunk_size" :value="__('Chunk size')" /><input id="chunk_size" name="chunk_size" type="number" min="1" max="1000" value="250" class="mt-1 block w-full rounded-lg border-slate-300"></div>
                    <div class="md:col-span-3"><x-input-label for="idempotency_key" :value="__('Idempotency key (optional)')" /><x-text-input id="idempotency_key" name="idempotency_key" class="mt-1 block w-full" placeholder="supplier-feed-2026-08-20" /></div>
                    <div class="flex items-end"><x-primary-button>{{ __('Upload and map') }}</x-primary-button></div>
                </form>
                <x-input-error :messages="$errors->all()" class="mt-3" />
            </section>
        @endif

        <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 px-6 py-4"><h2 class="font-semibold text-slate-900">{{ __('Import history') }}</h2></div>
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-sm"><thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="px-6 py-3">{{ __('File') }}</th><th class="px-6 py-3">{{ __('Mode') }}</th><th class="px-6 py-3">{{ __('Status') }}</th><th class="px-6 py-3">{{ __('Rows') }}</th><th class="px-6 py-3">{{ __('Created') }}</th></tr></thead><tbody class="divide-y divide-slate-100">
                @forelse($jobs as $job)<tr><td class="px-6 py-4"><a class="font-medium text-indigo-700 hover:underline" href="{{ route('catalog.imports.show', $job) }}">{{ $job->source_filename }}</a></td><td class="px-6 py-4">{{ $job->mode->value }}</td><td class="px-6 py-4">{{ $job->status->value }}</td><td class="px-6 py-4">{{ $job->processed_rows }} / {{ $job->total_rows }}</td><td class="px-6 py-4 text-slate-500">{{ $job->created_at?->diffForHumans() }}</td></tr>@empty<tr><td colspan="5" class="px-6 py-10 text-center text-slate-500">{{ __('No imports yet.') }}</td></tr>@endforelse
            </tbody></table></div>
            <div class="px-6 py-4">{{ $jobs->links() }}</div>
        </section>
    </div>
</x-app-layout>
