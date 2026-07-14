<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog · Product variants') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ $product->name }}</h1>
                <p class="mt-1 font-mono text-xs text-slate-500">{{ $product->slug }}</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('catalog.products.show', $product->uuid) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('View product') }}</a>
                @if ($canCreate)<a href="{{ route('catalog.products.variants.create', $product->uuid) }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ __('Add variant') }}</a>@endif
            </div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <x-input-error :messages="$errors->get('variant')" class="mb-4" />
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                        <tr><th class="px-5 py-3">{{ __('Variant') }}</th><th class="px-5 py-3">{{ __('Identifiers') }}</th><th class="px-5 py-3">{{ __('Status') }}</th><th class="px-5 py-3">{{ __('Order') }}</th><th class="px-5 py-3">{{ __('Updated') }}</th><th class="px-5 py-3 text-right">{{ __('Actions') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($variants as $variant)
                            @php($isDefault = $variant->isDefaultFor($product))
                            <tr>
                                <td class="px-5 py-4"><div class="flex items-center gap-2"><span class="font-semibold text-slate-900">{{ $variant->displayName() }}</span>@if ($isDefault)<x-badge tone="indigo">{{ __('Default') }}</x-badge>@endif</div><p class="mt-1 font-mono text-xs text-slate-400">{{ $variant->uuid }}</p></td>
                                <td class="px-5 py-4 text-slate-700"><p><span class="text-slate-400">SKU:</span> {{ $variant->sku ?: '—' }}</p><p><span class="text-slate-400">GTIN:</span> {{ $variant->gtin ?: '—' }}</p><p><span class="text-slate-400">MPN:</span> {{ $variant->mpn ?: '—' }}</p></td>
                                <td class="px-5 py-4"><x-badge :tone="$variant->status->value === 'active' ? 'emerald' : ($variant->status->value === 'archived' ? 'amber' : 'slate')">{{ $variant->status->value }}</x-badge></td>
                                <td class="px-5 py-4 text-slate-600">{{ $variant->sort_order }}</td>
                                <td class="px-5 py-4 text-slate-600">{{ $variant->updated_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-5 py-4"><div class="flex justify-end gap-2"><a href="{{ route('catalog.products.variants.show', [$product->uuid, $variant->uuid]) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-50">{{ __('View') }}</a>@if ($canUpdate)<a href="{{ route('catalog.products.variants.edit', [$product->uuid, $variant->uuid]) }}" class="rounded-lg border border-indigo-300 px-3 py-1.5 font-semibold text-indigo-700 hover:bg-indigo-50">{{ __('Edit') }}</a>@if (! $isDefault)<form method="POST" action="{{ route('catalog.products.variants.set-default', [$product->uuid, $variant->uuid]) }}">@csrf<button type="submit" class="rounded-lg border border-emerald-300 px-3 py-1.5 font-semibold text-emerald-700 hover:bg-emerald-50">{{ __('Set as default') }}</button></form>@endif @endif</div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($variants->hasPages())<div class="border-t border-slate-200 px-5 py-4">{{ $variants->links() }}</div>@endif
        </section>
    </div>
</x-app-layout>
