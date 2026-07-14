<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Create category') }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('Add a category to :company.', ['company' => $company->name]) }}</p>
        </div>
    </x-slot>

    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('catalog.categories.store') }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            @csrf
            @php($category = null)
            @include('catalog.categories._form')

            <div>
                <x-input-label for="parent_uuid" :value="__('Parent category')" />
                <select id="parent_uuid" name="parent_uuid" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">{{ __('Root category') }}</option>
                    @foreach ($parentOptions as $parentOption)
                        <option value="{{ $parentOption->uuid }}" @selected(old('parent_uuid', $selectedParent) === $parentOption->uuid)>{{ str_repeat('— ', $parentOption->depth) }}{{ $parentOption->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('parent_uuid')" class="mt-2" />
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-5">
                <a href="{{ route('catalog.categories.index') }}" class="text-sm font-semibold text-slate-600 hover:text-slate-900">{{ __('Cancel') }}</a>
                <x-primary-button>{{ __('Create category') }}</x-primary-button>
            </div>
        </form>
    </div>
</x-app-layout>
