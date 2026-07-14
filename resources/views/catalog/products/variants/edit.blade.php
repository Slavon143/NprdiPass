<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog · Product variants') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Edit variant') }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $product->name }} · {{ $variant->displayName() }}</p>
            </div>
            @if ($variant->isDefaultFor($product))<x-badge tone="indigo">{{ __('Default') }}</x-badge>@endif
        </div>
    </x-slot>

    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('catalog.products.variants.update', [$product->uuid, $variant->uuid]) }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            @csrf
            @method('PATCH')
            @include('catalog.products.variants._form')
            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-5">
                <a href="{{ route('catalog.products.variants.show', [$product->uuid, $variant->uuid]) }}" class="text-sm font-semibold text-slate-600 hover:text-slate-900">{{ __('Cancel') }}</a>
                <x-primary-button>{{ __('Save variant') }}</x-primary-button>
            </div>
        </form>
    </div>
</x-app-layout>
