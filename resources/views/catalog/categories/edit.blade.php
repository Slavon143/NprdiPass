<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Edit category') }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $category->name }} · {{ $category->slug }}</p>
            </div>
            <x-badge :tone="$category->status->value === 'active' ? 'emerald' : 'amber'">{{ $category->status->value }}</x-badge>
        </div>
    </x-slot>

    <div class="mx-auto grid max-w-5xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:px-8">
        <form method="POST" action="{{ route('catalog.categories.update', $category->uuid) }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            @csrf
            @method('PATCH')
            @include('catalog.categories._form')

            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-5">
                <a href="{{ route('catalog.categories.index') }}" class="text-sm font-semibold text-slate-600 hover:text-slate-900">{{ __('Back') }}</a>
                <x-primary-button>{{ __('Save category') }}</x-primary-button>
            </div>
        </form>

        <aside class="space-y-6">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-semibold text-slate-900">{{ __('Hierarchy') }}</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('Depth') }}</dt><dd class="font-semibold">{{ $category->depth }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('Sort order') }}</dt><dd class="font-semibold">{{ $category->sort_order }}</dd></div>
                </dl>

                <form method="POST" action="{{ route('catalog.categories.move', $category->uuid) }}" class="mt-5 space-y-3 border-t border-slate-200 pt-5">
                    @csrf
                    @method('PATCH')
                    <x-input-label for="parent_uuid" :value="__('Move to parent')" />
                    <select id="parent_uuid" name="parent_uuid" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">{{ __('Root category') }}</option>
                        @foreach ($parentOptions as $parentOption)
                            <option value="{{ $parentOption->uuid }}" @selected(old('parent_uuid', $category->parent?->uuid) === $parentOption->uuid)>{{ str_repeat('— ', $parentOption->depth) }}{{ $parentOption->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('parent_uuid')" class="mt-2" />
                    <x-secondary-button type="submit">{{ __('Move category') }}</x-secondary-button>
                </form>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-semibold text-slate-900">{{ __('Lifecycle') }}</h2>
                @if ($category->status->value === 'active')
                    @if ($activeChildrenCount > 0 || $activePrimaryProductsCount > 0)
                        <div class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                            <p class="font-semibold">{{ __('Archive is currently blocked.') }}</p>
                            @if ($activeChildrenCount > 0)<p class="mt-1">{{ trans_choice(':count active child|:count active children', $activeChildrenCount, ['count' => $activeChildrenCount]) }}</p>@endif
                            @if ($activePrimaryProductsCount > 0)<p class="mt-1">{{ trans_choice('Primary for :count active product|Primary for :count active products', $activePrimaryProductsCount, ['count' => $activePrimaryProductsCount]) }}</p>@endif
                        </div>
                    @else
                        <p class="mt-2 text-sm text-slate-500">{{ __('Archive hides this category without deleting it or changing product relations.') }}</p>
                        <form method="POST" action="{{ route('catalog.categories.archive', $category->uuid) }}" class="mt-4">
                            @csrf
                            @method('PATCH')
                            <x-danger-button>{{ __('Archive') }}</x-danger-button>
                        </form>
                    @endif
                @else
                    <p class="mt-2 text-sm text-slate-500">{{ __('Restore keeps the existing slug and hierarchy.') }}</p>
                    <form method="POST" action="{{ route('catalog.categories.restore', $category->uuid) }}" class="mt-4">
                        @csrf
                        @method('PATCH')
                        <x-primary-button>{{ __('Restore') }}</x-primary-button>
                    </form>
                @endif
                <x-input-error :messages="$errors->get('category')" class="mt-3" />
            </section>
        </aside>
    </div>
</x-app-layout>
