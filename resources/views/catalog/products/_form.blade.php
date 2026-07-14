<div>
    <x-input-label for="name" :value="__('Name')" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full scroll-mt-24 target:border-amber-500 target:ring-4 target:ring-amber-200" :value="old('name', $product?->name)" required autofocus />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

<div>
    <x-input-label for="slug" :value="__('Slug')" />
    <x-text-input id="slug" name="slug" type="text" class="mt-1 block w-full scroll-mt-24 target:border-amber-500 target:ring-4 target:ring-amber-200" :value="old('slug', $product?->slug)" :required="$product !== null" />
    <p class="mt-1 text-xs text-slate-500">{{ __('Leave blank when creating to generate it from the name.') }}</p>
    <x-input-error :messages="$errors->get('slug')" class="mt-2" />
</div>

<div>
    <x-input-label for="short_description" :value="__('Short description')" />
    <textarea id="short_description" name="short_description" rows="3" maxlength="500" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('short_description', $product?->short_description) }}</textarea>
    <x-input-error :messages="$errors->get('short_description')" class="mt-2" />
</div>

<div>
    <x-input-label for="description" :value="__('Description')" />
    <textarea id="description" name="description" rows="7" maxlength="10000" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $product?->description) }}</textarea>
    <x-input-error :messages="$errors->get('description')" class="mt-2" />
</div>

<div class="grid gap-6 sm:grid-cols-2">
    <div>
        <x-input-label for="brand" :value="__('Brand')" />
        <x-text-input id="brand" name="brand" type="text" class="mt-1 block w-full scroll-mt-24 target:border-amber-500 target:ring-4 target:ring-amber-200" :value="old('brand', $product?->brand)" maxlength="255" />
        <x-input-error :messages="$errors->get('brand')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="manufacturer" :value="__('Manufacturer')" />
        <x-text-input id="manufacturer" name="manufacturer" type="text" class="mt-1 block w-full scroll-mt-24 target:border-amber-500 target:ring-4 target:ring-amber-200" :value="old('manufacturer', $product?->manufacturer)" maxlength="255" />
        <x-input-error :messages="$errors->get('manufacturer')" class="mt-2" />
    </div>
</div>

<fieldset class="space-y-4 rounded-xl border border-slate-200 p-4">
    <legend class="px-2 font-semibold text-slate-900">{{ __('Categories') }}</legend>
    <div>
        <x-input-label for="primary_category_uuid" :value="__('Primary category')" />
        <select id="primary_category_uuid" name="primary_category_uuid" class="mt-1 block w-full scroll-mt-24 rounded-lg border-slate-300 shadow-sm target:border-amber-500 target:ring-4 target:ring-amber-200 focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">{{ __('No primary category') }}</option>
            @foreach ($categoryOptions as $categoryOption)
                <option value="{{ $categoryOption->uuid }}" @selected(old('primary_category_uuid', $product?->primaryCategory?->uuid) === $categoryOption->uuid)>{{ str_repeat('— ', $categoryOption->depth) }}{{ $categoryOption->name }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">{{ __('Draft products may be saved without a primary category.') }}</p>
        <x-input-error :messages="$errors->get('primary_category_uuid')" class="mt-2" />
    </div>

    @php($checkedCategoryUuids = old('category_uuids', $selectedCategoryUuids))
    <div>
        <p class="text-sm font-medium text-slate-700">{{ __('Additional categories') }}</p>
        @if ($categoryOptions->isEmpty())
            <p class="mt-2 text-sm text-slate-500">{{ __('No active categories are available.') }}</p>
        @else
            <div class="mt-2 grid max-h-72 gap-2 overflow-y-auto rounded-lg border border-slate-200 p-3 sm:grid-cols-2">
                @foreach ($categoryOptions as $categoryOption)
                    <label class="flex items-start gap-2 rounded-lg p-2 hover:bg-slate-50">
                        <input type="checkbox" name="category_uuids[]" value="{{ $categoryOption->uuid }}" class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" @checked(in_array($categoryOption->uuid, $checkedCategoryUuids, true))>
                        <span class="text-sm text-slate-700"><span class="text-slate-300" aria-hidden="true">{{ str_repeat('— ', $categoryOption->depth) }}</span>{{ $categoryOption->name }}</span>
                    </label>
                @endforeach
            </div>
        @endif
        <x-input-error :messages="$errors->get('category_uuids')" class="mt-2" />
        <x-input-error :messages="$errors->get('category_uuids.*')" class="mt-2" />
    </div>
</fieldset>
