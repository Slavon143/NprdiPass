<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Create product') }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('Create a draft product for :company.', ['company' => $company->name]) }}</p>
        </div>
    </x-slot>

    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="mb-5 rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900">
            {{ __('A default product variant will be created automatically.') }}
        </div>
        <form method="POST" action="{{ route('catalog.products.store') }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            @csrf
            @php($product = null)
            @php($selectedCategoryUuids = [])
            @include('catalog.products._form')
            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-5">
                <a href="{{ route('catalog.products.index') }}" class="text-sm font-semibold text-slate-600 hover:text-slate-900">{{ __('Cancel') }}</a>
                <x-primary-button>{{ __('Create product') }}</x-primary-button>
            </div>
        </form>
    </div>
</x-app-layout>
