@php($definition = $definition ?? null)
<div class="grid gap-5 sm:grid-cols-2">
    <div><x-input-label for="name" :value="__('Name')" /><x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name', $definition?->name)" required /><x-input-error class="mt-2" :messages="$errors->get('name')" /></div>
    <div><x-input-label for="code" :value="__('Code')" /><x-text-input id="code" name="code" class="mt-1 block w-full font-mono" :value="old('code', $definition?->code)" required /><x-input-error class="mt-2" :messages="$errors->get('code')" /></div>
    <div class="sm:col-span-2"><x-input-label for="description" :value="__('Description')" /><textarea id="description" name="description" rows="3" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $definition?->description) }}</textarea><x-input-error class="mt-2" :messages="$errors->get('description')" /></div>
    <div><x-input-label for="type" :value="__('Data type')" /><select id="type" name="type" class="mt-1 block w-full rounded-md border-slate-300" required>@foreach($types as $type)<option value="{{ $type->value }}" @selected(old('type', $definition?->type?->value) === $type->value)>{{ ucfirst($type->value) }}</option>@endforeach</select><x-input-error class="mt-2" :messages="$errors->get('type')" /></div>
    <div><x-input-label for="scope" :value="__('Scope')" /><select id="scope" name="scope" class="mt-1 block w-full rounded-md border-slate-300" required>@foreach($scopes as $scope)<option value="{{ $scope->value }}" @selected(old('scope', $definition?->scope?->value) === $scope->value)>{{ ucfirst($scope->value) }}</option>@endforeach</select><x-input-error class="mt-2" :messages="$errors->get('scope')" /></div>
    <div><x-input-label for="unit" :value="__('Unit')" /><x-text-input id="unit" name="unit" class="mt-1 block w-full" :value="old('unit', $definition?->unit)" maxlength="50" /><x-input-error class="mt-2" :messages="$errors->get('unit')" /></div>
    <div><x-input-label for="sort_order" :value="__('Sort order')" /><x-text-input id="sort_order" name="sort_order" type="number" min="0" class="mt-1 block w-full" :value="old('sort_order', $definition?->sort_order ?? 0)" required /><x-input-error class="mt-2" :messages="$errors->get('sort_order')" /></div>
</div>
<div class="grid gap-3 sm:grid-cols-3">
    @foreach(['required' => 'Required', 'filterable' => 'Filterable', 'searchable' => 'Searchable'] as $field => $label)
        <input type="hidden" name="{{ $field }}" value="0"><label class="flex items-center gap-2 rounded-lg border border-slate-200 p-3"><input type="checkbox" name="{{ $field }}" value="1" class="rounded border-slate-300 text-indigo-600" @checked(old($field, (bool) ($definition?->{$field} ?? false)))><span class="text-sm font-medium text-slate-700">{{ __($label) }}</span></label>
    @endforeach
</div>
<fieldset class="space-y-4 rounded-xl border border-slate-200 p-4">
    <legend class="px-2 font-semibold text-slate-900">{{ __('Typed validation rules') }}</legend>
    <p class="text-sm text-slate-500">{{ __('Only rules compatible with the selected data type are accepted. Blank fields are ignored.') }}</p>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach(['min_length' => 'Text min length', 'max_length' => 'Text max length', 'min_selections' => 'Minimum selections', 'max_selections' => 'Maximum selections'] as $rule => $label)
            <div><x-input-label :for="'rule_'.$rule" :value="__($label)" /><x-text-input :id="'rule_'.$rule" :name="'validation_rules['.$rule.']'" type="number" min="0" class="mt-1 block w-full" :value="old('validation_rules.'.$rule, $definition?->validation_rules[$rule] ?? '')" /></div>
        @endforeach
        @foreach(['min' => 'Numeric minimum', 'max' => 'Numeric maximum'] as $rule => $label)
            <div><x-input-label :for="'rule_'.$rule" :value="__($label)" /><x-text-input :id="'rule_'.$rule" :name="'validation_rules['.$rule.']'" inputmode="decimal" class="mt-1 block w-full" :value="old('validation_rules.'.$rule, $definition?->validation_rules[$rule] ?? '')" /></div>
        @endforeach
        @foreach(['min_date' => 'Earliest date', 'max_date' => 'Latest date'] as $rule => $label)
            <div><x-input-label :for="'rule_'.$rule" :value="__($label)" /><x-text-input :id="'rule_'.$rule" :name="'validation_rules['.$rule.']'" type="date" class="mt-1 block w-full" :value="old('validation_rules.'.$rule, $definition?->validation_rules[$rule] ?? '')" /></div>
        @endforeach
    </div>
    <x-input-error :messages="$errors->get('validation_rules')" />
</fieldset>
