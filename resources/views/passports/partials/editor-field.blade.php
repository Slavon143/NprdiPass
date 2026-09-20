@props([
    'field',
    'sectionKey',
    'canManage',
    'hints' => [],
    'gateOffLabel' => null,
    'chips' => [],
    'showChips' => false,
    'jsonDefaults' => null,
    'jsonRequired' => [],
    'numberKind' => null,
    'rulePresenter',
    'countryOptions' => [],
])

@php
    $key = $field->key;
    $inputId = 'input-'.$sectionKey.'-'.$key;
    $fieldId = 'field-'.$sectionKey.'-'.$key;
    $disabled = $canManage ? '' : 'disabled';
@endphp

<div id="{{ $fieldId }}" class="scroll-mt-24">
    <label class="block text-sm font-semibold text-slate-900" for="{{ $inputId }}">
        {{ $field->label() }}
    </label>
    @if ($field->help() !== null)
        <p class="mt-1 text-xs leading-5 text-slate-500">{{ __($field->help()) }}</p>
    @endif
    @if ($showChips)
        <div class="mt-1 flex flex-wrap gap-1.5">
            @foreach ($chips as $locale => $complete)
                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold @if ($complete) bg-emerald-50 text-emerald-700 @else bg-slate-100 text-slate-500 @endif">
                    {{ strtoupper($locale) }} {{ $complete ? '✓' : __('Missing') }}
                </span>
            @endforeach
        </div>
    @endif

    @if ($field->type->value === 'boolean')
        <div class="mt-2 flex gap-6">
            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                <input type="radio" id="{{ $inputId }}-yes" name="{{ $fieldId }}" value="1"
                       :checked="values.{{ $key }} === true"
                       @change="values.{{ $key }} = true"
                       class="rounded-full border-slate-300 text-indigo-600 focus:ring-indigo-500" {{ $disabled }}>
                {{ __('Yes') }}
            </label>
            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                <input type="radio" id="{{ $inputId }}-no" name="{{ $fieldId }}" value="0"
                       :checked="values.{{ $key }} === false"
                       @change="values.{{ $key }} = false"
                       class="rounded-full border-slate-300 text-indigo-600 focus:ring-indigo-500" {{ $disabled }}>
                {{ __('No') }}
            </label>
        </div>
        @if ($gateOffLabel !== null)
            <p x-show="values.{{ $key }} === false" x-cloak class="mt-1.5 text-xs text-slate-500">{{ $gateOffLabel }}</p>
        @endif
    @elseif ($field->type->value === 'long_text')
        <textarea id="{{ $inputId }}" rows="4"
                  x-model="values.{{ $key }}"
                  @if ($field->maxLength) maxlength="{{ $field->maxLength }}" @endif
                  class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" {{ $disabled }}></textarea>
    @elseif ($field->type->value === 'integer' || $field->type->value === 'decimal')
        <input type="number" id="{{ $inputId }}"
               x-model="values.{{ $key }}"
               @if ($field->min !== null) min="{{ $field->min }}" @endif
               @if ($field->max !== null) max="{{ $field->max }}" @endif
               @if ($field->type->value === 'decimal') step="any" @endif
               class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" {{ $disabled }}>
    @elseif ($field->type->value === 'date')
        <input type="date" id="{{ $inputId }}"
               x-model="values.{{ $key }}"
               class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" {{ $disabled }}>
    @elseif ($field->type->value === 'email' || $field->type->value === 'url')
        <input type="{{ $field->type->value }}" id="{{ $inputId }}"
               x-model="values.{{ $key }}"
               class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" {{ $disabled }}>
    @elseif ($field->type->value === 'country_code')
        <select id="{{ $inputId }}"
                x-model="values.{{ $key }}"
                class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" {{ $disabled }}>
            <option value="">{{ __('Select country') }}</option>
            @foreach ($countryOptions as $code => $label)
                <option value="{{ $code }}">{{ $label }} ({{ $code }})</option>
            @endforeach
        </select>
    @elseif ($field->type->value === 'string_list')
        <textarea id="{{ $inputId }}" rows="3"
                  :value="joinedStringList(values.{{ $key }})"
                  @input="splitStringList('{{ $key }}', $event.target.value)"
                  class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" {{ $disabled }}></textarea>
        <p class="mt-1 text-xs text-slate-400">{{ __('One per line.') }}</p>
    @elseif ($field->type->value === 'material_list')
        <div class="mt-2 space-y-2" data-testid="material-list">
            <template x-for="(row, index) in values.{{ $key }}" :key="index">
                <div class="grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 md:grid-cols-12">
                    <label class="text-xs font-medium text-slate-600 md:col-span-3">
                        {{ __('Material name') }}
                        <input type="text" x-model="row.name" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                    </label>
                    <label class="text-xs font-medium text-slate-600 md:col-span-2">
                        {{ __('Product share (%)') }}
                        <input type="number" x-model="row.percentage" min="0" max="100" step="any" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                    </label>
                    <label class="text-xs font-medium text-slate-600 md:col-span-2">
                        {{ __('Recycled content (%)') }}
                        <input type="number" x-model="row.recycled_content_percentage" min="0" max="100" step="any" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                    </label>
                    <label class="text-xs font-medium text-slate-600 md:col-span-3">
                        {{ __('Country of origin') }}
                        <select x-model="row.country_of_origin" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                            <option value="">{{ __('Select country') }}</option>
                            @foreach ($countryOptions as $code => $label)
                                <option value="{{ $code }}">{{ $label }} ({{ $code }})</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="inline-flex items-center gap-1.5 self-end pb-2 text-xs text-slate-600 md:col-span-1">
                        <input type="checkbox" x-model="row.hazardous" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" {{ $disabled }}>
                        {{ __('Hazardous') }}
                    </label>
                    @if ($canManage)
                        <button type="button" @click="removeJsonRow('{{ $key }}', index)" class="text-xs font-semibold text-red-600 hover:underline md:col-span-1">{{ __('Remove') }}</button>
                    @endif
                </div>
            </template>
            @if ($canManage)
                <button type="button" @click="addJsonRow('{{ $key }}')" class="text-sm font-semibold text-indigo-600 hover:underline">+ {{ __('Add material') }}</button>
            @endif
            <p class="text-xs text-slate-500">{{ __('Product share values should total approximately 100%. Countries are saved as ISO 3166-1 alpha-2 codes.') }}</p>
        </div>
    @elseif ($field->type->value === 'json_list')
        @if ($key === 'spare_parts')
            <div class="mt-2 space-y-3" data-testid="spare-parts-editor">
                <template x-for="(row, index) in values.{{ $key }}" :key="index">
                    <div class="rounded-xl border border-slate-200 p-4">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <x-input-label value="{{ __('Part name') }} *" />
                                <input type="text" x-model="row.name" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                            </div>
                            <div>
                                <x-input-label value="{{ __('Part number') }}" />
                                <input type="text" x-model="row.code" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                            </div>
                            <div>
                                <x-input-label value="{{ __('Availability') }}" />
                                <input type="text" x-model="row.availability_status" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                            </div>
                            <div>
                                <x-input-label value="{{ __('Availability period') }}" />
                                <input type="text" x-model="row.availability_period" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                            </div>
                            <div class="sm:col-span-2">
                                <x-input-label value="{{ __('Order reference') }}" />
                                <input type="text" x-model="row.order_reference" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                            </div>
                            <div class="sm:col-span-2">
                                <x-input-label value="{{ __('Description') }}" />
                                <input type="text" x-model="row.description" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                            </div>
                        </div>
                        @if ($canManage)
                            <button type="button" @click="removeJsonRow('{{ $key }}', index)" class="mt-3 text-xs font-semibold text-red-600 hover:underline">{{ __('Remove') }}</button>
                        @endif
                    </div>
                </template>
                @if ($canManage)
                    <button type="button" @click="addJsonRow('{{ $key }}')" class="inline-flex items-center rounded-lg border border-indigo-200 px-3 py-1.5 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">+ {{ __('Add spare part') }}</button>
                @endif
            </div>
        @elseif ($key === 'environmental_metrics')
            <div class="mt-2 space-y-3" data-testid="environmental-metrics-editor">
                <template x-for="(row, index) in values.{{ $key }}" :key="index">
                    <div class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-12">
                        <div class="sm:col-span-3">
                            <x-input-label value="{{ __('Metric name') }}" />
                            <input type="text" x-model="row.label" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                        </div>
                        <div class="sm:col-span-3">
                            <x-input-label value="{{ __('Metric code') }} *" />
                            <input type="text" x-model="row.metric_code" class="mt-1 block w-full rounded-lg border-slate-300 font-mono text-sm" {{ $disabled }}>
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label value="{{ __('Value') }} *" />
                            <input type="text" x-model="row.value" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                        </div>
                        <div class="sm:col-span-3">
                            <x-input-label value="{{ __('Unit') }} *" />
                            <select x-model="row.unit" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                                <option value="">{{ __('Select unit') }}</option>
                                <option value="kg_co2e">kg CO₂e</option>
                                <option value="kwh">kWh</option>
                                <option value="l">L</option>
                                <option value="percent">%</option>
                                <option value="years">{{ __('years') }}</option>
                                <option value="cycles">{{ __('cycles') }}</option>
                                <option value="score">{{ __('score') }}</option>
                            </select>
                        </div>
                        <div class="flex items-end sm:col-span-1">
                            @if ($canManage)
                                <button type="button" @click="removeJsonRow('{{ $key }}', index)" class="pb-2 text-xs font-semibold text-red-600 hover:underline">{{ __('Remove') }}</button>
                            @endif
                        </div>
                    </div>
                </template>
                @if ($canManage)
                    <button type="button" @click="addJsonRow('{{ $key }}')" class="inline-flex items-center rounded-lg border border-indigo-200 px-3 py-1.5 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">+ {{ __('Add metric') }}</button>
                @endif
            </div>
        @elseif ($key === 'environmental_claim_records')
            <div class="mt-2 space-y-3" data-testid="environmental-claims-editor">
                <template x-for="(row, index) in values.{{ $key }}" :key="index">
                    <div class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-12">
                        <div class="sm:col-span-4">
                            <x-input-label value="{{ __('Claim') }} *" />
                            <input type="text" x-model="row.claim_text" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label value="{{ __('Type') }}" />
                            <input type="text" x-model="row.claim_type" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label value="{{ __('Review state') }}" />
                            <select x-model="row.review_state" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                                <option value="">{{ __('Not reviewed') }}</option>
                                <option value="provided">{{ __('Provided') }}</option>
                                <option value="needs_review">{{ __('Needs review') }}</option>
                                <option value="reviewed">{{ __('Reviewed') }}</option>
                                <option value="rejected">{{ __('Rejected') }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-3">
                            <x-input-label value="{{ __('Source reference') }}" />
                            <input type="text" x-model="row.source_reference" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                        </div>
                        <div class="flex items-end sm:col-span-1">
                            @if ($canManage)
                                <button type="button" @click="removeJsonRow('{{ $key }}', index)" class="pb-2 text-xs font-semibold text-red-600 hover:underline">{{ __('Remove') }}</button>
                            @endif
                        </div>
                    </div>
                </template>
                @if ($canManage)
                    <button type="button" @click="addJsonRow('{{ $key }}')" class="inline-flex items-center rounded-lg border border-indigo-200 px-3 py-1.5 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">+ {{ __('Add claim') }}</button>
                @endif
            </div>
        @elseif ($key === 'compliance_metadata')
            <div class="mt-2 space-y-3" data-testid="compliance-metadata-editor">
                <template x-for="(row, index) in values.{{ $key }}" :key="index">
                    <div class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-12">
                        <div class="sm:col-span-2">
                            <x-input-label value="{{ __('Topic code') }} *" />
                            <input type="text" x-model="row.topic_code" class="mt-1 block w-full rounded-lg border-slate-300 font-mono text-sm" {{ $disabled }}>
                        </div>
                        <div class="sm:col-span-4">
                            <x-input-label value="{{ __('Statement') }}" />
                            <input type="text" x-model="row.statement" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label value="{{ __('Market region') }}" />
                            <input type="text" x-model="row.market_region" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                        </div>
                        <div class="sm:col-span-3">
                            <x-input-label value="{{ __('Reference identifier') }}" />
                            <input type="text" x-model="row.reference_identifier" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                        </div>
                        <div class="flex items-end sm:col-span-1">
                            @if ($canManage)
                                <button type="button" @click="removeJsonRow('{{ $key }}', index)" class="pb-2 text-xs font-semibold text-red-600 hover:underline">{{ __('Remove') }}</button>
                            @endif
                        </div>
                    </div>
                </template>
                @if ($canManage)
                    <button type="button" @click="addJsonRow('{{ $key }}')" class="inline-flex items-center rounded-lg border border-indigo-200 px-3 py-1.5 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">+ {{ __('Add entry') }}</button>
                @endif
            </div>
        @elseif ($key === 'support_channels')
            <div class="mt-2 space-y-3" data-testid="support-channels-editor">
                <template x-for="(row, index) in values.{{ $key }}" :key="index">
                    <div class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-12">
                        <div class="sm:col-span-2">
                            <x-input-label value="{{ __('Type') }} *" />
                            <select x-model="row.type" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                                <option value="">{{ __('Select type') }}</option>
                                <option value="email">{{ __('Email') }}</option>
                                <option value="phone">{{ __('Phone') }}</option>
                                <option value="web">{{ __('Web') }}</option>
                                <option value="service_center">{{ __('Service center') }}</option>
                                <option value="other">{{ __('Other') }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-3">
                            <x-input-label value="{{ __('Value') }} *" />
                            <input type="text" x-model="row.value" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                        </div>
                        <div class="sm:col-span-3">
                            <x-input-label value="{{ __('Label') }}" />
                            <input type="text" x-model="row.label" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                        </div>
                        <div class="sm:col-span-3">
                            <x-input-label value="{{ __('Availability') }}" />
                            <input type="text" x-model="row.availability" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" {{ $disabled }}>
                        </div>
                        <div class="flex items-end sm:col-span-1">
                            @if ($canManage)
                                <button type="button" @click="removeJsonRow('{{ $key }}', index)" class="pb-2 text-xs font-semibold text-red-600 hover:underline">{{ __('Remove') }}</button>
                            @endif
                        </div>
                    </div>
                </template>
                @if ($canManage)
                    <button type="button" @click="addJsonRow('{{ $key }}')" class="inline-flex items-center rounded-lg border border-indigo-200 px-3 py-1.5 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">+ {{ __('Add channel') }}</button>
                @endif
            </div>
        @endif
    @else
        <input type="text" id="{{ $inputId }}"
               x-model="values.{{ $key }}"
               @if ($field->maxLength) maxlength="{{ $field->maxLength }}" @endif
               class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" {{ $disabled }}>
    @endif

    @if ($field->maxLength && ! in_array($field->type->value, ['string_list', 'material_list', 'json_list'], true))
        <p class="mt-1 text-xs text-slate-400">{{ __(':count characters maximum', ['count' => $field->maxLength]) }}</p>
    @endif

    @foreach ($hints as $hint)
        @php($isBlocker = $hint->severity->value === 'blocker')
        <div class="field-hint mt-2 flex items-start gap-1.5 text-xs @if ($isBlocker) text-red-700 @elseif ($hint->severity->value === 'warning') text-amber-700 @else text-blue-700 @endif" data-testid="field-hint">
            @if ($isBlocker)
                <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
            @else
                <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
            @endif
            <span>
                {{ $rulePresenter->message($hint) }}
                <span class="font-semibold">{{ $isBlocker ? __('Required before publication.') : __('Optional for publication; completing it improves readiness.') }}</span>
            </span>
        </div>
    @endforeach
</div>
