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
            <section id="readiness" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div><h2 class="text-lg font-semibold text-slate-900">{{ __('Activation readiness') }}</h2><p class="mt-1 text-xs text-slate-500">{{ __('Evaluated :time', ['time' => $readiness->checkedAt->format('Y-m-d H:i:s')]) }}</p></div>
                    <x-badge :tone="$readiness->ready ? 'emerald' : 'red'">{{ $readiness->ready ? __('Ready') : __('Not ready') }}</x-badge>
                </div>
                <?php
                    $readinessHref = static function ($item) use ($product, $canUpdate, $canManageAttributes, $canManageMedia): string {
                        $fallback = '#'.$item->section;
                        $defaultVariant = $product->defaultVariant;

                        return match ($item->code) {
                            'missing_product_name' => $canUpdate ? route('catalog.products.edit', $product->uuid).'#name' : $fallback,
                            'missing_product_slug' => $canUpdate ? route('catalog.products.edit', $product->uuid).'#slug' : $fallback,
                            'missing_product_brand' => $canUpdate ? route('catalog.products.edit', $product->uuid).'#brand' : $fallback,
                            'missing_product_manufacturer' => $canUpdate ? route('catalog.products.edit', $product->uuid).'#manufacturer' : $fallback,
                            'missing_primary_category', 'invalid_primary_category', 'archived_primary_category', 'archived_secondary_category' => $canUpdate ? route('catalog.products.edit', $product->uuid).'#primary_category_uuid' : $fallback,
                            'missing_primary_media', 'missing_primary_media_file' => $canManageMedia ? route('catalog.products.media.index', $product->uuid).'#product-image-management' : $fallback,
                            'missing_default_variant', 'invalid_default_variant', 'no_available_variants' => route('catalog.products.variants.index', $product->uuid),
                            'missing_variant_sku' => $canUpdate && $defaultVariant ? route('catalog.products.variants.edit', [$product->uuid, $defaultVariant->uuid]).'#sku' : $fallback,
                            'missing_variant_gtin' => $canUpdate && $defaultVariant ? route('catalog.products.variants.edit', [$product->uuid, $defaultVariant->uuid]).'#gtin' : $fallback,
                            'missing_required_product_attribute' => $canManageAttributes ? route('catalog.products.attributes.edit', $product->uuid).'#required-attributes' : $fallback,
                            'missing_required_variant_attribute' => $canManageAttributes && $defaultVariant ? route('catalog.products.variants.attributes.edit', [$product->uuid, $defaultVariant->uuid]).'#required-attributes' : $fallback,
                            'invalid_attribute_value', 'archived_attribute_option' => $canManageAttributes && $item->entityType === 'ProductVariant' && $defaultVariant
                                ? route('catalog.products.variants.attributes.edit', [$product->uuid, $defaultVariant->uuid]).'#required-attributes'
                                : ($canManageAttributes ? route('catalog.products.attributes.edit', $product->uuid).'#required-attributes' : $fallback),
                            default => $fallback,
                        };
                    };
                ?>
                @if($readiness->blockers !== [])<div class="mt-5"><h3 class="text-sm font-semibold text-red-800">{{ __('Blockers') }}</h3><ul class="mt-2 space-y-2">@foreach($readiness->blockers as $item)<li class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800"><a href="{{ $readinessHref($item) }}" class="font-medium underline decoration-red-300 underline-offset-2 hover:decoration-red-700">{{ $item->message }} <span class="whitespace-nowrap">{{ __('Open →') }}</span></a><span class="ml-2 font-mono text-xs text-red-500">{{ $item->code }}</span></li>@endforeach</ul></div>@endif
                @if($readiness->warnings !== [])<div class="mt-5"><h3 class="text-sm font-semibold text-amber-800">{{ __('Warnings') }}</h3><ul class="mt-2 space-y-2">@foreach($readiness->warnings as $item)<li class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900"><a href="{{ $readinessHref($item) }}" class="underline decoration-amber-300 underline-offset-2 hover:decoration-amber-700">{{ $item->message }} <span class="whitespace-nowrap">{{ __('Open →') }}</span></a><span class="ml-2 font-mono text-xs text-amber-600">{{ $item->code }}</span></li>@endforeach</ul></div>@endif
                <div class="mt-5 flex flex-wrap gap-3 border-t border-slate-200 pt-5">
                    @if($product->status->value === 'draft' && $canActivate)<form method="POST" action="{{ route('catalog.products.activate', $product->uuid) }}">@csrf<button type="submit" @disabled(!$readiness->ready) class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:bg-slate-300">{{ __('Activate') }}</button></form>@endif
                    @if($product->status->value === 'active' && $canReturnToDraft)<form method="POST" action="{{ route('catalog.products.return-to-draft', $product->uuid) }}">@csrf<button type="submit" class="rounded-lg border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">{{ __('Return to draft') }}</button></form>@endif
                    @if($product->status->value !== 'archived' && $canArchive)<form method="POST" action="{{ route('catalog.products.archive', $product->uuid) }}">@csrf<button type="submit" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">{{ __('Archive') }}</button></form>@endif
                    @if($product->status->value === 'archived' && $canRestore)<form method="POST" action="{{ route('catalog.products.restore', $product->uuid) }}">@csrf<button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ __('Restore to draft') }}</button></form>@endif
                </div>
                @if($product->status->value === 'draft' && !$readiness->ready)<p class="mt-3 text-sm text-slate-600">{{ __('Resolve all blockers before activation.') }}</p>@endif
                <x-input-error :messages="$errors->get('lifecycle')" class="mt-3" />
            </section>
            <section id="media" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-center justify-between gap-3"><div><h2 class="text-lg font-semibold text-slate-900">{{ __('Product images') }}</h2><p class="mt-1 text-sm text-slate-500">{{ trans_choice(':count image|:count images', $product->product_media_count, ['count'=>$product->product_media_count]) }}</p></div><a href="{{ route('catalog.products.media.index',$product->uuid) }}" class="text-sm font-semibold text-indigo-700">{{ $canManageMedia ? __('Manage images') : __('View images') }}</a></div>
                @if($product->primaryMedia)<img src="{{ route('catalog.media.content',$product->primaryMedia->uuid) }}" alt="{{ $product->primaryMedia->alt_text ?? '' }}" decoding="async" class="mt-4 max-h-96 w-full rounded-xl bg-slate-100 object-contain">@else<div class="mt-4 flex h-52 items-center justify-center rounded-xl bg-slate-100 text-sm text-slate-500">{{ __('No primary image selected') }}</div>@endif
            </section>
            <section id="details" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Product details') }}</h2>
                <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div><dt class="text-sm text-slate-500">{{ __('Brand') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $product->brand ?: __('Not set') }}</dd></div>
                    <div><dt class="text-sm text-slate-500">{{ __('Manufacturer') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $product->manufacturer ?: __('Not set') }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-sm text-slate-500">{{ __('Short description') }}</dt><dd class="mt-1 whitespace-pre-line text-slate-800">{{ $product->short_description ?: __('Not set') }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-sm text-slate-500">{{ __('Description') }}</dt><dd class="mt-1 whitespace-pre-line text-slate-800">{{ $product->description ?: __('Not set') }}</dd></div>
                </dl>
            </section>
            <section id="categories" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Categories') }}</h2>
                <p class="mt-2 text-sm text-slate-600">{{ __('Primary:') }} <span class="font-semibold text-slate-900">{{ $product->primaryCategory?->name ?? __('Not assigned') }}</span></p>
                <div class="mt-4 flex flex-wrap gap-2">@forelse ($product->categories as $category)<x-badge :tone="$product->primaryCategory?->is($category) ? 'indigo' : 'slate'">{{ $category->name }}</x-badge>@empty<span class="text-sm text-slate-500">{{ __('No categories assigned.') }}</span>@endforelse</div>
            </section>
            <section id="attributes" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-center justify-between gap-3"><h2 class="text-lg font-semibold text-slate-900">{{ __('Product Attributes') }}</h2>@if($canManageAttributes)<a href="{{ route('catalog.products.attributes.edit', $product->uuid) }}" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">{{ __('Edit attributes') }}</a>@endif</div>
                <dl class="mt-4 divide-y divide-slate-100">
                    @forelse($attributeDefinitions as $definition)@php($attributeValue = $attributeValues->get($definition->getKey())) @php($hasArchivedOption = $attributeValue && ($attributeValue->selectedOption?->status?->value === 'archived' || $attributeValue->selectedOptions->contains(fn ($option) => $option->status->value === 'archived')))<div class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between"><dt class="flex items-center gap-2 text-sm font-medium text-slate-700">{{ $definition->name }}@if($definition->required)<x-badge tone="amber">{{ __('Required') }}</x-badge>@endif</dt><dd class="text-sm font-semibold text-slate-900">@if($attributeValue){{ $attributeFormatter->format($attributeValue) }} @if($hasArchivedOption)<x-badge tone="gray">{{ __('Archived option') }}</x-badge>@endif @elseif($definition->required)<x-badge tone="red">{{ __('Missing required value') }}</x-badge>@else<span class="font-normal text-slate-400">{{ __('Not set') }}</span>@endif</dd></div>@empty<p class="py-4 text-sm text-slate-500">{{ __('No Product attributes are defined.') }}</p>@endforelse
                    @foreach($archivedAttributeValues as $attributeValue)<div class="flex items-center justify-between gap-4 py-3"><dt class="text-sm text-slate-600">{{ $attributeValue->definition->name }} <x-badge tone="gray">{{ __('Archived') }}</x-badge></dt><dd class="text-sm font-semibold">{{ $attributeFormatter->format($attributeValue) }} @if($attributeValue->selectedOption?->status?->value === 'archived')<x-badge tone="gray">{{ __('Archived option') }}</x-badge>@endif</dd></div>@endforeach
                </dl>
            </section>
            <section id="variants" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div><h2 class="text-lg font-semibold text-slate-900">{{ __('Variants') }}</h2><p class="mt-1 text-sm text-slate-500">{{ trans_choice(':count variant|:count variants', $product->variants_count, ['count' => $product->variants_count]) }}</p></div>
                    <div class="flex gap-3"><a href="{{ route('catalog.products.variants.index', $product->uuid) }}" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">{{ __('Manage variants') }}</a>@if ($canCreateVariant)<a href="{{ route('catalog.products.variants.create', $product->uuid) }}" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">{{ __('Add variant') }}</a>@endif</div>
                </div>
                <div class="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200">
                    @foreach ($product->variants as $variant)
                        <a href="{{ route('catalog.products.variants.show', [$product->uuid, $variant->uuid]) }}" class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-slate-50"><span><span class="font-semibold text-slate-900">{{ $variant->displayName() }}</span><span class="ml-2 text-sm text-slate-500">{{ $variant->sku ?: '—' }}</span></span><span class="flex gap-2"><x-badge :tone="$variant->status->value === 'active' ? 'emerald' : ($variant->status->value === 'archived' ? 'amber' : 'slate')">{{ $variant->status->value }}</x-badge>@if ($variant->isDefaultFor($product))<x-badge tone="indigo">{{ __('Default') }}</x-badge>@endif</span></a>
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
