<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Products') }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ __('Products owned by :company.', ['company' => $company->name]) }}</p>
            </div>
            @if ($canCreate)
                <a href="{{ route('catalog.products.create') }}" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Create product') }}</a>
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
        $attributeCriteriaByUuid = collect($criteria->attributeFilters)->keyBy('definitionUuid');
    ?>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
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

                    <div>
                        <x-input-label for="readiness" :value="__('Activation readiness')" />
                        <select id="readiness" name="readiness" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="any" @selected($criteria->readiness === 'any')>{{ __('Any readiness') }}</option>
                            <option value="ready" @selected($criteria->readiness === 'ready')>{{ __('Ready') }}</option>
                            <option value="not_ready" @selected($criteria->readiness === 'not_ready')>{{ __('Not ready') }}</option>
                        </select>
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
            <section class="rounded-2xl border border-slate-200 bg-white px-6 py-16 text-center shadow-sm">
                <p class="font-semibold text-slate-900">{{ $hasProducts ? __('No products match these filters.') : __('No products have been created yet.') }}</p>
            </section>
        @else
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                            <tr><th class="px-5 py-3">{{ __('Product') }}</th><th class="px-5 py-3">{{ __('Status') }}</th><th class="px-5 py-3">{{ __('Primary category') }}</th><th class="px-5 py-3">{{ __('Default variant') }}</th><th class="px-5 py-3">{{ __('Updated') }}</th><th class="px-5 py-3 text-right">{{ __('Actions') }}</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($products as $product)
                                @php($statusTone = match ($product->status->value) { 'active' => 'emerald', 'archived' => 'amber', default => 'indigo' })
                                <tr @class(['bg-amber-50/40' => $product->status->value === 'archived'])>
                                    <td class="px-5 py-4"><div class="flex items-center gap-3">@if($product->primaryMedia)<img src="{{ route('catalog.media.content',$product->primaryMedia->uuid) }}" alt="{{ $product->primaryMedia->alt_text ?? '' }}" loading="lazy" decoding="async" class="h-14 w-14 rounded-lg bg-slate-100 object-contain">@else<div class="h-14 w-14 rounded-lg bg-slate-100"></div>@endif<div><p class="font-semibold text-slate-900">{{ $product->name }}</p><p class="mt-1 font-mono text-xs text-slate-500">{{ $product->slug }}</p><p class="mt-1 text-xs text-slate-500">{{ $product->brand ?: __('No brand') }} · {{ $product->manufacturer ?: __('No manufacturer') }} · {{ trans_choice(':count category|:count categories', $product->categories_count, ['count' => $product->categories_count]) }} · {{ trans_choice(':count variant|:count variants', $product->variants_count, ['count' => $product->variants_count]) }} · {{ trans_choice(':count image|:count images',$product->product_media_count,['count'=>$product->product_media_count]) }}</p></div></div></td>
                                    <td class="px-5 py-4"><x-badge :tone="$statusTone">{{ $product->status->value }}</x-badge></td>
                                    <td class="px-5 py-4 text-slate-700">{{ $product->primaryCategory?->name ?? __('Not assigned') }}</td>
                                    <td class="px-5 py-4"><p class="font-medium text-slate-800">{{ $product->defaultVariant?->name ?? __('Unavailable') }}</p><p class="mt-1 text-xs text-slate-500">{{ $product->defaultVariant?->sku ?: __('No SKU') }}</p></td>
                                    <td class="px-5 py-4 text-slate-600"><time datetime="{{ $product->updated_at?->toAtomString() }}">{{ $product->updated_at?->format('Y-m-d H:i') }}</time></td>
                                    <td class="px-5 py-4"><div class="flex justify-end gap-2"><a href="{{ route('catalog.products.show', $product->uuid) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-50">{{ __('View') }}</a>@if ($canUpdate && $product->status->value !== 'archived')<a href="{{ route('catalog.products.edit', $product->uuid) }}" class="rounded-lg border border-indigo-300 px-3 py-1.5 font-semibold text-indigo-700 hover:bg-indigo-50">{{ __('Edit') }}</a>@endif</div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($products->hasPages())<div class="border-t border-slate-200 px-5 py-4">{{ $products->links() }}</div>@endif
            </section>
        @endif
    </div>
</x-app-layout>
