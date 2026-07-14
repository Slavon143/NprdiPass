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

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <form method="GET" action="{{ route('catalog.products.index') }}" class="grid gap-4 sm:grid-cols-[12rem_minmax(0,1fr)_auto] sm:items-end">
                <div>
                    <x-input-label for="status" :value="__('Status')" />
                    <select id="status" name="status" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="all" @selected($filters['status'] === 'all')>{{ __('All statuses') }}</option>
                        <option value="draft" @selected($filters['status'] === 'draft')>{{ __('Draft') }}</option>
                        <option value="active" @selected($filters['status'] === 'active')>{{ __('Active') }}</option>
                        <option value="archived" @selected($filters['status'] === 'archived')>{{ __('Archived') }}</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="primary_category" :value="__('Primary category')" />
                    <select id="primary_category" name="primary_category" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">{{ __('Any primary category') }}</option>
                        @foreach ($categoryOptions as $categoryOption)
                            <option value="{{ $categoryOption->uuid }}" @selected($filters['primary_category'] === $categoryOption->uuid)>{{ str_repeat('— ', $categoryOption->depth) }}{{ $categoryOption->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500">{{ __('Apply') }}</button>
                    <a href="{{ route('catalog.products.index') }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Clear') }}</a>
                </div>
            </form>
        </section>

        @if ($products->isEmpty())
            <section class="rounded-2xl border border-slate-200 bg-white px-6 py-16 text-center shadow-sm">
                <p class="font-semibold text-slate-900">{{ __('No products match these filters.') }}</p>
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
                                <tr>
                                    <td class="px-5 py-4"><p class="font-semibold text-slate-900">{{ $product->name }}</p><p class="mt-1 font-mono text-xs text-slate-500">{{ $product->slug }}</p><p class="mt-1 text-xs text-slate-500">{{ $product->brand ?: __('No brand') }} · {{ trans_choice(':count category|:count categories', $product->categories_count, ['count' => $product->categories_count]) }}</p></td>
                                    <td class="px-5 py-4"><x-badge :tone="$statusTone">{{ $product->status->value }}</x-badge></td>
                                    <td class="px-5 py-4 text-slate-700">{{ $product->primaryCategory?->name ?? __('Not assigned') }}</td>
                                    <td class="px-5 py-4"><p class="font-medium text-slate-800">{{ $product->defaultVariant?->name ?? __('Unavailable') }}</p><p class="mt-1 text-xs text-slate-500">{{ $product->defaultVariant?->sku ?: __('No SKU') }}</p></td>
                                    <td class="px-5 py-4 text-slate-600"><time datetime="{{ $product->updated_at?->toAtomString() }}">{{ $product->updated_at?->format('Y-m-d H:i') }}</time></td>
                                    <td class="px-5 py-4"><div class="flex justify-end gap-2"><a href="{{ route('catalog.products.show', $product->uuid) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-50">{{ __('View') }}</a>@if ($canUpdate)<a href="{{ route('catalog.products.edit', $product->uuid) }}" class="rounded-lg border border-indigo-300 px-3 py-1.5 font-semibold text-indigo-700 hover:bg-indigo-50">{{ __('Edit') }}</a>@endif</div></td>
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
