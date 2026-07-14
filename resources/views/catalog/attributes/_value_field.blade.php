@php
    $value = $values->get($definition->getKey());
    $field = 'attributes['.$definition->uuid.']';
    $oldKey = 'attributes.'.$definition->uuid;
    $type = $definition->type->value;
    $current = match ($type) {
        'text' => $value?->value_text,
        'integer' => $value?->value_integer,
        'decimal' => $value?->value_decimal,
        'boolean' => $value === null ? '' : ($value->value_boolean ? '1' : '0'),
        'date' => $value?->value_date?->format('Y-m-d'),
        'select' => $value?->value_option_id,
        'multiselect' => $value?->selectedOptions?->pluck('id')->map(fn ($id) => (string) $id)->all() ?? [],
    };
    $input = old($oldKey, $current);
@endphp
<div class="rounded-xl border border-slate-200 p-4">
    <div class="flex flex-wrap items-center gap-2"><label class="font-semibold text-slate-900" for="attribute_{{ $definition->uuid }}">{{ $definition->name }}</label>@if($definition->required)<x-badge tone="amber">{{ __('Required') }}</x-badge>@endif @if($definition->required && $value === null)<x-badge tone="red">{{ __('Missing required value') }}</x-badge>@endif @if($definition->unit)<span class="text-xs text-slate-500">{{ $definition->unit }}</span>@endif</div>
    @if($definition->description)<p class="mt-1 text-sm text-slate-500">{{ $definition->description }}</p>@endif
    <div class="mt-3">
        @if($type === 'text')
            <x-text-input id="attribute_{{ $definition->uuid }}" :name="$field" class="block w-full" :value="$input" maxlength="1000" />
        @elseif($type === 'integer')
            <x-text-input id="attribute_{{ $definition->uuid }}" :name="$field" type="number" step="1" class="block w-full" :value="$input" />
        @elseif($type === 'decimal')
            <x-text-input id="attribute_{{ $definition->uuid }}" :name="$field" inputmode="decimal" class="block w-full" :value="$input" />
        @elseif($type === 'boolean')
            <select id="attribute_{{ $definition->uuid }}" name="{{ $field }}" class="block w-full rounded-md border-slate-300"><option value="">{{ __('Not set') }}</option><option value="1" @selected((string)$input === '1')>{{ __('Yes') }}</option><option value="0" @selected((string)$input === '0')>{{ __('No') }}</option></select>
        @elseif($type === 'date')
            <x-text-input id="attribute_{{ $definition->uuid }}" :name="$field" type="date" class="block w-full" :value="$input" />
        @elseif($type === 'select')
            <select id="attribute_{{ $definition->uuid }}" name="{{ $field }}" class="block w-full rounded-md border-slate-300"><option value="">{{ __('Not set') }}</option>@foreach($definition->options->filter(fn ($candidate) => $candidate->status->value === 'active') as $option)<option value="{{ $option->id }}" @selected((string)$input === (string)$option->id)>{{ $option->label }}</option>@endforeach @if($value?->selectedOption?->status?->value === 'archived')<option value="{{ $value->selectedOption->id }}" selected disabled>{{ $value->selectedOption->label }} ({{ __('Archived') }})</option>@endif</select>
        @else
            <div id="attribute_{{ $definition->uuid }}" class="grid gap-2 sm:grid-cols-2">@foreach($definition->options->filter(fn ($candidate) => $candidate->status->value === 'active') as $option)<label class="flex items-center gap-2 rounded-lg border border-slate-200 p-2"><input type="checkbox" name="{{ $field }}[]" value="{{ $option->id }}" class="rounded border-slate-300 text-indigo-600" @checked(in_array((string)$option->id, array_map('strval', is_array($input) ? $input : []), true))><span class="text-sm">{{ $option->label }}</span></label>@endforeach</div>
        @endif
    </div>
    <x-input-error class="mt-2" :messages="$errors->get($oldKey)" />
</div>
