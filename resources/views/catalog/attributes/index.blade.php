@php
    $typeLabels = [
        'text' => __('Text'),
        'select' => __('Single select'),
        'multiselect' => __('Multi select'),
        'integer' => __('Number'),
        'decimal' => __('Decimal'),
        'boolean' => __('Boolean'),
        'date' => __('Date'),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Attributes') }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ __('Define reusable characteristics for products and variants.') }}</p>
            </div>
            @if ($canManage)
                <a href="{{ route('catalog.attributes.create') }}" class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2" data-testid="attribute-create-button">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>
                    {{ __('Create attribute') }}
                </a>
            @else
                <x-badge>{{ __('Read only') }}</x-badge>
            @endif
        </div>
    </x-slot>

    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8" data-testid="attributes-page">
        <section class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <form method="GET" action="{{ route('catalog.attributes.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full sm:max-w-xs">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" />
                    </svg>
                    <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="{{ __('Search attributes...') }}" aria-label="{{ __('Search attributes') }}" class="block w-full rounded-lg border-slate-300 py-2 pl-9 pr-3 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" oninput="clearTimeout(window.__nordipassAttrSearchTimer); window.__nordipassAttrSearchTimer = setTimeout(() => this.form.requestSubmit(), 300)">
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <select name="scope" aria-label="{{ __('Scope') }}" onchange="this.form.requestSubmit()" class="rounded-lg border-slate-300 py-2 pl-3 pr-8 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="all" @selected($filters['scope'] === 'all')>{{ __('All scopes') }}</option>
                        <option value="product" @selected($filters['scope'] === 'product')>{{ __('Product') }}</option>
                        <option value="variant" @selected($filters['scope'] === 'variant')>{{ __('Variant') }}</option>
                        <option value="both" @selected($filters['scope'] === 'both')>{{ __('Product & variant') }}</option>
                    </select>
                    <select name="type" aria-label="{{ __('Type') }}" onchange="this.form.requestSubmit()" class="rounded-lg border-slate-300 py-2 pl-3 pr-8 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="all" @selected($filters['type'] === 'all')>{{ __('All types') }}</option>
                        @foreach ($allTypes as $t)
                            <option value="{{ $t->value }}" @selected($filters['type'] === $t->value)>{{ $typeLabels[$t->value] }}</option>
                        @endforeach
                    </select>
                    <select name="status" aria-label="{{ __('Status') }}" onchange="this.form.requestSubmit()" class="rounded-lg border-slate-300 py-2 pl-3 pr-8 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="all" @selected($filters['status'] === 'all')>{{ __('All statuses') }}</option>
                        <option value="active" @selected($filters['status'] === 'active')>{{ __('Active') }}</option>
                        <option value="archived" @selected($filters['status'] === 'archived')>{{ __('Archived') }}</option>
                    </select>
                    @if ($hasActiveFilters)
                        <a href="{{ route('catalog.attributes.index') }}" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">{{ __('Clear') }}</a>
                    @endif
                </div>
            </form>
        </section>

        <p class="mb-3 px-1 text-sm font-medium text-slate-500">{{ trans_choice(':count attribute|:count attributes', $visibleCount, ['count' => $visibleCount]) }}</p>

        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm" data-testid="attributes-table">
            @if ($definitions->isEmpty())
                <div class="px-6 py-16 text-center">
                    @if ($hasActiveFilters)
                        <p class="font-semibold text-slate-900">{{ __('No attributes match these filters.') }}</p>
                        <p class="mt-1 text-sm text-slate-500">{{ __('Try a different search, type, scope, or status filter.') }}</p>
                        <a href="{{ route('catalog.attributes.index') }}" class="mt-5 inline-flex rounded-lg border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">{{ __('Clear filters') }}</a>
                    @elseif ($totalCount === 0)
                        <p class="font-semibold text-slate-900">{{ __('No attributes yet.') }}</p>
                        <p class="mt-1 text-sm text-slate-500">{{ __('Create an attribute to define reusable characteristics for products and variants.') }}</p>
                        @if ($canManage)
                            <a href="{{ route('catalog.attributes.create') }}" class="mt-5 inline-flex rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ __('Create attribute') }}</a>
                        @endif
                    @endif
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-5 py-3">{{ __('Attribute') }}</th>
                                <th class="px-5 py-3">{{ __('Type') }}</th>
                                <th class="px-5 py-3">{{ __('Scope') }}</th>
                                <th class="px-5 py-3">{{ __('Usage') }}</th>
                                <th class="px-5 py-3">{{ __('Status') }}</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($definitions as $definition)
                                @php
                                    $usesOptions = $definition->usesOptions();
                                    $productUsage = (int) $definition->product_values_count;
                                    $variantUsage = (int) $definition->variant_values_count;
                                    $usageParts = [];
                                    if ($productUsage > 0) {
                                        $usageParts[] = trans_choice(':count product|:count products', $productUsage, ['count' => $productUsage]);
                                    }
                                    if ($variantUsage > 0) {
                                        $usageParts[] = trans_choice(':count variant|:count variants', $variantUsage, ['count' => $variantUsage]);
                                    }
                                    $usageText = implode(' · ', $usageParts);
                                @endphp
                                <tr>
                                    <td class="px-5 py-4">
                                        <a href="{{ route('catalog.attributes.show', $definition->uuid) }}" class="group block">
                                            <p class="font-semibold text-slate-900 group-hover:text-indigo-700">{{ $definition->name }}</p>
                                            @if ($definition->code !== \Illuminate\Support\Str::slug($definition->name, '_'))
                                                <p class="font-mono text-xs text-slate-500">{{ $definition->code }}</p>
                                            @endif
                                        </a>
                                    </td>
                                    <td class="px-5 py-4 text-slate-700">{{ $typeLabels[$definition->type->value] ?? $definition->type->value }}</td>
                                    <td class="px-5 py-4 text-slate-700">
                                        @if ($definition->scope->value === 'both')
                                            {{ __('Product') }}, {{ __('Variant') }}
                                        @else
                                            {{ $definition->scope->value === 'product' ? __('Product') : __('Variant') }}
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-slate-700">
                                        @if ($usesOptions && $definition->options_count > 0)
                                            {{ trans_choice(':count option|:count options', $definition->options_count, ['count' => $definition->options_count]) }}
                                            @if ($usageText !== '')
                                                <span class="text-slate-400"> · {{ __('used by') }} </span>{{ $usageText }}
                                            @endif
                                        @elseif ($usageText !== '')
                                            {{ __('Used by') }} {{ $usageText }}
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4">
                                        <x-badge :tone="$definition->status->value === 'active' ? 'emerald' : 'slate'">{{ ucfirst($definition->status->value) }}</x-badge>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        @if ($canManage)
                                            <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                                                <button type="button" @click="open = !open" :aria-expanded="open" aria-haspopup="menu" class="inline-flex items-center justify-center rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500" aria-label="{{ __('Actions') }}">
                                                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" /></svg>
                                                </button>
                                                <div x-cloak x-show="open" x-transition class="absolute right-0 z-30 mt-1 w-52 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl" role="menu">
                                                    <a href="{{ route('catalog.attributes.show', $definition->uuid) }}" role="menuitem" class="block px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-50">{{ __('View') }}</a>
                                                    <a href="{{ route('catalog.attributes.edit', $definition->uuid) }}" role="menuitem" class="block px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-50">{{ __('Edit') }}</a>
                                                    @if ($usesOptions)
                                                        <a href="{{ route('catalog.attributes.show', $definition->uuid).'#options' }}" role="menuitem" class="block px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-50">{{ __('Manage options') }}</a>
                                                    @endif
                                                    <a href="{{ route('catalog.attributes.usage', $definition->uuid) }}" role="menuitem" class="block px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-50">{{ __('View usage') }}</a>
                                                    <div class="my-1 border-t border-slate-200"></div>
                                                    @if ($definition->status->value === 'active')
                                                        <form method="POST" action="{{ route('catalog.attributes.archive', $definition->uuid) }}">
                                                            @csrf
                                                            <button type="submit" role="menuitem" class="block w-full px-4 py-2 text-left text-sm text-slate-700 transition hover:bg-slate-50">{{ __('Archive') }}</button>
                                                        </form>
                                                    @else
                                                        <form method="POST" action="{{ route('catalog.attributes.restore', $definition->uuid) }}">
                                                            @csrf
                                                            <button type="submit" role="menuitem" class="block w-full px-4 py-2 text-left text-sm text-slate-700 transition hover:bg-slate-50">{{ __('Restore') }}</button>
                                                        </form>
                                                    @endif
                                                    <form method="POST" action="{{ route('catalog.attributes.destroy', $definition->uuid) }}" onsubmit="return confirm('{{ __('Delete this attribute? This is only allowed while it has no product or variant values.') }}')">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" role="menuitem" class="block w-full px-4 py-2 text-left text-sm text-red-700 transition hover:bg-red-50">{{ __('Delete') }}</button>
                                                    </form>
                                                </div>
                                            </div>
                                        @else
                                            <a href="{{ route('catalog.attributes.show', $definition->uuid) }}" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">{{ __('View') }}</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($definitions->hasPages())
                    <div class="border-t border-slate-200 px-5 py-4">{{ $definitions->links() }}</div>
                @endif
            @endif
        </section>
    </div>
</x-app-layout>
