<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Products') }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ __('Products owned by :company.', ['company' => $company->name]) }}</p>
            </div>
            @if ($canCreate)
                <a href="{{ route('catalog.products.create') }}" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2" data-testid="product-create-button">{{ __('Create product') }}</a>
            @else
                <x-badge>{{ __('Read only') }}</x-badge>
            @endif
        </div>
    </x-slot>

    <?php
        $selectedProductStatuses = $criteria->productStatuses;
        $selectedVariantStatuses = $criteria->variantStatuses;
        $selectedCategories = $criteria->categoryUuids;
        $selectedMissing = $criteria->missingData;
        $selectedPassportStatuses = $criteria->passportStatuses;
        $needsAttention = $criteria->needsAttention;
        $attributeCriteriaByUuid = collect($criteria->attributeFilters)->keyBy('definitionUuid');
    ?>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8" data-testid="products-page">
        <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <form method="GET" action="{{ route('catalog.products.index') }}" class="space-y-5">
                <div class="grid gap-4 lg:grid-cols-[minmax(0,1.4fr)_12rem_12rem_10rem] lg:items-end">
                    <div>
                        <x-input-label for="q" :value="__('Search')" />
                        <x-text-input id="q" name="q" type="search" class="mt-1 block w-full" :value="$criteria->query" placeholder="Name, slug, SKU, GTIN, MPN, Variant, Category" />
                    </div>
                    <div>
                        <x-input-label for="sort" :value="__('Sort')" />
                        <select id="sort" name="sort" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach (['relevance' => 'Relevance', 'updated' => 'Updated', 'created' => 'Created', 'name' => 'Name', 'brand' => 'Brand', 'variant_count' => 'Variant count'] as $value => $label)
                                <option value="{{ $value }}" @selected($criteria->sort === $value)>{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="direction" :value="__('Direction')" />
                        <select id="direction" name="direction" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="desc" @selected($criteria->direction === 'desc')>{{ __('Descending') }}</option>
                            <option value="asc" @selected($criteria->direction === 'asc')>{{ __('Ascending') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="per_page" :value="__('Per page')" />
                        <select id="per_page" name="per_page" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ([25, 50, 100] as $perPage)
                                <option value="{{ $perPage }}" @selected($criteria->perPage === $perPage)>{{ $perPage }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="grid gap-5 lg:grid-cols-3">
                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-900">{{ __('Product status') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (['draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived'] as $value => $label)
                                <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <input type="checkbox" name="product_statuses[]" value="{{ $value }}" class="rounded border-slate-300 text-indigo-600" @checked(in_array($value, $selectedProductStatuses, true))>
                                    <span>{{ __($label) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-900">{{ __('Variant status') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (['draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived'] as $value => $label)
                                <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <input type="checkbox" name="variant_statuses[]" value="{{ $value }}" class="rounded border-slate-300 text-indigo-600" @checked(in_array($value, $selectedVariantStatuses, true))>
                                    <span>{{ __($label) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-900">{{ __('Passport status') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (['not_created' => 'Not created', 'draft' => 'Draft', 'published' => 'Published'] as $value => $label)
                                <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <input type="checkbox" name="passport_statuses[]" value="{{ $value }}" class="rounded border-slate-300 text-indigo-600" @checked(in_array($value, $selectedPassportStatuses, true))>
                                    <span>{{ __($label) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </div>

                <div class="grid gap-5 lg:grid-cols-3">
                    <div>
                        <x-input-label for="readiness" :value="__('Activation readiness')" />
                        <select id="readiness" name="readiness" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="any" @selected($criteria->readiness === 'any')>{{ __('All') }}</option>
                            <option value="ready" @selected($criteria->readiness === 'ready')>{{ __('Ready') }}</option>
                            <option value="not_ready" @selected($criteria->readiness === 'not_ready')>{{ __('Not ready') }}</option>
                        </select>
                    </div>

                    <div class="flex items-end pb-1">
                        <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" name="needs_attention" value="1" class="rounded border-slate-300 text-indigo-600" @checked($needsAttention)>
                            <span class="font-medium">{{ __('Needs attention') }}</span>
                            <span class="text-xs text-slate-500">{{ __('(Blockers or missing passport)') }}</span>
                        </label>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-3">
                    <div>
                        <x-input-label for="category_uuids" :value="__('Categories')" />
                        <select id="category_uuids" name="category_uuids[]" multiple size="6" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($categoryOptions as $categoryOption)
                                <option value="{{ $categoryOption->uuid }}" @selected(in_array($categoryOption->uuid, $selectedCategories, true))>{{ str_repeat('- ', $categoryOption->depth) }}{{ $categoryOption->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid content-start gap-3">
                        <div>
                            <x-input-label for="category_mode" :value="__('Category mode')" />
                            <select id="category_mode" name="category_mode" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="primary" @selected($criteria->categoryMode === 'primary')>{{ __('Primary category') }}</option>
                                <option value="any" @selected($criteria->categoryMode === 'any')>{{ __('Any assigned category') }}</option>
                            </select>
                        </div>
                        <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" name="include_descendants" value="1" class="rounded border-slate-300 text-indigo-600" @checked($criteria->includeDescendants)>
                            <span>{{ __('Include descendants') }}</span>
                        </label>
                    </div>
                    <div class="grid content-start gap-3">
                        <div>
                            <x-input-label for="brand" :value="__('Brand')" />
                            <select id="brand" name="brand" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">{{ __('Any brand') }}</option>
                                @foreach ($brandOptions as $brand)
                                    <option value="{{ $brand }}" @selected($criteria->brand === $brand)>{{ $brand }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="manufacturer" :value="__('Manufacturer')" />
                            <select id="manufacturer" name="manufacturer" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">{{ __('Any manufacturer') }}</option>
                                @foreach ($manufacturerOptions as $manufacturer)
                                    <option value="{{ $manufacturer }}" @selected($criteria->manufacturer === $manufacturer)>{{ $manufacturer }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900">{{ __('Missing data') }}</legend>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach (['primary_category' => 'Primary category', 'default_variant' => 'Default Variant', 'primary_image' => 'Primary image', 'variant_sku' => 'Default Variant SKU', 'required_product_attribute' => 'Required Product attributes', 'required_variant_attribute' => 'Required Variant attributes'] as $value => $label)
                            <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                <input type="checkbox" name="missing_data[]" value="{{ $value }}" class="rounded border-slate-300 text-indigo-600" @checked(in_array($value, $selectedMissing, true))>
                                <span>{{ __($label) }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                @if ($attributeFilterDefinitions->isNotEmpty())
                    <section class="border-t border-slate-200 pt-5">
                        <h2 class="text-sm font-semibold text-slate-900">{{ __('Attribute filters') }}</h2>
                        <div class="mt-3 grid gap-4 lg:grid-cols-3">
                            @foreach ($attributeFilterDefinitions as $definition)
                                <?php $attributeFilter = $attributeCriteriaByUuid->get($definition->uuid); ?>
                                <div>
                                    <x-input-label for="attribute_{{ $definition->uuid }}" :value="$definition->name" />
                                    <input type="hidden" name="attributes[{{ $definition->uuid }}][definition]" value="{{ $definition->uuid }}">
                                    @if (in_array($definition->type->value, ['select', 'multiselect'], true))
                                        <select id="attribute_{{ $definition->uuid }}" name="attributes[{{ $definition->uuid }}][options][]" multiple size="4" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                            @foreach ($definition->options as $option)
                                                <option value="{{ $option->id }}" @selected(in_array((int) $option->id, $attributeFilter?->optionIds ?? [], true))>{{ $option->label }}</option>
                                            @endforeach
                                        </select>
                                    @elseif ($definition->type->value === 'boolean')
                                        <select id="attribute_{{ $definition->uuid }}" name="attributes[{{ $definition->uuid }}][boolean]" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                            <option value="">{{ __('Any value') }}</option>
                                            <option value="1" @selected($attributeFilter?->boolean === '1')>{{ __('Yes') }}</option>
                                            <option value="0" @selected($attributeFilter?->boolean === '0')>{{ __('No') }}</option>
                                            <option value="not_set" @selected($attributeFilter?->boolean === 'not_set')>{{ __('Not set') }}</option>
                                        </select>
                                    @elseif (in_array($definition->type->value, ['integer', 'decimal'], true))
                                        <div class="mt-1 grid grid-cols-2 gap-2">
                                            <input id="attribute_{{ $definition->uuid }}" name="attributes[{{ $definition->uuid }}][min]" value="{{ $attributeFilter?->min }}" inputmode="decimal" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="{{ __('Min') }}">
                                            <input name="attributes[{{ $definition->uuid }}][max]" value="{{ $attributeFilter?->max }}" inputmode="decimal" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="{{ __('Max') }}">
                                        </div>
                                    @elseif ($definition->type->value === 'date')
                                        <div class="mt-1 grid grid-cols-2 gap-2">
                                            <input id="attribute_{{ $definition->uuid }}" type="date" name="attributes[{{ $definition->uuid }}][from]" value="{{ $attributeFilter?->from }}" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                            <input type="date" name="attributes[{{ $definition->uuid }}][to]" value="{{ $attributeFilter?->to }}" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-5">
                    <div class="flex flex-wrap gap-2">
                        @foreach ($activeFilterChips as $chip)
                            <a href="{{ $chip['url'] }}" class="inline-flex items-center gap-2 rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-800">{{ $chip['label'] }} <span aria-hidden="true">x</span></a>
                        @endforeach
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500">{{ __('Apply filters') }}</button>
                        <a href="{{ route('catalog.products.index') }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Clear all') }}</a>
                    </div>
                </div>
            </form>
        </section>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
            <p>{{ trans_choice(':count product found|:count products found', $products->total(), ['count' => $products->total()]) }}</p>
            @if ($criteria->hasFilters())
                <p>{{ __('Filters are persisted in the URL.') }}</p>
            @endif
        </div>

        @if ($products->isEmpty())
            <section class="rounded-2xl border border-slate-200 bg-white px-6 py-20 shadow-sm text-center">
                @if ($hasProducts)
                    <p class="font-semibold text-slate-900">{{ __('No products match these filters.') }}</p>
                    <p class="mt-2 text-sm text-slate-500">{{ __('Try adjusting your filter criteria or clear all filters to see all products.') }}</p>
                @else
                    <div class="mx-auto max-w-sm">
                        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100">
                            <svg class="h-8 w-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m6 4.125 2.25 2.25m0 0 2.25 2.25M12 13.875l2.25-2.25M12 13.875l-2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />
                            </svg>
                        </div>
                        <p class="text-lg font-semibold text-slate-900">{{ __('No products yet') }}</p>
                        <p class="mt-2 text-sm text-slate-500">{{ __('Get started by creating your first product.') }}</p>
                        @if ($canCreate)
                            <a href="{{ route('catalog.products.create') }}" class="mt-6 inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Add your first product') }}</a>
                        @endif
                    </div>
                @endif
            </section>
        @else
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-5 py-3">{{ __('Product') }}</th>
                                <th class="px-5 py-3">{{ __('Status') }}</th>
                                <th class="px-5 py-3">{{ __('passports.label') }}</th>
                                <th class="px-5 py-3">{{ __('readiness.label') }}</th>
                                <th class="px-5 py-3">{{ __('Primary Category') }}</th>
                                <th class="px-5 py-3">{{ __('Updated') }}</th>
                                <th class="px-5 py-3 text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($products as $product)
                                @php
                                    $statusTone = match ($product->status->value) { 'active' => 'emerald', 'archived' => 'amber', default => 'indigo' };
                                    $summary = $passportSummaries[$product->uuid] ?? null;
                                @endphp
                                <tr @class(['bg-amber-50/40' => $product->status->value === 'archived'])>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            @if ($product->primaryMedia)
                                                <img src="{{ route('catalog.media.content', $product->primaryMedia->uuid) }}" alt="" loading="lazy" decoding="async" class="h-12 w-12 flex-shrink-0 rounded-lg bg-slate-100 object-cover">
                                            @else
                                                <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-lg bg-slate-100">
                                                    <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" />
                                                    </svg>
                                                </div>
                                            @endif
                                            <div class="min-w-0 flex-1">
                                                <p class="font-semibold text-slate-900 truncate">{{ $product->name }}</p>
                                                @php
                                                    $brand = $product->brand ? trim($product->brand) : null;
                                                    $manufacturer = $product->manufacturer ? trim($product->manufacturer) : null;
                                                @endphp
                                                @if ($brand || $manufacturer)
                                                    <p class="mt-0.5 text-xs text-slate-500 truncate">
                                                        @if ($brand && $manufacturer && strcasecmp($brand, $manufacturer) !== 0)
                                                            {{ $brand }} · {{ $manufacturer }}
                                                        @else
                                                            {{ $brand ?: $manufacturer }}
                                                        @endif
                                                    </p>
                                                @endif
                                                <p class="mt-0.5 text-xs text-slate-400">
                                                    {{ trans_choice(':count variant|:count variants', $product->variants_count, ['count' => $product->variants_count]) }} · {{ trans_choice(':count image|:count images', $product->product_media_count, ['count' => $product->product_media_count]) }}
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <x-badge :tone="$statusTone">{{ $product->status->value }}</x-badge>
                                    </td>
                                    <td class="px-5 py-4">
                                        @if ($summary === null || $summary['passport_status'] === 'not_created')
                                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-500/20">{{ __('Not created') }}</span>
                                            @if ($canManagePassports)
                                                <form method="POST" action="{{ route('catalog.products.passport.store', $product->uuid) }}" class="mt-1">
                                                    @csrf
                                                    <button type="submit" class="text-xs font-medium text-indigo-600 hover:text-indigo-500">{{ __('Create') }}</button>
                                                </form>
                                            @endif
                                        @elseif ($summary['passport_status'] === 'draft')
                                            <x-badge tone="indigo">{{ __('Draft') }}</x-badge>
                                            @if ($summary['passport_revision'] !== null)
                                                <p class="mt-1 text-xs text-slate-500">{{ __('Revision :rev', ['rev' => $summary['passport_revision']]) }}</p>
                                            @endif
                                        @elseif ($summary['passport_status'] === 'published')
                                            <x-badge tone="emerald">{{ __('Published') }}</x-badge>
                                        @elseif ($summary['passport_status'] === 'unpublished')
                                            <x-badge tone="amber">{{ __('Unpublished') }}</x-badge>
                                        @elseif ($summary['passport_status'] === 'archived')
                                            <x-badge tone="slate">{{ __('Archived') }}</x-badge>
                                        @endif
                                    </td>
                                     <td class="px-5 py-4">
                                        @php $score = is_array($summary) ? ($summary['score'] ?? null) : null; $blockers = is_array($summary) ? ((int) ($summary['blockers'] ?? 0)) : 0; $warnings = is_array($summary) ? ((int) ($summary['warnings'] ?? 0)) : 0; @endphp
                                        @if (is_array($summary) && is_int($score) && $score > 0)
                                            <a href="{{ route('catalog.products.passport.readiness', $product->uuid) }}" class="block group">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="text-sm font-semibold text-slate-900">{{ $score }}%</span>
                                                    @php
                                                        $readinessTone = match ($summary['readiness_status'] ?? null) {
                                                            'ready' => 'emerald',
                                                            'ready_with_warnings' => 'amber',
                                                            'not_ready' => 'red',
                                                            default => 'slate',
                                                        };
                                                        $readinessLabel = match ($summary['readiness_status'] ?? null) {
                                                            'ready' => __('Ready'),
                                                            'ready_with_warnings' => __('Warnings'),
                                                            'not_ready' => __('Not ready'),
                                                            default => __('Unknown'),
                                                        };
                                                    @endphp
                                                    <x-badge :tone="$readinessTone">{{ $readinessLabel }}</x-badge>
                                                </div>
                                                <p class="mt-1 text-xs text-slate-500 group-hover:text-indigo-600">
                                                    {{ trans_choice(':count blocker|:count blockers', (int) $blockers, ['count' => (int) $blockers]) }} · {{ trans_choice(':count warning|:count warnings', (int) $warnings, ['count' => (int) $warnings]) }}
                                                </p>
                                            </a>
                                        @elseif (is_array($summary) && $score === 0)
                                            <div>
                                                <div class="flex items-center gap-1.5">
                                                    <span class="text-sm font-semibold text-slate-900">0%</span>
                                                    <x-badge tone="red">{{ __('Not ready') }}</x-badge>
                                                </div>
                                                <p class="mt-1 text-xs text-slate-500">{{ (int) $blockers }} {{ __('blockers') }} · {{ (int) $warnings }} {{ __('warnings') }}</p>
                                            </div>
                                        @else
                                            <div>
                                                <span class="text-sm text-slate-400">—</span>
                                                <p class="mt-1 text-xs text-slate-400">{{ __('Not created') }}</p>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-slate-700">
                                        {{ $product->primaryCategory?->name ?? '—' }}
                                    </td>
                                    <td class="px-5 py-4 text-slate-600">
                                        <time datetime="{{ $product->updated_at?->toAtomString() }}">{{ $product->updated_at?->format('Y-m-d H:i') }}</time>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex justify-end gap-2">
                                            <a href="{{ route('catalog.products.show', $product->uuid) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-50">{{ __('Open') }}</a>
                                            @if ($canUpdate && $product->status->value !== 'archived')
                                                <a href="{{ route('catalog.products.edit', $product->uuid) }}" class="rounded-lg border border-indigo-300 px-3 py-1.5 font-semibold text-indigo-700 hover:bg-indigo-50">{{ __('Edit') }}</a>
                                            @endif
                                            @if ($canArchive && $product->status->value !== 'archived')
                                                <form method="POST" action="{{ route('catalog.products.destroy', $product->uuid) }}" onsubmit="return confirm('{{ __('Delete this product? It will be archived and kept for passport history.') }}')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="rounded-lg border border-red-300 px-3 py-1.5 font-semibold text-red-700 hover:bg-red-50">{{ __('Delete') }}</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($products->hasPages())
                    <div class="border-t border-slate-200 px-5 py-4">{{ $products->links() }}</div>
                @endif
            </section>
        @endif
    </div>
</x-app-layout>
