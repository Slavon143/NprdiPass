<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }} / {{ __('Products') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Products') }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ __('Manage products and their digital product passports.') }}</p>
            </div>
            @if ($canCreate)
                <a href="{{ route('catalog.products.create') }}" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2" data-testid="product-create-button">{{ __('Create product') }}</a>
            @else
                <x-badge>{{ __('Read only') }}</x-badge>
            @endif
        </div>
    </x-slot>

    @php
        $selectedProductStatuses = $criteria->productStatuses;
        $selectedVariantStatuses = $criteria->variantStatuses;
        $selectedCategories = $criteria->categoryUuids;
        $selectedMissing = $criteria->missingData;
        $selectedPassportStatuses = $criteria->passportStatuses;
        $needsAttention = $criteria->needsAttention;
        $attributeCriteriaByUuid = collect($criteria->attributeFilters)->keyBy('definitionUuid');
        $productListReturnUrl = request()->fullUrl();
        $activeFilterCount = count($activeFilterChips);
    @endphp

    <div id="catalog-products-page" class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8" data-testid="products-page" x-data="{ filtersOpen: false }">
        <form id="catalog-products-filter-form" method="GET" action="{{ route('catalog.products.index') }}" data-ajax-products-form oninput="clearTimeout(window.__nordipassProductsFilterTimer); window.__nordipassProductsFilterTimer = setTimeout(() => this.requestSubmit(), 300)">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <div class="flex-1">
                    <x-input-label for="q" :value="__('Search')" class="sr-only" />
                    <x-text-input id="q" name="q" type="search" class="block w-full" :value="$criteria->query" placeholder="Search products, SKU, GTIN…" oninput="clearTimeout(window.__nordipassProductsFilterTimer); window.__nordipassProductsFilterTimer = setTimeout(() => this.form.requestSubmit(), 300)" />
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen" aria-controls="catalog-products-filter-panel" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <svg class="h-4 w-4 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 01-.659 1.591l-5.432 5.432a2.25 2.25 0 00-.659 1.591v2.927a2.25 2.25 0 01-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 00-.659-1.591L3.659 7.409A2.25 2.25 0 013 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0112 3z" />
                        </svg>
                        <span>{{ __('Filters') }}</span>
                        <span id="catalog-products-filter-count" @class(['rounded-full bg-indigo-600 px-1.5 py-0.5 text-xs font-semibold text-white', 'hidden' => $activeFilterCount === 0])>{{ $activeFilterCount }}</span>
                    </button>

                    <div class="relative" x-data="{ sortOpen: false }" @click.outside="sortOpen = false" @keydown.escape.window="sortOpen = false">
                        <button type="button" @click="sortOpen = !sortOpen" :aria-expanded="sortOpen" aria-haspopup="menu" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <svg class="h-4 w-4 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                            </svg>
                            <span>{{ __('Sort') }}</span>
                            <svg class="h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                            </svg>
                        </button>

                        <div x-cloak x-show="sortOpen" x-transition role="menu" class="absolute right-0 z-40 mt-2 w-64 rounded-xl border border-slate-200 bg-white p-3 shadow-xl">
                            <p class="px-1 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Sort by') }}</p>
                            <div class="mt-2 space-y-0.5">
                                @foreach (['relevance' => 'Relevance', 'updated' => 'Updated', 'created' => 'Created', 'name' => 'Name', 'brand' => 'Brand', 'variant_count' => 'Variant count'] as $value => $label)
                                    <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
                                        <input type="radio" name="sort" value="{{ $value }}" @checked($criteria->sort === $value) @change="sortOpen = false" class="rounded-full border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                        <span>{{ __($label) }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="mt-3 px-1 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Direction') }}</p>
                            <div class="mt-2 space-y-0.5">
                                @foreach (['desc' => 'Descending', 'asc' => 'Ascending'] as $value => $label)
                                    <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
                                        <input type="radio" name="direction" value="{{ $value }}" @checked($criteria->direction === $value) @change="sortOpen = false" class="rounded-full border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                        <span>{{ __($label) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="catalog-products-filter-panel" x-cloak x-show="filtersOpen" class="mt-4 space-y-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-slate-900">{{ __('Filters') }}</h2>
                    <a href="{{ route('catalog.products.index') }}" data-products-clear class="text-sm font-semibold text-indigo-600 hover:text-indigo-500">{{ __('Clear all') }}</a>
                </div>

                <div class="grid gap-5 lg:grid-cols-2 xl:grid-cols-3">
                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-900">{{ __('Status') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (['draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived'] as $value => $label)
                                <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <input type="checkbox" name="product_statuses[]" value="{{ $value }}" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" @checked(in_array($value, $selectedProductStatuses, true))>
                                    <span>{{ __($label) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-900">{{ __('Passport') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (['not_created' => 'Not created', 'draft' => 'Draft', 'published' => 'Published'] as $value => $label)
                                <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <input type="checkbox" name="passport_statuses[]" value="{{ $value }}" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" @checked(in_array($value, $selectedPassportStatuses, true))>
                                    <span>{{ __($label) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <label class="mt-3 inline-flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" name="needs_attention" value="1" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" @checked($needsAttention)>
                            <span class="font-medium">{{ __('Needs attention') }}</span>
                        </label>
                    </fieldset>

                    <div>
                        <x-input-label for="readiness" :value="__('readiness.label')" />
                        <select id="readiness" name="readiness" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="any" @selected($criteria->readiness === 'any')>{{ __('All') }}</option>
                            <option value="ready" @selected($criteria->readiness === 'ready')>{{ __('Ready') }}</option>
                            <option value="not_ready" @selected($criteria->readiness === 'not_ready')>{{ __('Not ready') }}</option>
                        </select>
                    </div>

                    <div>
                        <x-input-label for="category_uuids" :value="__('Category')" />
                        <select id="category_uuids" name="category_uuids[]" multiple size="5" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($categoryOptions as $categoryOption)
                                <option value="{{ $categoryOption->uuid }}" @selected(in_array($categoryOption->uuid, $selectedCategories, true))>{{ str_repeat('- ', $categoryOption->depth) }}{{ $categoryOption->name }}</option>
                            @endforeach
                        </select>
                    </div>

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

                    <fieldset class="lg:col-span-2 xl:col-span-3">
                        <legend class="text-sm font-semibold text-slate-900">{{ __('Missing data') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (['primary_category' => 'Primary category', 'default_variant' => 'Default variant', 'primary_image' => 'Primary image', 'variant_sku' => 'SKU', 'required_product_attribute' => 'Required attributes', 'required_variant_attribute' => 'Required variant attributes'] as $value => $label)
                                <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <input type="checkbox" name="missing_data[]" value="{{ $value }}" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" @checked(in_array($value, $selectedMissing, true))>
                                    <span>{{ __($label) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </div>

                <details class="border-t border-slate-200 pt-5">
                    <summary class="cursor-pointer select-none text-sm font-semibold text-slate-900 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Advanced filters') }}</summary>
                    <div class="mt-4 grid gap-5 lg:grid-cols-3">
                        <fieldset>
                            <legend class="text-sm font-semibold text-slate-900">{{ __('Variant status') }}</legend>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach (['draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived'] as $value => $label)
                                    <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                        <input type="checkbox" name="variant_statuses[]" value="{{ $value }}" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" @checked(in_array($value, $selectedVariantStatuses, true))>
                                        <span>{{ __($label) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        <div>
                            <x-input-label for="category_mode" :value="__('Category mode')" />
                            <select id="category_mode" name="category_mode" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="primary" @selected($criteria->categoryMode === 'primary')>{{ __('Primary category') }}</option>
                                <option value="any" @selected($criteria->categoryMode === 'any')>{{ __('Any assigned category') }}</option>
                            </select>
                            <label class="mt-2 inline-flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" name="include_descendants" value="1" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" @checked($criteria->includeDescendants)>
                                <span>{{ __('Include descendants') }}</span>
                            </label>
                        </div>

                        @if ($attributeFilterDefinitions->isNotEmpty())
                            @foreach ($attributeFilterDefinitions as $definition)
                                @php $attributeFilter = $attributeCriteriaByUuid->get($definition->uuid); @endphp
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
                        @endif
                    </div>
                </details>
            </div>
        </form>

        <div id="catalog-products-active-chips" class="mt-4 flex flex-wrap gap-2">
            @foreach ($activeFilterChips as $chip)
                <a href="{{ $chip['url'] }}" class="inline-flex items-center gap-2 rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-800 transition hover:bg-indigo-100">{{ $chip['label'] }} <span aria-hidden="true">×</span></a>
            @endforeach
        </div>

        <div id="catalog-products-results" class="mt-4">
            <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
                <p>{{ trans_choice(':count product|:count products', $products->total(), ['count' => $products->total()]) }}</p>
                <div class="flex items-center gap-2">
                    <label for="per_page" class="text-xs font-medium text-slate-500">{{ __('Per page') }}</label>
                    <select id="per_page" name="per_page" form="catalog-products-filter-form" onchange="this.form.requestSubmit()" class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @foreach ([25, 50, 100] as $perPage)
                            <option value="{{ $perPage }}" @selected($criteria->perPage === $perPage)>{{ $perPage }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if ($products->isEmpty())
                <section class="mt-4 rounded-2xl border border-slate-200 bg-white px-6 py-20 text-center shadow-sm">
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
                @if ($canPublish || $canArchive)
                    <form id="bulk-products-form" method="POST" action="{{ route('catalog.products.bulk-status', request()->query()) }}" class="mt-4">
                        @csrf
                        @method('PATCH')
                        <div id="bulk-products-bar" class="hidden sticky top-20 z-20 flex flex-col gap-3 rounded-2xl border border-indigo-200 bg-indigo-50/95 p-4 shadow-sm backdrop-blur sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm font-semibold text-indigo-900">
                                <span id="bulk-products-count">0</span> <span id="bulk-products-label">{{ trans_choice('product selected|products selected', 0) }}</span>
                            </p>
                            <div class="flex flex-wrap items-center gap-2">
                                <label for="bulk-operation" class="sr-only">{{ __('Change status') }}</label>
                                <select id="bulk-operation" name="operation" class="rounded-lg border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:w-56">
                                    <option value="">{{ __('Change status…') }}</option>
                                    @if ($canPublish)
                                        <option value="activate">{{ __('Activate selected') }}</option>
                                        <option value="draft">{{ __('Return selected to draft') }}</option>
                                    @endif
                                    @if ($canArchive)
                                        <option value="archive">{{ __('Archive selected') }}</option>
                                        <option value="restore">{{ __('Restore selected to draft') }}</option>
                                    @endif
                                </select>
                                <button type="submit" id="bulk-products-submit" class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-300" disabled>
                                    {{ __('Apply') }}
                                </button>
                                @if ($canArchive)
                                    <button type="button" id="bulk-products-archive" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Archive') }}</button>
                                @endif
                                <button type="button" id="bulk-products-clear" class="rounded-lg p-2.5 text-slate-500 hover:bg-white hover:text-slate-700" aria-label="{{ __('Clear selection') }}">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </form>
                @endif

                <section class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                <tr>
                                    @if ($canPublish || $canArchive)
                                        <th class="w-10 px-4 py-3">
                                            <input type="checkbox" id="bulk-products-select-all" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" aria-label="{{ __('Select all products on this page') }}">
                                        </th>
                                    @endif
                                    <th class="px-5 py-3">{{ __('Product') }}</th>
                                    <th class="px-5 py-3">{{ __('Price') }}</th>
                                    <th class="px-5 py-3">{{ __('Status') }}</th>
                                    <th class="px-5 py-3">{{ __('Passport') }}</th>
                                    <th class="px-5 py-3">{{ __('readiness.label') }}</th>
                                    <th class="px-5 py-3">{{ __('Category') }}</th>
                                    <th class="px-5 py-3">{{ __('Updated') }}</th>
                                    <th class="px-5 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($products as $product)
                                    @php
                                        $summary = $passportSummaries[$product->uuid] ?? null;
                                        $statusTone = match ($product->status->value) {
                                            'active' => 'emerald',
                                            'archived' => 'slate',
                                            default => 'slate',
                                        };
                                        $isArchived = $product->status->value === 'archived';
                                        $updatedAt = $product->updated_at;
                                        $updatedLabel = $updatedAt instanceof \Illuminate\Support\Carbon
                                            ? $updatedAt->format($updatedAt->year === now()->year ? 'j M' : 'j M Y')
                                            : null;
                                        $commerce = is_array($product->commerce) ? $product->commerce : [];
                                        $salesPrice = $commerce['sales_price'] ?? null;
                                        $currency = is_string($commerce['currency'] ?? null) ? $commerce['currency'] : 'SEK';
                                    @endphp
                                    <tr @class(['bg-amber-50/40' => $isArchived])>
                                        @if ($canPublish || $canArchive)
                                            <td class="px-4 py-4 align-top">
                                                <input type="checkbox" name="products[]" value="{{ $product->uuid }}" form="bulk-products-form" class="bulk-product-checkbox rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" aria-label="{{ __('Select :product', ['product' => $product->name]) }}">
                                            </td>
                                        @endif
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
                                                    <a href="{{ route('catalog.products.show', ['product' => $product->uuid, 'return' => $productListReturnUrl]) }}" class="block truncate font-semibold text-slate-900 hover:text-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                                        {{ $product->name }}
                                                    </a>
                                                    @if (trim((string) $product->brand) !== '')
                                                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ $product->brand }}</p>
                                                    @endif
                                                    <p class="mt-0.5 text-xs text-slate-400">
                                                        {{ trans_choice(':count variant|:count variants', $product->variants_count, ['count' => $product->variants_count]) }} · {{ trans_choice(':count image|:count images', $product->product_media_count, ['count' => $product->product_media_count]) }}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4 text-slate-700">
                                            @if (is_string($salesPrice) && $salesPrice !== '')
                                                <span class="font-semibold text-slate-900">{{ $salesPrice }}</span>
                                                <span class="text-xs text-slate-500">{{ $currency }}</span>
                                            @else
                                                <span class="text-sm text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4">
                                            <x-badge :tone="$statusTone">{{ $product->status->value }}</x-badge>
                                        </td>
                                        <td class="px-5 py-4">
                                            @php $passportStatus = $summary['passport_status'] ?? 'not_created'; @endphp
                                            @if ($passportStatus === 'not_created')
                                                <x-badge>{{ __('Not created') }}</x-badge>
                                                @if ($canManagePassports)
                                                    <form method="POST" action="{{ route('catalog.products.passport.store', $product->uuid) }}" class="mt-1.5">
                                                        @csrf
                                                        <button type="submit" class="text-xs font-medium text-indigo-600 hover:text-indigo-500">{{ __('Create passport') }}</button>
                                                    </form>
                                                @endif
                                            @elseif ($passportStatus === 'draft')
                                                <x-badge tone="indigo">{{ __('Draft') }}</x-badge>
                                            @elseif ($passportStatus === 'published')
                                                <x-badge tone="emerald">{{ __('Published') }}</x-badge>
                                            @elseif ($passportStatus === 'unpublished')
                                                <x-badge tone="amber">{{ __('Unpublished') }}</x-badge>
                                            @elseif ($passportStatus === 'archived')
                                                <x-badge>{{ __('Archived') }}</x-badge>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4">
                                            @php
                                                $score = is_array($summary) ? ($summary['score'] ?? null) : null;
                                                $blockers = is_array($summary) ? ((int) ($summary['blockers'] ?? 0)) : 0;
                                                $readinessTextTone = match ($summary['readiness_status'] ?? null) {
                                                    'ready' => 'text-emerald-700',
                                                    'ready_with_warnings' => 'text-amber-700',
                                                    'not_ready' => 'text-red-700',
                                                    default => 'text-slate-900',
                                                };
                                            @endphp
                                            @if (is_array($summary) && is_int($score))
                                                <a href="{{ route('catalog.products.passport.readiness', $product->uuid) }}" class="block hover:opacity-80">
                                                    <span class="text-sm font-semibold {{ $readinessTextTone }}">{{ $score }}%</span>
                                                    @if ($blockers > 0)
                                                        <span class="text-xs text-slate-500"> · {{ trans_choice(':count blocker|:count blockers', $blockers, ['count' => $blockers]) }}</span>
                                                    @endif
                                                    @if ($passportStatus === 'published')
                                                        <span class="mt-0.5 block text-xs text-slate-400">{{ __('Public version live') }}</span>
                                                    @endif
                                                </a>
                                            @else
                                                <span class="text-sm text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">
                                            {{ $product->primaryCategory?->name ?? '—' }}
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4 text-slate-600">
                                            <time datetime="{{ $updatedAt?->toAtomString() }}">{{ $updatedLabel }}</time>
                                        </td>
                                        <td class="px-5 py-4">
                                            <div class="relative" x-data="{ open: false }">
                                                <button type="button" x-ref="button" @click="open = !open; if (open) { const r = $refs.button.getBoundingClientRect(); const m = $refs.menu; m.style.top = (r.bottom + 4) + 'px'; m.style.left = Math.max(8, Math.min(r.right - 224, window.innerWidth - 232)) + 'px'; }" :aria-expanded="open" aria-haspopup="menu" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500" aria-label="{{ __('Actions for :product', ['product' => $product->name]) }}">
                                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                        <path d="M10.5 6a1.5 1.5 0 113 0 1.5 1.5 0 01-3 0zm0 6a1.5 1.5 0 113 0 1.5 1.5 0 01-3 0zm0 6a1.5 1.5 0 113 0 1.5 1.5 0 01-3 0z" />
                                                    </svg>
                                                </button>
                                                <div x-ref="menu" x-cloak x-show="open" @click.outside="open = false" @keydown.escape.window="open = false" role="menu" class="fixed z-50 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl">
                                                    <a href="{{ route('catalog.products.show', ['product' => $product->uuid, 'return' => $productListReturnUrl]) }}" role="menuitem" class="block px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-50">{{ __('Open') }}</a>
                                                    @if ($canUpdate && ! $isArchived)
                                                        <a href="{{ route('catalog.products.edit', ['product' => $product->uuid, 'return' => $productListReturnUrl]) }}" role="menuitem" class="block px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-50">{{ __('Edit') }}</a>
                                                    @endif
                                                    @if ($canArchive && ! $isArchived)
                                                        <form method="POST" action="{{ route('catalog.products.destroy', $product->uuid) }}" data-confirm-title="{{ __('Archive product') }}" data-confirm-message="{{ __('Archive this product? It will be hidden from normal catalog work, but passport history will be kept.') }}" data-confirm-action="{{ __('Archive') }}">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" role="menuitem" class="block w-full px-4 py-2 text-left text-sm text-red-700 transition hover:bg-red-50">{{ __('Archive') }}</button>
                                                        </form>
                                                    @endif
                                                    @if ($canArchive && $isArchived)
                                                        <form method="POST" action="{{ route('catalog.products.restore', $product->uuid) }}">
                                                            @csrf
                                                            <button type="submit" role="menuitem" class="block w-full px-4 py-2 text-left text-sm text-slate-700 transition hover:bg-slate-50">{{ __('Restore') }}</button>
                                                        </form>
                                                    @endif
                                                </div>
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
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var ajaxController = null;
                var currentModalResolve = null;

                function page() {
                    return document.getElementById('catalog-products-page');
                }

                function filterForm() {
                    return document.getElementById('catalog-products-filter-form');
                }

                function results() {
                    return document.getElementById('catalog-products-results');
                }

                function activeChips() {
                    return document.getElementById('catalog-products-active-chips');
                }

                function filterCountBadge() {
                    return document.getElementById('catalog-products-filter-count');
                }

                function cleanFormData(form) {
                    var data = new FormData(form);
                    var params = new URLSearchParams();

                    data.forEach(function (value, key) {
                        if (typeof value === 'string' && value.trim() === '') {
                            return;
                        }

                        params.append(key, value);
                    });

                    return params;
                }

                function urlFromForm(form) {
                    var params = cleanFormData(form);
                    var url = new URL(form.action, window.location.origin);
                    url.search = params.toString();

                    return url;
                }

                function setLoading(isLoading) {
                    var container = results();

                    if (!container) {
                        return;
                    }

                    container.classList.toggle('opacity-60', isLoading);
                    container.setAttribute('aria-busy', isLoading ? 'true' : 'false');
                }

                function syncFilterCount() {
                    var badge = filterCountBadge();

                    if (!badge) {
                        return;
                    }

                    var chips = activeChips();
                    var count = chips ? chips.querySelectorAll('a').length : 0;

                    badge.textContent = count;
                    badge.classList.toggle('hidden', count === 0);
                }

                function fetchProducts(url, pushUrl) {
                    if (ajaxController) {
                        ajaxController.abort();
                    }

                    ajaxController = new AbortController();
                    setLoading(true);

                    return fetch(url.toString(), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'text/html',
                        },
                        signal: ajaxController.signal,
                    })
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('Request failed: ' + response.status);
                            }

                            return response.text();
                        })
                        .then(function (html) {
                            var parser = new DOMParser();
                            var documentFragment = parser.parseFromString(html, 'text/html');
                            var freshResults = documentFragment.getElementById('catalog-products-results');
                            var currentResults = results();
                            var freshChips = documentFragment.getElementById('catalog-products-active-chips');
                            var currentChips = activeChips();

                            if (!freshResults || !currentResults) {
                                window.location.href = url.toString();
                                return;
                            }

                            currentResults.innerHTML = freshResults.innerHTML;

                            if (freshChips && currentChips) {
                                currentChips.innerHTML = freshChips.innerHTML;
                            }

                            syncFilterCount();

                            if (pushUrl) {
                                window.history.pushState({ catalogProductsUrl: url.toString() }, '', url.toString());
                            }

                            bindResults();
                        })
                        .catch(function (error) {
                            if (error.name === 'AbortError') {
                                return;
                            }

                            window.location.href = url.toString();
                        })
                        .finally(function () {
                            setLoading(false);
                        });
                }

                function ensureConfirmModal() {
                    var existing = document.getElementById('catalog-products-confirm-modal');

                    if (existing) {
                        return existing;
                    }

                    var modal = document.createElement('div');
                    modal.id = 'catalog-products-confirm-modal';
                    modal.className = 'fixed inset-0 z-[60] hidden items-center justify-center bg-slate-950/50 px-4 py-6';
                    modal.innerHTML = [
                        '<div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl ring-1 ring-slate-900/10">',
                        '  <div class="flex items-start gap-4">',
                        '    <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">',
                        '      <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008v.008H12v-.008Zm9-4.5a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>',
                        '    </div>',
                        '    <div class="min-w-0 flex-1">',
                        '      <h2 class="text-lg font-semibold text-slate-900" data-confirm-title></h2>',
                        '      <p class="mt-2 text-sm leading-6 text-slate-600" data-confirm-message></p>',
                        '    </div>',
                        '  </div>',
                        '  <div class="mt-6 flex justify-end gap-2">',
                        '    <button type="button" data-confirm-cancel class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Cancel') }}</button>',
                        '    <button type="button" data-confirm-ok class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500">{{ __('Archive') }}</button>',
                        '  </div>',
                        '</div>'
                    ].join('');

                    document.body.appendChild(modal);

                    modal.addEventListener('click', function (event) {
                        if (event.target === modal || event.target.closest('[data-confirm-cancel]')) {
                            closeConfirmModal(false);
                        }

                        if (event.target.closest('[data-confirm-ok]')) {
                            closeConfirmModal(true);
                        }
                    });

                    document.addEventListener('keydown', function (event) {
                        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                            closeConfirmModal(false);
                        }
                    });

                    return modal;
                }

                function closeConfirmModal(result) {
                    var modal = ensureConfirmModal();
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');

                    if (currentModalResolve) {
                        currentModalResolve(result);
                        currentModalResolve = null;
                    }
                }

                function confirmAction(options) {
                    var modal = ensureConfirmModal();
                    modal.querySelector('[data-confirm-title]').textContent = options.title || '{{ __('Confirm action') }}';
                    modal.querySelector('[data-confirm-message]').textContent = options.message || '{{ __('Are you sure?') }}';
                    modal.querySelector('[data-confirm-ok]').textContent = options.action || '{{ __('Continue') }}';
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                    modal.querySelector('[data-confirm-cancel]').focus();

                    return new Promise(function (resolve) {
                        currentModalResolve = resolve;
                    });
                }

                function bindBulkActions() {
                    var form = document.getElementById('bulk-products-form');

                    if (!form || form.dataset.bulkActionsBound === '1') {
                        return;
                    }

                    form.dataset.bulkActionsBound = '1';

                    var bar = document.getElementById('bulk-products-bar');
                    var selectAll = document.getElementById('bulk-products-select-all');
                    var submit = document.getElementById('bulk-products-submit');
                    var operation = document.getElementById('bulk-operation');
                    var countEl = document.getElementById('bulk-products-count');
                    var labelEl = document.getElementById('bulk-products-label');
                    var clearBtn = document.getElementById('bulk-products-clear');
                    var archiveBtn = document.getElementById('bulk-products-archive');
                    var checkboxes = Array.prototype.slice.call(document.querySelectorAll('.bulk-product-checkbox'));

                    function selectedCount() {
                        return checkboxes.filter(function (checkbox) {
                            return checkbox.checked;
                        }).length;
                    }

                    function syncBulkState() {
                        var count = selectedCount();

                        if (bar) {
                            bar.classList.toggle('hidden', count === 0);
                        }

                        if (countEl) {
                            countEl.textContent = count;
                        }

                        if (labelEl) {
                            labelEl.textContent = count === 1 ? '{{ __('product selected') }}' : '{{ __('products selected') }}';
                        }

                        if (submit) {
                            submit.disabled = count === 0 || !operation || !operation.value;
                        }

                        if (selectAll) {
                            selectAll.checked = count === checkboxes.length;
                            selectAll.indeterminate = count > 0 && count < checkboxes.length;
                        }
                    }

                    if (selectAll) {
                        selectAll.addEventListener('change', function () {
                            checkboxes.forEach(function (checkbox) {
                                checkbox.checked = selectAll.checked;
                            });
                            syncBulkState();
                        });
                    }

                    checkboxes.forEach(function (checkbox) {
                        checkbox.addEventListener('change', syncBulkState);
                    });

                    if (operation) {
                        operation.addEventListener('change', syncBulkState);
                    }

                    if (clearBtn) {
                        clearBtn.addEventListener('click', function () {
                            checkboxes.forEach(function (checkbox) {
                                checkbox.checked = false;
                            });
                            syncBulkState();
                        });
                    }

                    if (archiveBtn && operation) {
                        archiveBtn.addEventListener('click', function () {
                            operation.value = 'archive';
                            syncBulkState();
                            form.requestSubmit();
                        });
                    }

                    form.addEventListener('submit', function (event) {
                        var count = selectedCount();

                        if (count === 0 || !operation || !operation.value) {
                            event.preventDefault();
                            return;
                        }

                        if (operation.value !== 'archive' || form.dataset.confirmed === '1') {
                            return;
                        }

                        event.preventDefault();

                        confirmAction({
                            title: '{{ __('Archive selected products') }}',
                            message: '{{ __('Archive selected products? They will be hidden from the working catalog but kept for history.') }}',
                            action: '{{ __('Archive selected') }}',
                        }).then(function (confirmed) {
                            if (!confirmed) {
                                return;
                            }

                            form.dataset.confirmed = '1';
                            form.requestSubmit();
                        });
                    });

                    syncBulkState();
                }

                function bindConfirmForms() {
                    if (document.documentElement.dataset.confirmFormsBound === '1') {
                        return;
                    }

                    document.documentElement.dataset.confirmFormsBound = '1';

                    document.addEventListener('submit', function (event) {
                        var form = event.target instanceof HTMLElement ? event.target.closest('form[data-confirm-message]') : null;

                        if (!form || form.dataset.confirmed === '1') {
                            return;
                        }

                        event.preventDefault();

                        confirmAction({
                            title: form.dataset.confirmTitle,
                            message: form.dataset.confirmMessage,
                            action: form.dataset.confirmAction,
                        }).then(function (confirmed) {
                            if (!confirmed) {
                                return;
                            }

                            form.dataset.confirmed = '1';
                            form.requestSubmit();
                        });
                    }, true);
                }

                function bindAjaxFilters() {
                    var form = filterForm();

                    if (!form || form.dataset.ajaxFiltersBound === '1') {
                        return;
                    }

                    form.dataset.ajaxFiltersBound = '1';

                    form.addEventListener('submit', function (event) {
                        event.preventDefault();
                        fetchProducts(urlFromForm(form), true);
                    });
                }

                function bindAjaxLinks() {
                    var container = page();

                    if (!container || container.dataset.ajaxLinksBound === '1') {
                        return;
                    }

                    container.dataset.ajaxLinksBound = '1';

                    container.addEventListener('click', function (event) {
                        var link = event.target.closest('a[href]');

                        if (!link) {
                            return;
                        }

                        var indexUrl = new URL('{{ route('catalog.products.index') }}', window.location.origin);
                        var targetUrl = new URL(link.href, window.location.origin);

                        if (targetUrl.origin !== window.location.origin || targetUrl.pathname !== indexUrl.pathname) {
                            return;
                        }

                        event.preventDefault();

                        if (link.matches('[data-products-clear]')) {
                            var form = filterForm();
                            if (form) {
                                form.reset();
                                Array.prototype.slice.call(form.querySelectorAll('select[multiple] option')).forEach(function (option) {
                                    option.selected = false;
                                });
                            }
                        }

                        fetchProducts(targetUrl, true);
                    });
                }

                function bindResults() {
                    bindBulkActions();
                    bindConfirmForms();
                }

                function bindCatalogProductsPage() {
                    bindAjaxFilters();
                    bindAjaxLinks();
                    bindResults();
                    syncFilterCount();
                }

                window.addEventListener('popstate', function () {
                    fetchProducts(new URL(window.location.href), false);
                });

                bindCatalogProductsPage();
            });
        </script>
    @endpush
</x-app-layout>
