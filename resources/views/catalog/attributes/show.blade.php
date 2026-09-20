<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog · Attributes') }}</p>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold text-slate-900">{{ $definition->name }}</h1>
                    <x-badge :tone="$definition->status->value === 'active' ? 'emerald' : ($definition->status->value === 'deprecated' ? 'amber' : 'slate')">{{ ucfirst($definition->status->value) }}</x-badge>
                </div>
                <p class="font-mono text-xs text-slate-500">{{ $definition->code }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('catalog.attributes.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">{{ __('All attributes') }}</a>
                @if ($canManage && $definition->status->value === 'active')
                    <a href="{{ route('catalog.attributes.edit', $definition->uuid) }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">{{ __('Edit') }}</a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="mx-auto grid max-w-7xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-3 lg:px-8" data-testid="attribute-governance-page">
        <div class="space-y-6 lg:col-span-1">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-semibold text-slate-900">{{ __('Definition') }}</h2>
                <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-slate-500">{{ __('Type') }}</dt><dd class="font-semibold capitalize">{{ $definition->type->value }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Scope') }}</dt><dd class="font-semibold capitalize">{{ $definition->scope->value }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Unit') }}</dt><dd>{{ $definition->unit ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Usage') }}</dt><dd>{{ $definition->product_values_count + $definition->variant_values_count }}</dd></div>
                </dl>
                @if ($definition->description)<p class="mt-5 text-sm text-slate-600">{{ $definition->description }}</p>@endif
            </section>

            @if ($canManage)
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="font-semibold text-slate-900">{{ __('Lifecycle governance') }}</h2>
                    @if ($definition->status->value === 'active')
                        <p class="mt-2 text-sm text-slate-500">{{ __('Deprecation keeps historical values readable and directs new integrations to a compatible replacement.') }}</p>
                        @if ($replacementDefinitions->isNotEmpty())
                            <form method="POST" action="{{ route('catalog.attributes.deprecate', $definition->uuid) }}" class="mt-4 space-y-3">
                                @csrf
                                <x-input-label for="replacement_uuid" :value="__('Replacement attribute')" />
                                <select id="replacement_uuid" name="replacement_uuid" required class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">{{ __('Choose replacement') }}</option>
                                    @foreach ($replacementDefinitions as $replacement)
                                        <option value="{{ $replacement->uuid }}">{{ $replacement->name }} · {{ $replacement->code }}</option>
                                    @endforeach
                                </select>
                                <x-secondary-button>{{ __('Deprecate with replacement') }}</x-secondary-button>
                            </form>
                        @else
                            <p class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ __('Create another active attribute with the same type and scope before deprecating this definition.') }}</p>
                        @endif
                        <form method="POST" action="{{ route('catalog.attributes.archive', $definition->uuid) }}" class="mt-5 border-t border-slate-200 pt-5">
                            @csrf
                            <x-danger-button>{{ __('Archive attribute') }}</x-danger-button>
                        </form>
                    @elseif ($definition->status->value === 'archived')
                        <form method="POST" action="{{ route('catalog.attributes.restore', $definition->uuid) }}">
                            @csrf
                            <x-primary-button>{{ __('Restore attribute') }}</x-primary-button>
                        </form>
                    @else
                        <p class="mt-2 text-sm text-amber-800">{{ __('Deprecated definitions remain available for historical values but cannot be added to new category templates.') }}</p>
                    @endif
                </section>
            @endif
        </div>

        <section id="options" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
            <div class="flex items-center justify-between"><h2 class="font-semibold text-slate-900">{{ __('Controlled vocabulary') }}</h2><span class="text-sm text-slate-500">{{ $definition->options->count() }}</span></div>
            @if (! $definition->usesOptions())
                <p class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-600">{{ __('This attribute type does not use predefined options.') }}</p>
            @else
                <p class="mt-2 text-sm text-slate-500">{{ __('Only active, approved options are selectable in new product data.') }}</p>
                <div class="mt-4 space-y-3">
                    @foreach ($definition->options as $option)
                        @php
                            $replacementOptions = $definition->options->filter(fn ($candidate) => $candidate->id !== $option->id && $candidate->status->value === 'active' && $candidate->approval_status->value === 'approved');
                        @endphp
                        <div class="rounded-xl border border-slate-200 p-4">
                            <form method="POST" action="{{ route('catalog.attributes.options.update', [$definition->uuid, $option->id]) }}" class="grid gap-3 sm:grid-cols-[1fr_1fr_7rem_auto] sm:items-end">
                                @csrf
                                @method('PATCH')
                                <div><x-input-label :for="'label_'.$option->id" :value="__('Label')"/><x-text-input :id="'label_'.$option->id" name="label" class="mt-1 block w-full" :value="$option->label" :disabled="!$canManage || $option->status->value !== 'active'"/></div>
                                <div><x-input-label :for="'code_'.$option->id" :value="__('Code')"/><x-text-input :id="'code_'.$option->id" name="code" class="mt-1 block w-full font-mono" :value="$option->code" :disabled="!$canManage || $option->status->value !== 'active'"/></div>
                                <div><x-input-label :for="'sort_'.$option->id" :value="__('Order')"/><x-text-input :id="'sort_'.$option->id" name="sort_order" type="number" min="0" class="mt-1 block w-full" :value="$option->sort_order" :disabled="!$canManage || $option->status->value !== 'active'"/></div>
                                @if ($canManage && $option->status->value === 'active')<x-primary-button>{{ __('Save') }}</x-primary-button>@endif
                            </form>
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <x-badge :tone="$option->status->value === 'active' ? 'emerald' : ($option->status->value === 'deprecated' ? 'amber' : 'slate')">{{ ucfirst($option->status->value) }}</x-badge>
                                <x-badge :tone="$option->approval_status->value === 'approved' ? 'emerald' : 'amber'">{{ ucfirst(str_replace('_', ' ', $option->approval_status->value)) }}</x-badge>
                                @if ($canManage && $option->status->value === 'active' && $option->approval_status->value !== 'approved')
                                    <form method="POST" action="{{ route('catalog.attributes.options.approve', [$definition->uuid, $option->id]) }}">@csrf<button class="text-xs font-semibold text-emerald-700">{{ __('Approve') }}</button></form>
                                @endif
                            </div>
                            @if ($canManage)
                                <div class="mt-3 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-3">
                                    @if ($option->status->value === 'active' && $replacementOptions->isNotEmpty())
                                        <form method="POST" action="{{ route('catalog.attributes.options.deprecate', [$definition->uuid, $option->id]) }}" class="flex flex-wrap items-end gap-2">
                                            @csrf
                                            <div><x-input-label :for="'replacement_'.$option->id" :value="__('Replacement')"/><select id="replacement_{{ $option->id }}" name="replacement_option_id" required class="mt-1 rounded-lg border-slate-300 text-sm"><option value="">{{ __('Choose') }}</option>@foreach($replacementOptions as $replacement)<option value="{{ $replacement->id }}">{{ $replacement->label }}</option>@endforeach</select></div>
                                            <x-secondary-button>{{ __('Deprecate') }}</x-secondary-button>
                                        </form>
                                    @endif
                                    @if ($option->status->value === 'active')
                                        <form method="POST" action="{{ route('catalog.attributes.options.archive', [$definition->uuid, $option->id]) }}">@csrf<button class="text-xs font-semibold text-red-700">{{ __('Archive') }}</button></form>
                                    @elseif ($option->status->value === 'archived')
                                        <form method="POST" action="{{ route('catalog.attributes.options.restore', [$definition->uuid, $option->id]) }}">@csrf<button class="text-xs font-semibold text-indigo-700">{{ __('Restore') }}</button></form>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($canManage && $definition->status->value === 'active')
                    <form method="POST" action="{{ route('catalog.attributes.options.store', $definition->uuid) }}" class="mt-6 grid gap-3 border-t border-slate-200 pt-5 sm:grid-cols-[1fr_1fr_7rem_auto] sm:items-end">
                        @csrf
                        <div><x-input-label for="new_label" :value="__('New option label')"/><x-text-input id="new_label" name="label" class="mt-1 block w-full" required/></div>
                        <div><x-input-label for="new_code" :value="__('Code')"/><x-text-input id="new_code" name="code" class="mt-1 block w-full font-mono" required/></div>
                        <div><x-input-label for="new_sort" :value="__('Order')"/><x-text-input id="new_sort" name="sort_order" type="number" min="0" value="0" class="mt-1 block w-full" required/></div>
                        <x-primary-button>{{ __('Add') }}</x-primary-button>
                    </form>
                @endif
            @endif
        </section>
    </div>
</x-app-layout>
