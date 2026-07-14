<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Edit product') }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $product->name }}</p>
            </div>
            @php($statusTone = match ($product->status->value) { 'active' => 'emerald', 'archived' => 'amber', default => 'indigo' })
            <x-badge :tone="$statusTone">{{ $product->status->value }}</x-badge>
        </div>
    </x-slot>

    <div class="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_21rem] lg:px-8">
        <form method="POST" action="{{ route('catalog.products.update', $product->uuid) }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            @csrf
            @method('PATCH')
            @include('catalog.products._form')
            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-5">
                <a href="{{ route('catalog.products.show', $product->uuid) }}" class="text-sm font-semibold text-slate-600 hover:text-slate-900">{{ __('Cancel') }}</a>
                <x-primary-button>{{ __('Save product') }}</x-primary-button>
            </div>
        </form>

        <aside class="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold text-slate-900">{{ __('Default variant') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('Identifiers and the default selection are managed on the variants screen.') }}</p>
            @if ($product->defaultVariant)
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-slate-500">{{ __('Variant UUID') }}</dt><dd class="mt-1 break-all font-mono text-xs text-slate-800">{{ $product->defaultVariant->uuid }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Name') }}</dt><dd class="font-semibold text-slate-900">{{ $product->defaultVariant->name }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Status') }}</dt><dd class="capitalize text-slate-900">{{ $product->defaultVariant->status->value }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('SKU') }}</dt><dd class="text-slate-900">{{ $product->defaultVariant->sku ?: __('Not set') }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('GTIN') }}</dt><dd class="text-slate-900">{{ $product->defaultVariant->gtin ?: __('Not set') }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('MPN') }}</dt><dd class="text-slate-900">{{ $product->defaultVariant->mpn ?: __('Not set') }}</dd></div>
                </dl>
            @else
                <p class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ __('Default variant is unavailable.') }}</p>
            @endif
            <a href="{{ route('catalog.products.variants.index', $product->uuid) }}" class="mt-5 inline-flex rounded-lg border border-indigo-300 px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">{{ __('Manage variants') }}</a>
        </aside>
    </div>
</x-app-layout>
