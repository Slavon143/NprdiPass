<x-app-layout>
    <x-slot name="header"><div><a class="text-sm font-medium text-indigo-600" href="{{ route('catalog.imports.index') }}">{{ __('Import & export') }}</a><h1 class="text-2xl font-semibold text-slate-900">{{ $job->source_filename }}</h1></div></x-slot>
    <div class="space-y-6">
        <section class="grid gap-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:grid-cols-3 lg:grid-cols-6" data-testid="import-summary">
            @foreach(['status' => $job->status->value, 'total' => $job->total_rows, 'valid' => $job->valid_rows, 'invalid' => $job->invalid_rows, 'created' => $job->created_rows, 'updated' => $job->updated_rows] as $label => $value)<div><p class="text-xs font-semibold uppercase text-slate-400">{{ __($label) }}</p><p class="mt-1 text-xl font-semibold text-slate-900">{{ $value }}</p></div>@endforeach
        </section>

        @if($canWrite && in_array($job->status->value, ['uploaded', 'previewed'], true))
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-lg font-semibold">{{ __('Column mapping') }}</h2><p class="mt-1 text-sm text-slate-600">{{ __('Name is required. Slug is generated from name when it is not mapped.') }}</p>
                <form method="POST" action="{{ route('catalog.imports.preview', $job) }}" class="mt-5 space-y-4" data-testid="import-preview-form">@csrf
                    @if($templates->isNotEmpty())<div class="max-w-md"><x-input-label for="mapping_template" :value="__('Load saved mapping')" /><select id="mapping_template" class="mt-1 block w-full rounded-lg border-slate-300"><option value="">{{ __('Choose a template') }}</option>@foreach($templates as $template)<option value="{{ $template->uuid }}">{{ $template->name }}</option>@endforeach</select></div>@endif
                    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        @foreach($fields as $field)
                            @php $selected = $job->mapping[$field] ?? collect($job->headers)->first(fn($header) => str_replace([' ', '-'], '_', strtolower($header)) === $field); @endphp
                            @php $fieldLabel = str_starts_with($field, 'attribute:') ? 'Attribute: '.substr($field, 10) : ucwords(str_replace('_', ' ', $field)); @endphp
                            <div><x-input-label :for="'mapping_'.$field" :value="$fieldLabel.($field === 'name' ? ' *' : '')" /><select id="mapping_{{ $field }}" name="mapping[{{ $field }}]" class="mt-1 block w-full rounded-lg border-slate-300"><option value="">{{ __('Not mapped') }}</option>@foreach($job->headers ?? [] as $header)<option value="{{ $header }}" @selected($selected === $header)>{{ $header }}</option>@endforeach</select></div>
                        @endforeach
                    </div>
                    <x-primary-button>{{ __('Generate safe preview') }}</x-primary-button>
                </form><x-input-error :messages="$errors->all()" class="mt-3" />
            </section>
        @endif

        @if(in_array($job->status->value, ['previewed', 'processing', 'failed'], true) && $canWrite)
            <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-indigo-200 bg-indigo-50 p-5"><div><h2 class="font-semibold text-indigo-950">{{ __('Apply one resumable chunk') }}</h2><p class="text-sm text-indigo-800">{{ __('Cursor: row :row · chunk size: :size. Repeating this action never reapplies completed rows.', ['row' => $job->next_row, 'size' => $job->chunk_size]) }}</p></div><form method="POST" action="{{ route('catalog.imports.apply', $job) }}">@csrf<input type="hidden" name="max_chunks" value="1"><x-primary-button data-testid="import-apply">{{ $job->status->value === 'processing' ? __('Continue import') : __('Apply import') }}</x-primary-button></form></section>
        @endif

        @if($job->mapping && $canWrite)
            <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200"><h2 class="font-semibold text-slate-900">{{ __('Reuse this mapping') }}</h2><form class="mt-3 flex flex-wrap gap-3" method="POST" action="{{ route('catalog.imports.templates.store') }}">@csrf<input name="name" required maxlength="255" class="min-w-72 rounded-lg border-slate-300" placeholder="{{ __('Template name') }}">@foreach($job->mapping as $field => $header)<input type="hidden" name="mapping[{{ $field }}]" value="{{ $header }}">@endforeach<x-secondary-button type="submit">{{ __('Save mapping template') }}</x-secondary-button></form></section>
        @endif

        @if($job->invalid_rows > 0 || $job->skipped_rows > 0)<div class="flex justify-end"><a href="{{ route('catalog.imports.errors', $job) }}" class="text-sm font-semibold text-indigo-700">{{ __('Download row error report (CSV)') }}</a></div>@endif

        @if($job->records->isNotEmpty())
            <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200"><div class="border-b border-slate-200 px-6 py-4"><h2 class="font-semibold">{{ __('Preview rows') }}</h2></div><div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-sm"><thead class="bg-slate-50"><tr><th class="px-4 py-3 text-left">{{ __('Row') }}</th><th class="px-4 py-3 text-left">{{ __('Operation') }}</th><th class="px-4 py-3 text-left">{{ __('Name') }}</th><th class="px-4 py-3 text-left">{{ __('Slug') }}</th><th class="px-4 py-3 text-left">{{ __('Result') }}</th></tr></thead><tbody class="divide-y divide-slate-100">@foreach($job->records as $record)<tr><td class="px-4 py-3">{{ $record->row_number }}</td><td class="px-4 py-3">{{ $record->operation }}</td><td class="px-4 py-3">{{ $record->mapped_data['name'] ?? '—' }}</td><td class="px-4 py-3">{{ $record->mapped_data['slug'] ?? '—' }}</td><td class="px-4 py-3"><span class="font-medium {{ $record->errors->isEmpty() ? 'text-emerald-700' : 'text-rose-700' }}">{{ $record->status->value }}</span>@foreach($record->errors as $error)<p class="mt-1 max-w-xl text-xs text-rose-700">{{ $error->field ? $error->field.': ' : '' }}{{ $error->message }}</p>@endforeach</td></tr>@endforeach</tbody></table></div></section>
        @endif
    </div>

@if($templates->isNotEmpty())
    @push('scripts')
        <script>
            (() => {
                const templates = {{ Illuminate\Support\Js::from($templates->mapWithKeys(fn($template) => [$template->uuid => $template->mapping])) }};
                document.getElementById('mapping_template')?.addEventListener('change', (event) => {
                    const mapping = templates[event.target.value] ?? {};
                    Object.entries(mapping).forEach(([field, header]) => {
                        const select = document.getElementById(`mapping_${field}`);
                        if (select && [...select.options].some((option) => option.value === header)) select.value = header;
                    });
                });
            })();
        </script>
    @endpush
@endif
</x-app-layout>
