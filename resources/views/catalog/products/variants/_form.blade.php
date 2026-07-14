<div>
    <x-input-label for="name" :value="__('Variant name')" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $variant?->name)" maxlength="255" autofocus />
    <p class="mt-1 text-xs text-slate-500">{{ __('If blank, SKU or a short UUID is used as the display label.') }}</p>
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

<div class="grid gap-6 sm:grid-cols-2">
    <div>
        <x-input-label for="sku" :value="__('SKU')" />
        <x-text-input id="sku" name="sku" type="text" class="mt-1 block w-full" :value="old('sku', $variant?->sku)" maxlength="100" />
        <p class="mt-1 text-xs text-slate-500">{{ __('Optional and unique inside the current company.') }}</p>
        <x-input-error :messages="$errors->get('sku')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="gtin" :value="__('GTIN')" />
        <x-text-input id="gtin" name="gtin" type="text" inputmode="numeric" class="mt-1 block w-full" :value="old('gtin', $variant?->gtin)" maxlength="14" />
        <p class="mt-1 text-xs text-slate-500">{{ __('Optional GTIN-8, GTIN-12, GTIN-13, or GTIN-14 with a valid check digit.') }}</p>
        <x-input-error :messages="$errors->get('gtin')" class="mt-2" />
    </div>
</div>

<div class="grid gap-6 sm:grid-cols-2">
    <div>
        <x-input-label for="mpn" :value="__('MPN')" />
        <x-text-input id="mpn" name="mpn" type="text" class="mt-1 block w-full" :value="old('mpn', $variant?->mpn)" maxlength="100" />
        <x-input-error :messages="$errors->get('mpn')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="sort_order" :value="__('Sort order')" />
        <x-text-input id="sort_order" name="sort_order" type="number" min="0" max="4294967295" class="mt-1 block w-full" :value="old('sort_order', $variant?->sort_order ?? 0)" required />
        <x-input-error :messages="$errors->get('sort_order')" class="mt-2" />
    </div>
</div>
