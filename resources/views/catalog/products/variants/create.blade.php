<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog · Product variants') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Add variant') }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $product->name }} · {{ $product->slug }}</p>
            </div>
            <a href="{{ route('catalog.products.variants.index', $product->uuid) }}" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">{{ __('All variants') }}</a>
        </div>
    </x-slot>

    <div class="mx-auto grid max-w-5xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_19rem] lg:px-8">
        <form method="POST" action="{{ route('catalog.products.variants.store', $product->uuid) }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            @csrf
            @include('catalog.products.variants._form', ['variant' => null])
            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-5">
                <a href="{{ route('catalog.products.variants.index', $product->uuid) }}" class="text-sm font-semibold text-slate-600 hover:text-slate-900">{{ __('Cancel') }}</a>
                <x-primary-button>{{ __('Create variant') }}</x-primary-button>
            </div>
        </form>

        <aside class="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold text-slate-900">{{ __('Product context') }}</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <div><dt class="text-slate-500">{{ __('Product') }}</dt><dd class="font-semibold text-slate-900">{{ $product->name }}</dd></div>
                <div><dt class="text-slate-500">{{ __('Current default') }}</dt><dd class="font-semibold text-slate-900">{{ $product->defaultVariant?->displayName() ?? '—' }}</dd></div>
            </dl>
            <p class="mt-4 text-xs leading-5 text-slate-500">{{ __('A new variant starts as draft and does not replace the current default variant.') }}</p>
        </aside>
    </div>
</x-app-layout>
