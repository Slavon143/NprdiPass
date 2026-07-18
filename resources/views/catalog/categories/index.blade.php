<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Categories') }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ __('Manage the category hierarchy for :company.', ['company' => $company->name]) }}</p>
            </div>
            @if ($canManage)
                <a href="{{ route('catalog.categories.create') }}" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2" data-testid="category-create-button">{{ __('Create category') }}</a>
            @else
                <x-badge>{{ __('Read only') }}</x-badge>
            @endif
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8" data-testid="categories-page">
        <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <form method="GET" action="{{ route('catalog.categories.index') }}" class="grid gap-4 lg:grid-cols-[1fr_12rem_16rem_auto] lg:items-end">
                <div>
                    <x-input-label for="search" :value="__('Search')" />
                    <x-text-input id="search" name="search" type="search" class="mt-1 block w-full" :value="$filters['search']" placeholder="Name or slug" />
                </div>
                <div>
                    <x-input-label for="status" :value="__('Status')" />
                    <select id="status" name="status" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="all" @selected($filters['status'] === 'all')>{{ __('All statuses') }}</option>
                        <option value="active" @selected($filters['status'] === 'active')>{{ __('Active') }}</option>
                        <option value="archived" @selected($filters['status'] === 'archived')>{{ __('Archived') }}</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="parent" :value="__('Parent')" />
                    <select id="parent" name="parent" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">{{ __('Any parent') }}</option>
                        <option value="root" @selected($filters['parent'] === 'root')>{{ __('Root only') }}</option>
                        @foreach ($parentOptions as $parentOption)
                            <option value="{{ $parentOption->uuid }}" @selected($filters['parent'] === $parentOption->uuid)>{{ str_repeat('— ', $parentOption->depth) }}{{ $parentOption->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500">{{ __('Apply') }}</button>
                    <a href="{{ route('catalog.categories.index') }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Clear') }}</a>
                </div>
            </form>
        </section>

        <x-input-error :messages="$errors->all()" class="mb-5 rounded-lg bg-red-50 p-4" />

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if ($categories->isEmpty())
                <div class="px-6 py-16 text-center">
                    <p class="font-semibold text-slate-900">{{ __('No categories match these filters.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-5 py-3">{{ __('Category') }}</th>
                                <th class="px-5 py-3">{{ __('Parent') }}</th>
                                <th class="px-5 py-3">{{ __('Level') }}</th>
                                <th class="px-5 py-3">{{ __('Sort order') }}</th>
                                <th class="px-5 py-3">{{ __('Status') }}</th>
                                <th class="px-5 py-3 text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($categories as $category)
                                @php
                                    $depth = (int) $category->depth;
                                    $indentRem = min($depth, 6) * 1.25;
                                @endphp
                                <tr>
                                    <td class="px-5 py-4">
                                        <div style="padding-left: {{ $indentRem }}rem">
                                            <p class="flex items-center gap-2 font-semibold text-slate-900">
                                                @if($depth > 0)
                                                    <span class="text-slate-400" aria-hidden="true">↳</span>
                                                @endif
                                                <span>{{ $category->name }}</span>
                                            </p>
                                            <p class="mt-1 font-mono text-xs text-slate-500">{{ $category->slug }}</p>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        @if($category->parent)
                                            <p class="font-medium text-slate-700">{{ $category->parent->name }}</p>
                                            <p class="mt-1 text-xs text-slate-500">{{ __('Nested under this parent') }}</p>
                                        @else
                                            <x-badge tone="slate">{{ __('Root category') }}</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4">
                                        @if($depth === 0)
                                            <x-badge tone="indigo">{{ __('Top level') }}</x-badge>
                                        @else
                                            <x-badge tone="slate">{{ __('Level :level', ['level' => $depth]) }}</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="font-mono text-sm font-semibold text-slate-700">{{ $category->sort_order }}</span>
                                        <p class="mt-1 text-xs text-slate-500">{{ __('Lower numbers appear first under the same parent.') }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <x-badge :tone="$category->status->value === 'active' ? 'emerald' : 'amber'">{{ ucfirst($category->status->value) }}</x-badge>
                                        @if ($category->children_count > 0)
                                            <p class="mt-1 text-xs text-amber-700">{{ trans_choice(':count child category|:count child categories', $category->children_count, ['count' => $category->children_count]) }}</p>
                                        @endif
                                        @if ($category->primary_products_count > 0)
                                            <p class="mt-1 text-xs text-amber-700">
                                                {{ trans_choice('Primary for :count product|Primary for :count products', $category->primary_products_count, ['count' => $category->primary_products_count]) }}
                                                @if ($category->active_primary_products_count !== $category->primary_products_count)
                                                    <span class="text-slate-500">({{ trans_choice(':count active|:count active', $category->active_primary_products_count, ['count' => $category->active_primary_products_count]) }})</span>
                                                @endif
                                            </p>
                                        @endif
                                        @if ($category->assigned_products_count > $category->primary_products_count)
                                            <p class="mt-1 text-xs text-amber-700">
                                                {{ trans_choice('Also assigned to :count product|Also assigned to :count products', $category->assigned_products_count - $category->primary_products_count, ['count' => $category->assigned_products_count - $category->primary_products_count]) }}
                                            </p>
                                        @endif
                                        @if ($category->primary_products_count > 0 || $category->assigned_products_count > 0)
                                            @php($categoryProductLink = $categoryProductLinks[$category->uuid] ?? null)
                                            <a href="{{ $categoryProductLink['url'] ?? route('catalog.products.index', ['category_uuids' => [$category->uuid], 'category_mode' => $category->primary_products_count > 0 ? 'primary' : 'any']) }}" class="mt-1 inline-block text-xs font-semibold text-indigo-700 hover:text-indigo-900">{{ $categoryProductLink['label'] ?? __('View products') }}</a>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4">
                                        @if ($canManage)
                                            <div class="flex items-center justify-end gap-2">
                                                @foreach (['up' => __('Move up'), 'down' => __('Move down')] as $direction => $label)
                                                    @if ($reorderPayloads[$category->uuid][$direction] !== null)
                                                        <form method="POST" action="{{ route('catalog.categories.reorder') }}">
                                                            @csrf
                                                            @method('PATCH')
                                                            <input type="hidden" name="parent_uuid" value="{{ $reorderPayloads[$category->uuid]['parent_uuid'] }}">
                                                            @foreach ($reorderPayloads[$category->uuid][$direction] as $orderedUuid)<input type="hidden" name="ordered_uuids[]" value="{{ $orderedUuid }}">@endforeach
                                                            <button type="submit" class="rounded border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50" title="{{ __('Changes order only among categories with the same parent.') }}" aria-label="{{ $label }}">
                                                                {{ $direction === 'up' ? '↑' : '↓' }}
                                                                <span class="sr-only">{{ $label }}</span>
                                                            </button>
                                                        </form>
                                                    @endif
                                                @endforeach
                                                <a href="{{ route('catalog.categories.edit', $category->uuid) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-50">{{ __('Edit') }}</a>
                                                <form method="POST" action="{{ route('catalog.categories.destroy', $category->uuid) }}" onsubmit="return confirm('{{ __('Delete this category? This is only allowed when it has no child categories or product assignments.') }}')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="rounded-lg border border-red-300 px-3 py-1.5 font-semibold text-red-700 hover:bg-red-50">{{ __('Delete') }}</button>
                                                </form>
                                            </div>
                                            <p class="mt-2 text-right text-xs text-slate-400">{{ __('Arrows change sibling order.') }}</p>
                                        @else
                                            <span class="block text-right text-xs text-slate-400">{{ __('View only') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($categories->hasPages())<div class="border-t border-slate-200 px-5 py-4">{{ $categories->links() }}</div>@endif
            @endif
        </section>
    </div>
</x-app-layout>
