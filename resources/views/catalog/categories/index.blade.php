<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Categories') }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ __('Organize products into a hierarchy.') }}</p>
            </div>
            @if ($canManage)
                <a href="{{ route('catalog.categories.create') }}" class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2" data-testid="category-create-button">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>
                    {{ __('Create category') }}
                </a>
            @else
                <x-badge>{{ __('Read only') }}</x-badge>
            @endif
        </div>
    </x-slot>

    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8" data-testid="categories-page">
        <section class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <form method="GET" action="{{ route('catalog.categories.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full sm:max-w-xs">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" />
                    </svg>
                    <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="{{ __('Search categories...') }}" aria-label="{{ __('Search categories') }}" class="block w-full rounded-lg border-slate-300 py-2 pl-9 pr-3 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" oninput="clearTimeout(window.__nordipassCategorySearchTimer); window.__nordipassCategorySearchTimer = setTimeout(() => this.form.requestSubmit(), 300)">
                </div>
                <div class="flex items-center gap-3">
                    <select name="status" aria-label="{{ __('Status') }}" onchange="this.form.requestSubmit()" class="rounded-lg border-slate-300 py-2 pl-3 pr-8 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="all" @selected($filters['status'] === 'all')>{{ __('All statuses') }}</option>
                        <option value="active" @selected($filters['status'] === 'active')>{{ __('Active') }}</option>
                        <option value="archived" @selected($filters['status'] === 'archived')>{{ __('Archived') }}</option>
                    </select>
                    @if ($hasActiveFilters)
                        <a href="{{ route('catalog.categories.index') }}" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">{{ __('Clear') }}</a>
                    @endif
                </div>
            </form>
        </section>

        <p class="mb-3 px-1 text-sm font-medium text-slate-500">{{ trans_choice(':count category|:count categories', $visibleCount, ['count' => $visibleCount]) }}</p>

        <x-input-error :messages="$errors->all()" class="mb-5 rounded-lg bg-red-50 p-4" />

        <section class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4" data-testid="category-tree" x-data="{ expanded: @js($initialExpanded) }">
            @if ($totalCount === 0)
                <div class="px-4 py-16 text-center">
                    <p class="font-semibold text-slate-900">{{ __('No categories yet.') }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Create a category to start organizing products.') }}</p>
                    @if ($canManage)
                        <a href="{{ route('catalog.categories.create') }}" class="mt-5 inline-flex rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ __('Create category') }}</a>
                    @endif
                </div>
            @elseif ($tree === [])
                <div class="px-4 py-16 text-center">
                    <p class="font-semibold text-slate-900">{{ __('No categories match these filters.') }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Try a different search or status filter.') }}</p>
                    <a href="{{ route('catalog.categories.index') }}" class="mt-5 inline-flex rounded-lg border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">{{ __('Clear filters') }}</a>
                </div>
            @else
                @include('catalog.categories._tree', ['nodes' => $tree])
            @endif
        </section>
    </div>
</x-app-layout>
