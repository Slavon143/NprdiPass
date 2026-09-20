<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog · Attributes') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ $definition->name }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ __('Products and variants currently using this attribute.') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('catalog.attributes.show', $definition->uuid) }}" class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Attribute details') }}</a>
                <a href="{{ route('catalog.attributes.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('All attributes') }}</a>
            </div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8" data-testid="attribute-usage-page">
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm" data-testid="usage-products">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h2 class="font-semibold text-slate-900">{{ __('Products') }}</h2>
                    <span class="text-sm text-slate-500">{{ trans_choice(':count product|:count products', $products->total(), ['count' => $products->total()]) }}</span>
                </div>
                @if ($products->isEmpty())
                    <p class="px-5 py-12 text-center text-sm text-slate-500">{{ __('No products use this attribute yet.') }}</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($products as $product)
                            @php($value = $product->attributeValues->first())
                            <li class="flex items-center justify-between gap-4 px-5 py-3.5">
                                <a href="{{ route('catalog.products.show', $product->uuid) }}" class="truncate font-medium text-slate-900 hover:text-indigo-700">{{ $product->name }}</a>
                                @if ($value !== null)
                                    <span class="shrink-0 rounded-md bg-slate-100 px-2 py-1 font-mono text-xs text-slate-700">{{ $formatter->format($value) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($products->hasPages())
                        <div class="border-t border-slate-200 px-5 py-4">{{ $products->links() }}</div>
                    @endif
                @endif
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm" data-testid="usage-variants">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h2 class="font-semibold text-slate-900">{{ __('Variants') }}</h2>
                    <span class="text-sm text-slate-500">{{ trans_choice(':count variant|:count variants', $variants->total(), ['count' => $variants->total()]) }}</span>
                </div>
                @if ($variants->isEmpty())
                    <p class="px-5 py-12 text-center text-sm text-slate-500">{{ __('No variants use this attribute yet.') }}</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($variants as $variant)
                            @php($value = $variant->attributeValues->first())
                            <li class="flex items-center justify-between gap-4 px-5 py-3.5">
                                <div class="min-w-0">
                                    <a href="{{ route('catalog.products.variants.show', [$variant->product->uuid, $variant->uuid]) }}" class="block truncate font-medium text-slate-900 hover:text-indigo-700">{{ $variant->name }}</a>
                                    <p class="truncate text-xs text-slate-500">{{ $variant->product->name }}</p>
                                </div>
                                @if ($value !== null)
                                    <span class="shrink-0 rounded-md bg-slate-100 px-2 py-1 font-mono text-xs text-slate-700">{{ $formatter->format($value) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($variants->hasPages())
                        <div class="border-t border-slate-200 px-5 py-4">{{ $variants->links() }}</div>
                    @endif
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
