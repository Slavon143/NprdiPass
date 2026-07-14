<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog · Product variants') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ $variant->displayName() }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $product->name }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if ($variant->isDefaultFor($product))<x-badge tone="indigo">{{ __('Default') }}</x-badge>@endif
                @if ($canUpdate)<a href="{{ route('catalog.products.variants.edit', [$product->uuid, $variant->uuid]) }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ __('Edit variant') }}</a>@endif
            </div>
        </div>
    </x-slot>

    <div class="mx-auto grid max-w-5xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:px-8">
        <div class="space-y-6"><section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Identifiers') }}</h2>
            <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                <div><dt class="text-sm text-slate-500">{{ __('SKU') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $variant->sku ?: '—' }}</dd></div>
                <div><dt class="text-sm text-slate-500">{{ __('GTIN') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $variant->gtin ?: '—' }}</dd></div>
                <div><dt class="text-sm text-slate-500">{{ __('MPN') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $variant->mpn ?: '—' }}</dd></div>
                <div><dt class="text-sm text-slate-500">{{ __('Sort order') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $variant->sort_order }}</dd></div>
                <div><dt class="text-sm text-slate-500">{{ __('Status') }}</dt><dd class="mt-1 capitalize text-slate-900">{{ $variant->status->value }}</dd></div>
                <div><dt class="text-sm text-slate-500">{{ __('Variant UUID') }}</dt><dd class="mt-1 break-all font-mono text-xs text-slate-700">{{ $variant->uuid }}</dd></div>
            </dl>
            @if ($canSetDefault && ! $variant->isDefaultFor($product))
                <form method="POST" action="{{ route('catalog.products.variants.set-default', [$product->uuid, $variant->uuid]) }}" class="mt-6 border-t border-slate-200 pt-5">
                    @csrf
                    <x-primary-button>{{ __('Set as default') }}</x-primary-button>
                </form>
            @endif
        </section>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex items-center justify-between gap-3"><h2 class="text-lg font-semibold text-slate-900">{{ __('Variant Attributes') }}</h2>@if($canManageAttributes)<a href="{{ route('catalog.products.variants.attributes.edit', [$product->uuid, $variant->uuid]) }}" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">{{ __('Edit attributes') }}</a>@endif</div>
            <dl class="mt-4 divide-y divide-slate-100">@forelse($attributeDefinitions as $definition)@php($attributeValue = $attributeValues->get($definition->getKey())) @php($hasArchivedOption = $attributeValue && ($attributeValue->selectedOption?->status?->value === 'archived' || $attributeValue->selectedOptions->contains(fn ($option) => $option->status->value === 'archived')))<div class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between"><dt class="flex items-center gap-2 text-sm font-medium text-slate-700">{{ $definition->name }}@if($definition->required)<x-badge tone="amber">{{ __('Required') }}</x-badge>@endif</dt><dd class="text-sm font-semibold">@if($attributeValue){{ $attributeFormatter->format($attributeValue) }} @if($hasArchivedOption)<x-badge tone="gray">{{ __('Archived option') }}</x-badge>@endif @elseif($definition->required)<x-badge tone="red">{{ __('Missing required value') }}</x-badge>@else<span class="font-normal text-slate-400">{{ __('Not set') }}</span>@endif</dd></div>@empty<p class="py-4 text-sm text-slate-500">{{ __('No Variant attributes are defined.') }}</p>@endforelse @foreach($archivedAttributeValues as $attributeValue)<div class="flex items-center justify-between gap-4 py-3"><dt class="text-sm">{{ $attributeValue->definition->name }} <x-badge tone="gray">{{ __('Archived') }}</x-badge></dt><dd class="text-sm font-semibold">{{ $attributeFormatter->format($attributeValue) }}</dd></div>@endforeach</dl>
        </section></div>

        <aside class="space-y-6">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="font-semibold text-slate-900">{{ __('Product') }}</h2><p class="mt-3 font-semibold text-slate-900">{{ $product->name }}</p><p class="mt-1 font-mono text-xs text-slate-500">{{ $product->slug }}</p><a href="{{ route('catalog.products.variants.index', $product->uuid) }}" class="mt-4 inline-block text-sm font-semibold text-indigo-700 hover:text-indigo-900">{{ __('Manage variants') }}</a></section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="font-semibold text-slate-900">{{ __('Record') }}</h2><dl class="mt-4 space-y-3 text-sm"><div><dt class="text-slate-500">{{ __('Created by') }}</dt><dd>{{ $variant->createdBy?->name ?? __('Unknown') }}</dd></div><div><dt class="text-slate-500">{{ __('Updated by') }}</dt><dd>{{ $variant->updatedBy?->name ?? __('Unknown') }}</dd></div><div><dt class="text-slate-500">{{ __('Created') }}</dt><dd>{{ $variant->created_at?->format('Y-m-d H:i:s') }}</dd></div><div><dt class="text-slate-500">{{ __('Updated') }}</dt><dd>{{ $variant->updated_at?->format('Y-m-d H:i:s') }}</dd></div></dl></section>
        </aside>
    </div>
</x-app-layout>
