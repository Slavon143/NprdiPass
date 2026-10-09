<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-slate-900">{{ __('Publication readiness') }}</h1>
    </x-slot>
    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6">
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-lg font-semibold">{{ __('No stored readiness evaluation') }}</h2>
            <p class="mt-2 text-sm text-slate-600">{{ __('Your product data and publication history are retained. An active subscription and a managed product are required to calculate a new evaluation.') }}</p>
            <a href="{{ route('catalog.products.show', $product->uuid) }}" class="mt-4 inline-block font-medium text-indigo-600">{{ __('Back to product') }}</a>
        </div>
    </div>
</x-app-layout>
