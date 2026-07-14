<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div><p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }}</p><h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ $product->name }}</h1><p class="mt-1 font-mono text-xs text-slate-500">{{ $product->slug }}</p></div>
            <div class="flex items-center gap-3">
                @php($statusTone = match ($product->status->value) { 'active' => 'emerald', 'archived' => 'amber', default => 'indigo' })
                <x-badge :tone="$statusTone">{{ $product->status->value }}</x-badge>
                @if ($canUpdate)<a href="{{ route('catalog.products.edit', $product->uuid) }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ __('Edit product') }}</a>@endif
            </div>
        </div>
    </x-slot>

    <div class="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:px-8">
        <div class="space-y-6">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Product details') }}</h2>
                <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div><dt class="text-sm text-slate-500">{{ __('Brand') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $product->brand ?: __('Not set') }}</dd></div>
                    <div><dt class="text-sm text-slate-500">{{ __('Manufacturer') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $product->manufacturer ?: __('Not set') }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-sm text-slate-500">{{ __('Short description') }}</dt><dd class="mt-1 whitespace-pre-line text-slate-800">{{ $product->short_description ?: __('Not set') }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-sm text-slate-500">{{ __('Description') }}</dt><dd class="mt-1 whitespace-pre-line text-slate-800">{{ $product->description ?: __('Not set') }}</dd></div>
                </dl>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Categories') }}</h2>
                <p class="mt-2 text-sm text-slate-600">{{ __('Primary:') }} <span class="font-semibold text-slate-900">{{ $product->primaryCategory?->name ?? __('Not assigned') }}</span></p>
                <div class="mt-4 flex flex-wrap gap-2">@forelse ($product->categories as $category)<x-badge :tone="$product->primaryCategory?->is($category) ? 'indigo' : 'slate'">{{ $category->name }}</x-badge>@empty<span class="text-sm text-slate-500">{{ __('No categories assigned.') }}</span>@endforelse</div>
            </section>
        </div>

        <aside class="space-y-6">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-semibold text-slate-900">{{ __('Default variant') }}</h2>
                @if ($product->defaultVariant)
                    <dl class="mt-4 space-y-3 text-sm">
                        <div><dt class="text-slate-500">{{ __('Variant UUID') }}</dt><dd class="mt-1 break-all font-mono text-xs">{{ $product->defaultVariant->uuid }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('Name') }}</dt><dd class="font-semibold">{{ $product->defaultVariant->name }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('Status') }}</dt><dd class="capitalize">{{ $product->defaultVariant->status->value }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('SKU') }}</dt><dd>{{ $product->defaultVariant->sku ?: __('Not set') }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('GTIN') }}</dt><dd>{{ $product->defaultVariant->gtin ?: __('Not set') }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('MPN') }}</dt><dd>{{ $product->defaultVariant->mpn ?: __('Not set') }}</dd></div>
                    </dl>
                @endif
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-semibold text-slate-900">{{ __('Record') }}</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-slate-500">{{ __('Created by') }}</dt><dd>{{ $product->createdBy?->name ?? __('Unknown') }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Updated by') }}</dt><dd>{{ $product->updatedBy?->name ?? __('Unknown') }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Created') }}</dt><dd>{{ $product->created_at?->format('Y-m-d H:i:s') }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Updated') }}</dt><dd>{{ $product->updated_at?->format('Y-m-d H:i:s') }}</dd></div>
                </dl>
            </section>
        </aside>
    </div>
</x-app-layout>
