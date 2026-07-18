<x-app-layout>
<div class="container mx-auto px-4 py-8">
    @php
        $payload = $version->payload ?? [];
        $snapshotContext = $payload['_catalog_context'] ?? [];
        $snapshotProduct = $snapshotContext['product'] ?? [];
        $snapshotVariant = $snapshotContext['default_variant'] ?? null;
        $snapshotMedia = $snapshotContext['media'] ?? [];
        $snapshotDocuments = $snapshotContext['documents'] ?? [];
        $dataSections = $payload['data'] ?? [];
        $translations = $payload['translations'] ?? [];
        $enabledSections = $payload['enabled_sections'] ?? array_keys($dataSections);
        $defaultLocale = $passport->default_language ?? 'sv';

        $statusTone = match ($version->status->value) {
            'published' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
            'superseded' => 'bg-blue-100 text-blue-800 border-blue-200',
            'withdrawn' => 'bg-red-100 text-red-800 border-red-200',
            default => 'bg-slate-100 text-slate-700 border-slate-200',
        };

        $fieldLabel = static fn (string $field): string => isset($fields[$field])
            ? $fields[$field]->label()
            : \Illuminate\Support\Str::headline(str_replace('_', ' ', $field));

        $formatScalar = static function (mixed $value): string {
            if ($value === true || $value === 1 || $value === '1') {
                return __('Yes');
            }

            if ($value === false || $value === 0 || $value === '0') {
                return __('No');
            }

            if ($value === null || $value === '') {
                return '—';
            }

            return (string) $value;
        };

        $formatList = static function (array $items): string {
            $items = array_values(array_filter($items, fn ($item): bool => ! is_array($item) && $item !== null && $item !== ''));

            return $items === [] ? '—' : implode(', ', array_map('strval', $items));
        };
    @endphp

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Published passport snapshot') }}</p>
            <h1 class="text-2xl font-bold text-slate-900">
                {{ __('Version :version: :product', ['version' => $version->version_number ?? 'N/A', 'product' => $snapshotProduct['name'] ?? $product->name]) }}
            </h1>
            <div class="mt-2 flex flex-wrap gap-2">
                <span class="rounded-full border px-3 py-1 text-sm font-semibold {{ $statusTone }}">
                    {{ ucfirst($version->status->value) }}
                </span>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700">
                    {{ __('Draft revision #:revision', ['revision' => $version->draft_revision]) }}
                </span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
            <a href="{{ route('catalog.products.passport.versions.index', $product->uuid) }}" class="font-semibold text-indigo-700 hover:underline">{{ __('Version history') }}</a>
            <a href="{{ route('catalog.products.passport.show', $product->uuid) }}" class="font-semibold text-indigo-700 hover:underline">{{ __('Back to passport') }}</a>
        </div>
    </div>

    <div class="space-y-6">
        <section class="rounded-lg bg-white p-6 shadow">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Publication metadata') }}</h2>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-slate-500">{{ __('Version number') }}</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $version->version_number ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __('Version status') }}</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ ucfirst($version->status->value) }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __('Published at') }}</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $version->published_at?->format('Y-m-d H:i') ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __('Published by') }}</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $version->publisher?->name ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __('Schema version') }}</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $version->schema_version }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __('Draft revision used') }}</dt>
                    <dd class="mt-1 font-semibold text-slate-900">#{{ $version->draft_revision }}</dd>
                </div>
                @if($version->superseded_at)
                    <div>
                        <dt class="text-slate-500">{{ __('Superseded at') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-900">{{ $version->superseded_at->format('Y-m-d H:i') }}</dd>
                    </div>
                @endif
                @if($version->withdrawn_at)
                    <div>
                        <dt class="text-slate-500">{{ __('Withdrawn at') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-900">{{ $version->withdrawn_at->format('Y-m-d H:i') }}</dd>
                    </div>
                @endif
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-slate-500">{{ __('Content checksum') }}</dt>
                    <dd class="mt-1 break-all font-mono text-xs text-slate-700">{{ $version->content_checksum ?? 'N/A' }}</dd>
                </div>
            </dl>
        </section>

        <section class="rounded-lg bg-white p-6 shadow">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Catalog snapshot at publication') }}</h2>
            <p class="mt-1 text-sm text-slate-600">
                {{ __('This is the product data captured into this passport version. It may differ from the current catalog record after later edits.') }}
            </p>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-slate-500">{{ __('Product name') }}</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $snapshotProduct['name'] ?? $product->name }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __('Brand') }}</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $snapshotProduct['brand'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __('Manufacturer') }}</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $snapshotProduct['manufacturer'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __('Catalog product status at publish time') }}</dt>
                    <dd class="mt-1">
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                            {{ $snapshotProduct['status'] ?? $product->status->value }}
                        </span>
                    </dd>
                </div>
                @if(is_array($snapshotVariant))
                    <div class="sm:col-span-2 lg:col-span-4">
                        <dt class="text-slate-500">{{ __('Default variant') }}</dt>
                        <dd class="mt-1 text-slate-900">
                            {{ $snapshotVariant['name'] ?: __('Default variant') }}
                            <span class="text-slate-500">· SKU: {{ $snapshotVariant['sku'] ?: '—' }} · GTIN: {{ $snapshotVariant['gtin'] ?: '—' }} · MPN: {{ $snapshotVariant['mpn'] ?: '—' }}</span>
                        </dd>
                    </div>
                @endif
            </dl>
        </section>

        <section class="rounded-lg bg-white p-6 shadow">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('DPP sections in this snapshot') }}</h2>

            @if(empty($dataSections) && empty($translations))
                <p class="mt-4 text-sm text-slate-500">{{ __('No DPP section data in this snapshot.') }}</p>
            @else
                <div class="mt-4 space-y-4">
                    @foreach($sectionKeys as $sectionKey)
                        @php
                            $sectionData = $dataSections[$sectionKey] ?? [];
                            $translatedData = $translations[$defaultLocale][$sectionKey] ?? [];
                            $mergedData = array_filter(
                                array_merge($translatedData, $sectionData),
                                fn ($value): bool => $value !== null && $value !== '' && $value !== []
                            );
                            $sectionDefinition = $sections[$sectionKey] ?? null;
                        @endphp

                        @if(in_array($sectionKey, $enabledSections, true) && $mergedData !== [])
                            <div class="rounded-lg border border-slate-200 p-4">
                                <h3 class="font-semibold text-slate-900">
                                    {{ $sectionDefinition?->key->label() ?? \Illuminate\Support\Str::headline(str_replace('_', ' ', $sectionKey)) }}
                                </h3>

                                @if($sectionKey === 'materials_and_composition' && isset($mergedData['materials']) && is_array($mergedData['materials']))
                                    <div class="mt-3 overflow-x-auto">
                                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                                                <tr>
                                                    <th class="px-3 py-2">{{ __('Material') }}</th>
                                                    <th class="px-3 py-2">{{ __('Percentage') }}</th>
                                                    <th class="px-3 py-2">{{ __('Recycled content') }}</th>
                                                    <th class="px-3 py-2">{{ __('Origin') }}</th>
                                                    <th class="px-3 py-2">{{ __('Hazardous') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100">
                                                @foreach($mergedData['materials'] as $material)
                                                    <tr>
                                                        <td class="px-3 py-2 font-medium text-slate-900">{{ $material['name'] ?? '—' }}</td>
                                                        <td class="px-3 py-2 text-slate-700">{{ isset($material['percentage']) ? $material['percentage'].'%' : '—' }}</td>
                                                        <td class="px-3 py-2 text-slate-700">{{ isset($material['recycled_content_percentage']) ? $material['recycled_content_percentage'].'%' : '—' }}</td>
                                                        <td class="px-3 py-2 text-slate-700">{{ $material['country_of_origin'] ?? '—' }}</td>
                                                        <td class="px-3 py-2 text-slate-700">{{ ! empty($material['hazardous']) ? __('Yes') : __('No') }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    @php($mergedData = array_diff_key($mergedData, ['materials' => true]))
                                @endif

                                @if($mergedData !== [])
                                    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                                        @foreach($mergedData as $field => $value)
                                            <div>
                                                <dt class="text-slate-500">{{ $fieldLabel($field) }}</dt>
                                                <dd class="mt-1 font-medium text-slate-900">
                                                    @if(is_array($value))
                                                        {{ $formatList($value) }}
                                                    @elseif(is_string($value) && str_starts_with($value, 'http'))
                                                        <a href="{{ $value }}" class="text-indigo-700 hover:underline" target="_blank" rel="noopener noreferrer">{{ $value }}</a>
                                                    @else
                                                        {{ $formatScalar($value) }}
                                                    @endif
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </section>

        <section class="rounded-lg bg-white p-6 shadow">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Documents captured in this snapshot') }}</h2>
            @if(!empty($snapshotDocuments))
                <div class="mt-4 space-y-3">
                    @foreach($snapshotDocuments as $document)
                        <div class="rounded-lg border border-slate-200 p-4 text-sm">
                            <div class="font-semibold text-slate-900">{{ $document['title'] ?? $document['original_filename'] ?? __('Untitled document') }}</div>
                            <div class="mt-1 text-slate-600">
                                {{ $document['document_type'] ?? 'document' }}
                                @if(!empty($document['role'])) · {{ __('Role: :role', ['role' => $document['role']]) }} @endif
                                @if(!empty($document['version_number'])) · {{ __('Version :version', ['version' => $document['version_number']]) }} @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-4 text-sm text-slate-500">{{ __('No documents were captured into this published snapshot.') }}</p>
            @endif
        </section>

        <section class="rounded-lg bg-white p-6 shadow">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Media captured in this snapshot') }}</h2>
            @if(!empty($snapshotMedia))
                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($snapshotMedia as $media)
                        <div class="rounded-lg border border-slate-200 p-4 text-sm">
                            <div class="font-semibold text-slate-900">{{ $media['alt_text'] ?: ($media['original_filename'] ?? __('Image')) }}</div>
                            <div class="mt-1 text-slate-600">
                                {{ $media['mime_type'] ?? 'image' }}
                                @if(!empty($media['width']) && !empty($media['height'])) · {{ $media['width'] }}×{{ $media['height'] }} @endif
                                @if(!empty($media['size_bytes'])) · {{ number_format($media['size_bytes'] / 1024, 1) }} KB @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-4 text-sm text-slate-500">{{ __('No media assets were captured into this published snapshot.') }}</p>
            @endif
        </section>
    </div>
</div>
</x-app-layout>
