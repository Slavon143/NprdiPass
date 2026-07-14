<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div><p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }}</p><h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ $product->name }}</h1><p class="mt-1 font-mono text-xs text-slate-500">{{ $product->slug }}</p></div>
            <div class="flex items-center gap-3">
                @php($statusTone = match ($product->status->value) { 'active' => 'emerald', 'archived' => 'amber', default => 'indigo' })
                <x-badge :tone="$statusTone">{{ $product->status->value }}</x-badge>
                @if ($canUpdate)<a href="{{ route('catalog.products.edit', $product->uuid) }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ __('Edit product') }}</a>@endif
            </div>
        </div>
    </x-slot>

    <div class="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:px-8">
        <div class="space-y-6">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-center justify-between gap-3"><div><h2 class="text-lg font-semibold text-slate-900">{{ __('Product images') }}</h2><p class="mt-1 text-sm text-slate-500">{{ trans_choice(':count image|:count images', $product->product_media_count, ['count'=>$product->product_media_count]) }}</p></div><a href="{{ route('catalog.products.media.index',$product->uuid) }}" class="text-sm font-semibold text-indigo-700">{{ $canManageMedia ? __('Manage images') : __('View images') }}</a></div>
                @if($product->primaryMedia)<img src="{{ route('catalog.media.content',$product->primaryMedia->uuid) }}" alt="{{ $product->primaryMedia->alt_text ?? '' }}" decoding="async" class="mt-4 max-h-96 w-full rounded-xl bg-slate-100 object-contain">@else<div class="mt-4 flex h-52 items-center justify-center rounded-xl bg-slate-100 text-sm text-slate-500">{{ __('No primary image selected') }}</div>@endif
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Product details') }}</h2>
                <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div><dt class="text-sm text-slate-500">{{ __('Brand') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $product->brand ?: __('Not set') }}</dd></div>
                    <div><dt class="text-sm text-slate-500">{{ __('Manufacturer') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $product->manufacturer ?: __('Not set') }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-sm text-slate-500">{{ __('Short description') }}</dt><dd class="mt-1 whitespace-pre-line text-slate-800">{{ $product->short_description ?: __('Not set') }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-sm text-slate-500">{{ __('Description') }}</dt><dd class="mt-1 whitespace-pre-line text-slate-800">{{ $product->description ?: __('Not set') }}</dd></div>
                </dl>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Categories') }}</h2>
                <p class="mt-2 text-sm text-slate-600">{{ __('Primary:') }} <span class="font-semibold text-slate-900">{{ $product->primaryCategory?->name ?? __('Not assigned') }}</span></p>
                <div class="mt-4 flex flex-wrap gap-2">@forelse ($product->categories as $category)<x-badge :tone="$product->primaryCategory?->is($category) ? 'indigo' : 'slate'">{{ $category->name }}</x-badge>@empty<span class="text-sm text-slate-500">{{ __('No categories assigned.') }}</span>@endforelse</div>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-center justify-between gap-3"><h2 class="text-lg font-semibold text-slate-900">{{ __('Product Attributes') }}</h2>@if($canManageAttributes)<a href="{{ route('catalog.products.attributes.edit', $product->uuid) }}" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">{{ __('Edit attributes') }}</a>@endif</div>
                <dl class="mt-4 divide-y divide-slate-100">
                    @forelse($attributeDefinitions as $definition)@php($attributeValue = $attributeValues->get($definition->getKey())) @php($hasArchivedOption = $attributeValue && ($attributeValue->selectedOption?->status?->value === 'archived' || $attributeValue->selectedOptions->contains(fn ($option) => $option->status->value === 'archived')))<div class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between"><dt class="flex items-center gap-2 text-sm font-medium text-slate-700">{{ $definition->name }}@if($definition->required)<x-badge tone="amber">{{ __('Required') }}</x-badge>@endif</dt><dd class="text-sm font-semibold text-slate-900">@if($attributeValue){{ $attributeFormatter->format($attributeValue) }} @if($hasArchivedOption)<x-badge tone="gray">{{ __('Archived option') }}</x-badge>@endif @elseif($definition->required)<x-badge tone="red">{{ __('Missing required value') }}</x-badge>@else<span class="font-normal text-slate-400">{{ __('Not set') }}</span>@endif</dd></div>@empty<p class="py-4 text-sm text-slate-500">{{ __('No Product attributes are defined.') }}</p>@endforelse
                    @foreach($archivedAttributeValues as $attributeValue)<div class="flex items-center justify-between gap-4 py-3"><dt class="text-sm text-slate-600">{{ $attributeValue->definition->name }} <x-badge tone="gray">{{ __('Archived') }}</x-badge></dt><dd class="text-sm font-semibold">{{ $attributeFormatter->format($attributeValue) }} @if($attributeValue->selectedOption?->status?->value === 'archived')<x-badge tone="gray">{{ __('Archived option') }}</x-badge>@endif</dd></div>@endforeach
                </dl>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div><h2 class="text-lg font-semibold text-slate-900">{{ __('Variants') }}</h2><p class="mt-1 text-sm text-slate-500">{{ trans_choice(':count variant|:count variants', $product->variants_count, ['count' => $product->variants_count]) }}</p></div>
                    <div class="flex gap-3"><a href="{{ route('catalog.products.variants.index', $product->uuid) }}" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">{{ __('Manage variants') }}</a>@if ($canCreateVariant)<a href="{{ route('catalog.products.variants.create', $product->uuid) }}" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">{{ __('Add variant') }}</a>@endif</div>
                </div>
                <div class="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200">
                    @foreach ($product->variants as $variant)
                        <a href="{{ route('catalog.products.variants.show', [$product->uuid, $variant->uuid]) }}" class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-slate-50"><span><span class="font-semibold text-slate-900">{{ $variant->displayName() }}</span><span class="ml-2 text-sm text-slate-500">{{ $variant->sku ?: '—' }}</span></span>@if ($variant->isDefaultFor($product))<x-badge tone="indigo">{{ __('Default') }}</x-badge>@endif</a>
                    @endforeach
                </div>
                @if ($product->variants_count > 5)<p class="mt-3 text-xs text-slate-500">{{ __('Showing the first 5 variants in catalog order.') }}</p>@endif
            </section>
        </div>

        <aside class="space-y-6">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-semibold text-slate-900">{{ __('Default variant') }}</h2>
                @if ($product->defaultVariant)
                    <dl class="mt-4 space-y-3 text-sm">
                        <div><dt class="text-slate-500">{{ __('Variant UUID') }}</dt><dd class="mt-1 break-all font-mono text-xs">{{ $product->defaultVariant->uuid }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('Name') }}</dt><dd class="font-semibold">{{ $product->defaultVariant->name }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('Status') }}</dt><dd class="capitalize">{{ $product->defaultVariant->status->value }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('SKU') }}</dt><dd>{{ $product->defaultVariant->sku ?: __('Not set') }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('GTIN') }}</dt><dd>{{ $product->defaultVariant->gtin ?: __('Not set') }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('MPN') }}</dt><dd>{{ $product->defaultVariant->mpn ?: __('Not set') }}</dd></div>
                    </dl>
                @endif
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-semibold text-slate-900">{{ __('Record') }}</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-slate-500">{{ __('Created by') }}</dt><dd>{{ $product->createdBy?->name ?? __('Unknown') }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Updated by') }}</dt><dd>{{ $product->updatedBy?->name ?? __('Unknown') }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Created') }}</dt><dd>{{ $product->created_at?->format('Y-m-d H:i:s') }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Updated') }}</dt><dd>{{ $product->updated_at?->format('Y-m-d H:i:s') }}</dd></div>
                </dl>
            </section>
        </aside>
    </div>
</x-app-layout>
