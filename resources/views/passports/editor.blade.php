<x-app-layout>
@php
    $rulePresenter = app(\App\Support\Passports\ReadinessRulePresenter::class);
    $localeRegistry = app(\App\Services\Passports\Localization\PassportLocaleRegistry::class);

    $payload = $editorData['payload'] ?? [];
    $enabledSections = array_values($payload['enabled_sections'] ?? []);
    $data = $payload['data'] ?? [];
    $translations = $payload['translations'] ?? [];
    $defaultLanguage = (string) ($editorData['default_language'] ?? 'sv');
    $enabledLanguages = array_values(array_filter((array) ($editorData['enabled_languages'] ?? [$defaultLanguage]), 'is_string'));
    $draftRevision = (int) ($editorData['draft_revision'] ?? 0);

    $countryOptions = [
        'SE' => 'Sweden', 'FI' => 'Finland', 'NO' => 'Norway', 'DK' => 'Denmark', 'IS' => 'Iceland',
        'EE' => 'Estonia', 'LV' => 'Latvia', 'LT' => 'Lithuania', 'PL' => 'Poland', 'DE' => 'Germany',
        'NL' => 'Netherlands', 'BE' => 'Belgium', 'FR' => 'France', 'ES' => 'Spain', 'IT' => 'Italy',
        'PT' => 'Portugal', 'AT' => 'Austria', 'CH' => 'Switzerland', 'CZ' => 'Czechia', 'SK' => 'Slovakia',
        'HU' => 'Hungary', 'RO' => 'Romania', 'BG' => 'Bulgaria', 'SI' => 'Slovenia', 'HR' => 'Croatia',
        'GR' => 'Greece', 'IE' => 'Ireland', 'GB' => 'United Kingdom', 'US' => 'United States',
        'CA' => 'Canada', 'MX' => 'Mexico', 'CN' => 'China', 'JP' => 'Japan', 'KR' => 'South Korea',
        'IN' => 'India', 'VN' => 'Vietnam', 'TH' => 'Thailand', 'TR' => 'Türkiye', 'UA' => 'Ukraine',
    ];

    $sectionDescriptions = [
        'identity' => __('Public name and description shown to customers.'),
        'manufacturer_and_operator' => __('Who made the product and who is responsible for it.'),
        'origin_and_traceability' => __('Where the product was made and how to trace it.'),
        'materials_and_composition' => __('What the product is made of.'),
        'safety' => __('Provide safety information shown in the public product passport.'),
        'usage_and_care' => __('How to use, maintain and store the product.'),
        'repair_and_spare_parts' => __('Repair options and spare part availability.'),
        'recycling_and_disposal' => __('How to recycle or dispose of the product.'),
        'environmental_information' => __('Environmental metrics and claims.'),
        'certifications_and_documents' => __('Certifications, compliance and linked documents.'),
        'support_and_contact' => __('Support channels and warranty information.'),
    ];

    $sectionRules = [];
    $fieldRules = [];

    foreach ($readiness->rules as $rule) {
        if ($rule->status->value !== 'failed' || $rule->section === null) {
            continue;
        }

        if ($rule->field !== null) {
            $fieldRules[$rule->section->value.'.'.$rule->field][] = $rule;
        } else {
            $sectionRules[$rule->section->value][$rule->severity->value][] = $rule;
        }
    }

    $sectionSummaries = [];
    foreach ($sectionKeys as $key) {
        $counts = ['blocker' => 0, 'warning' => 0, 'recommendation' => 0];
        foreach (($sectionRules[$key] ?? []) as $severity => $rules) {
            $counts[$severity] += count($rules);
        }
        foreach ($fieldRules as $fieldKey => $rules) {
            if (str_starts_with($fieldKey, $key.'.')) {
                foreach ($rules as $rule) {
                    $counts[$rule->severity->value]++;
                }
            }
        }
        $isEnabled = in_array($key, $enabledSections, true);
        $state = ! $isEnabled
            ? 'optional'
            : ($counts['blocker'] > 0 ? 'blocker' : (($counts['warning'] + $counts['recommendation']) > 0 ? 'issues' : 'complete'));
        $sectionSummaries[$key] = ['state' => $state, 'counts' => $counts];
    }

    $activeSection = (string) request('section');
    if (! array_key_exists($activeSection, $sections)) {
        $activeSection = 'identity';
    }
    $activeDef = $sections[$activeSection];
    $activeEnabled = in_array($activeSection, $enabledSections, true);

    $activeValues = [];
    foreach ($activeDef->fields as $field) {
        if ($field->translatable) {
            $activeValues[$field->key] = $translations[$defaultLanguage][$activeSection][$field->key]
                ?? $data[$activeSection][$field->key]
                ?? null;
        } else {
            $activeValues[$field->key] = $data[$activeSection][$field->key] ?? null;
        }
    }

    $sectionLocales = [];
    foreach ($enabledLanguages as $locale) {
        $sectionLocales[$locale] = $translations[$locale][$activeSection] ?? [];
    }

    $gateMap = [
        'repair_and_spare_parts' => [
            'repairable' => ['repairability_declaration', 'repair_skill_level', 'required_tools', 'estimated_repair_time_minutes', 'repair_instructions', 'disassembly_instructions'],
            'spare_parts_available' => ['spare_parts', 'spare_parts_url', 'spare_parts_notes'],
        ],
        'recycling_and_disposal' => [
            'take_back_program_available' => ['take_back_program', 'take_back_program_url', 'take_back_program_scope'],
        ],
        'support_and_contact' => [
            'warranty_available' => ['warranty_duration', 'warranty_duration_unit', 'warranty_url', 'warranty_summary', 'warranty_conditions', 'warranty_exclusions', 'warranty_claim_instructions'],
        ],
    ];
    $gateOffLabels = [
        'repairable' => __('This product is not repairable.'),
        'spare_parts_available' => __('Spare parts are not available.'),
        'take_back_program_available' => __('No take-back program is available.'),
        'warranty_available' => __('No warranty is provided.'),
    ];
    $activeGates = [];
    foreach (($gateMap[$activeSection] ?? []) as $gate => $gatedFields) {
        foreach ($gatedFields as $gatedField) {
            $activeGates[$gatedField] = $gate;
        }
    }

    $fieldsByKey = [];
    foreach ($activeDef->fields as $field) {
        $fieldsByKey[$field->key] = $field;
    }

    $groupedFieldKeys = [];
    foreach (($gateMap[$activeSection] ?? []) as $gate => $gatedFields) {
        foreach (array_merge([$gate], $gatedFields) as $groupedKey) {
            if (isset($fieldsByKey[$groupedKey])) {
                $groupedFieldKeys[$groupedKey] = array_merge([$gate], $gatedFields);
            }
        }
    }

    $orderedFields = [];
    $emittedFieldKeys = [];
    foreach ($activeDef->fields as $field) {
        if (isset($emittedFieldKeys[$field->key])) {
            continue;
        }
        foreach ($groupedFieldKeys[$field->key] ?? [$field->key] as $groupedKey) {
            if (isset($emittedFieldKeys[$groupedKey]) || ! isset($fieldsByKey[$groupedKey])) {
                continue;
            }
            $emittedFieldKeys[$groupedKey] = true;
            $orderedFields[] = $fieldsByKey[$groupedKey];
        }
    }

    $jsonRowDefaults = [
        'materials' => ['name' => '', 'percentage' => null, 'recycled_content_percentage' => null, 'country_of_origin' => '', 'hazardous' => false],
        'spare_parts' => ['name' => '', 'code' => '', 'availability_status' => '', 'availability_period' => '', 'order_reference' => '', 'description' => ''],
        'environmental_metrics' => ['label' => '', 'metric_code' => '', 'value' => '', 'unit' => '', 'verification_status' => ''],
        'environmental_claim_records' => ['claim_text' => '', 'claim_type' => '', 'review_state' => '', 'source_reference' => '', 'valid_from' => '', 'valid_to' => ''],
        'compliance_metadata' => ['topic_code' => '', 'statement' => '', 'market_region' => '', 'reference_identifier' => '', 'source_reference' => '', 'notes' => ''],
        'support_channels' => ['type' => '', 'label' => '', 'value' => '', 'availability' => ''],
    ];
    $jsonRequired = [
        'spare_parts' => ['name'],
        'environmental_metrics' => ['metric_code', 'value', 'unit'],
        'environmental_claim_records' => ['claim_text'],
        'compliance_metadata' => ['topic_code'],
        'support_channels' => ['type', 'value'],
    ];

    foreach ($activeDef->fields as $field) {
        if (isset($jsonRowDefaults[$field->key]) && ! is_array($activeValues[$field->key])) {
            $activeValues[$field->key] = [];
        }
        if ($field->type->value === 'material_list' && ! is_array($activeValues[$field->key])) {
            $activeValues[$field->key] = [];
        }
    }

    $translatableKeys = [];
    $numberKeys = [];
    foreach ($activeDef->fields as $field) {
        if ($field->translatable) {
            $translatableKeys[] = $field->key;
        }
        if ($field->type->value === 'integer') {
            $numberKeys[$field->key] = 'integer';
        } elseif ($field->type->value === 'decimal') {
            $numberKeys[$field->key] = 'decimal';
        }
    }

    $translationChips = [];
    $hasLocales = $activeDef->translatable && count($enabledLanguages) > 1;
    if ($hasLocales) {
        foreach ($activeDef->fields as $field) {
            if (! $field->translatable) {
                continue;
            }
            foreach ($enabledLanguages as $locale) {
                $value = $sectionLocales[$locale][$field->key] ?? null;
                $translationChips[$field->key][$locale] = is_array($value) ? $value !== [] : ($value !== null && $value !== '');
            }
        }
    }

    $activeFieldHints = [];
    foreach ($fieldRules as $fieldKey => $rules) {
        $prefix = $activeSection.'.';
        if (str_starts_with($fieldKey, $prefix)) {
            $activeFieldHints[substr($fieldKey, strlen($prefix))] = $rules;
        }
    }
    $activeSectionRules = $sectionRules[$activeSection] ?? [];

    $docRefs = array_values($payload['document_references'] ?? []);
    $docTitles = collect();
    if ($product->relationLoaded('documents')) {
        $docTitles = $product->documents->keyBy('uuid');
    }

    $localeLabels = [];
    foreach ($enabledLanguages as $locale) {
        $localeLabels[$locale] = $localeRegistry->get($locale)?->label ?? strtoupper($locale);
    }

    $passportStatusLabel = match (true) {
        $passport->isPublished() => __('Published'),
        $passport->isUnpublished() => __('Unpublished'),
        $passport->isArchived() => __('Archived'),
        default => __('Draft'),
    };

    $alpineConfig = [
        'sectionKey' => $activeSection,
        'canManage' => $canManage,
        'defaultLocale' => $defaultLanguage,
        'localeLabels' => $localeLabels,
        'hasLocales' => $hasLocales,
        'translatableKeys' => $translatableKeys,
        'fieldKeys' => array_map(fn ($field) => $field->key, $activeDef->fields),
        'values' => $activeValues,
        'locales' => $sectionLocales,
        'gates' => $activeGates,
        'gateOffLabels' => array_intersect_key($gateOffLabels, array_flip(array_keys($activeGates))),
        'jsonDefaults' => array_intersect_key($jsonRowDefaults, $activeValues),
        'jsonRequired' => array_intersect_key($jsonRequired, $activeValues),
        'numberKeys' => $numberKeys,
        'enabledSections' => $enabledSections,
        'expectedRevision' => $draftRevision,
        'productUuid' => $product->uuid,
        'saveUrl' => route('catalog.products.passport.sections.update', ['product' => $product->uuid, 'section' => $activeSection]),
        'settingsUrl' => route('catalog.products.passport.settings.update', ['product' => $product->uuid]),
        'csrfToken' => csrf_token(),
    ];
@endphp

<style>
    [id^="field-"]:target, [id^="section-"]:target, #sectionsContainer:target {
        scroll-margin-top: 6rem;
        outline: 3px solid rgb(251 191 36 / 0.75);
        outline-offset: 4px;
        border-radius: 0.5rem;
    }
</style>

<x-slot name="header">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }} / <a href="{{ route('catalog.products.index') }}" class="hover:underline">{{ __('Products') }}</a> / <a href="{{ route('catalog.products.show', $product->uuid) }}" class="hover:underline">{{ $product->name }}</a> / {{ __('Passport') }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ $product->name }}</h1>
            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-500">
                <span>{{ __('Digital Product Passport') }}</span>
                <span aria-hidden="true">·</span>
                <span>{{ $passportStatusLabel }}</span>
                @if ($draftRevision > 0)
                    <span aria-hidden="true">·</span>
                    <span id="editor-draft-revision">{{ __('Revision :revision', ['revision' => $draftRevision]) }}</span>
                @endif
                @if ($passport->isPublished())
                    <span aria-hidden="true">·</span>
                    <span>{{ __('Version :version', ['version' => $passport->currentPublishedVersion?->version_number ?? '—']) }}</span>
                @endif
            </p>
        </div>
        <div class="flex shrink-0 flex-col items-start gap-3 sm:items-end">
            <a href="{{ route('catalog.products.passport.readiness', $product->uuid) }}"
               id="editor-readiness-chip"
               class="inline-flex items-center rounded-full border px-3 py-1 text-sm font-semibold @if ($readiness->status->value === 'not_ready') border-red-200 bg-red-50 text-red-700 @elseif ($readiness->status->value === 'ready_with_warnings') border-amber-200 bg-amber-50 text-amber-700 @else border-emerald-200 bg-emerald-50 text-emerald-700 @endif">
                {{ $readiness->score }}% {{ __('complete') }}
                @if ($readiness->counts->blockers > 0)
                    · {{ trans_choice(':count blocking issue|:count blocking issues', $readiness->counts->blockers, ['count' => $readiness->counts->blockers]) }}
                @endif
            </a>
            <div class="flex flex-wrap items-center gap-2">
                @if ($draftRevision > 0)
                    <a href="{{ route('catalog.products.passport.preview', $product->uuid) }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Preview') }}</a>
                @endif
                <a href="{{ route('catalog.products.passport.readiness', $product->uuid) }}" class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('readiness.label') }}</a>
                @if ($canPublish && $passport->isDraft())
                    @if ($readiness->status->value === 'not_ready')
                        <button type="button" class="inline-flex cursor-not-allowed items-center rounded-lg bg-slate-300 px-4 py-2 text-sm font-semibold text-white" disabled>{{ __('Publish') }}</button>
                    @else
                        <a href="{{ route('catalog.products.passport.publish-confirm', $product->uuid) }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">{{ __('Publish') }}</a>
                    @endif
                @endif
                @if ($passport->isPublished())
                    <a href="{{ route('public.passports.show', $passport->public_id) }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg border border-emerald-300 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50">{{ __('Public page') }}</a>
                @endif
            </div>
        </div>
    </div>
</x-slot>

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8" data-testid="passport-editor-page">
    <div class="grid gap-6 lg:grid-cols-[19rem_minmax(0,1fr)]">
        {{-- Sidebar --}}
        <aside class="space-y-6">
            <nav aria-label="{{ __('Passport sections') }}" class="rounded-2xl border border-slate-200 bg-white shadow-sm" id="sectionsContainer" data-testid="passport-sections-nav">
                <p class="border-b border-slate-200 px-5 py-4 text-sm font-bold uppercase tracking-wider text-slate-500">{{ __('Passport sections') }}</p>
                <div class="p-2">
                    @foreach ($sectionKeys as $key)
                        @php
                            $summary = $sectionSummaries[$key];
                            $state = $summary['state'];
                            $counts = $summary['counts'];
                            $isActive = $key === $activeSection;
                        @endphp
                        <a href="?section={{ $key }}" data-section-nav="{{ $key }}"
                           class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition @if ($isActive) bg-indigo-50 font-semibold text-indigo-800 @else text-slate-700 hover:bg-slate-50 @endif">
                            @if ($state === 'blocker')
                                <svg class="h-4 w-4 shrink-0 text-red-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd" /></svg>
                            @elseif ($state === 'issues')
                                <svg class="h-4 w-4 shrink-0 text-amber-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                            @elseif ($state === 'complete')
                                <svg class="h-4 w-4 shrink-0 text-emerald-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg>
                            @else
                                <svg class="h-4 w-4 shrink-0 text-slate-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-13a.75.75 0 00-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 000-1.5h-3.25V5z" clip-rule="evenodd" /></svg>
                            @endif
                            <span class="min-w-0 flex-1 truncate">{{ __($sections[$key]->key->label()) }}</span>
                            <span class="shrink-0 text-xs text-slate-400" data-section-status="{{ $key }}">
                                @if (! in_array($key, $enabledSections, true))
                                    {{ __('Optional') }}
                                @elseif ($state === 'complete')
                                    {{ __('Complete') }}
                                @elseif ($counts['blocker'] > 0)
                                    {{ trans_choice(':count blocker|:count blockers', $counts['blocker'], ['count' => $counts['blocker']]) }}
                                    @if ($counts['warning'] + $counts['recommendation'] > 0)
                                        · {{ $counts['warning'] + $counts['recommendation'] }} {{ __('issues') }}
                                    @endif
                                @else
                                    {{ trans_choice(':count issue|:count issues', $counts['warning'] + $counts['recommendation'], ['count' => $counts['warning'] + $counts['recommendation']]) }}
                                @endif
                            </span>
                        </a>
                    @endforeach
                </div>
            </nav>

            <details class="rounded-2xl border border-slate-200 bg-white shadow-sm" data-testid="product-context">
                <summary class="flex cursor-pointer list-none items-center justify-between rounded-2xl px-5 py-4 hover:bg-slate-50">
                    <span class="text-sm font-bold uppercase tracking-wider text-slate-500">{{ __('Product context') }}</span>
                    <svg class="h-4 w-4 text-slate-400 transition group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                </summary>
                <dl class="space-y-2 border-t border-slate-200 px-5 py-4 text-sm">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Product') }}</dt>
                        <dd class="mt-0.5 font-medium text-slate-900">{{ $catalogContext['product_name'] ?? $product->name }}</dd>
                        @if (! empty($catalogContext['brand']))
                            <dd class="text-slate-600">{{ $catalogContext['brand'] }}</dd>
                        @endif
                    </div>
                    @if (! empty($catalogContext['default_variant']))
                        @foreach (['sku' => 'SKU', 'gtin' => 'GTIN', 'mpn' => 'MPN'] as $fieldKey => $fieldLabel)
                            @if (! empty($catalogContext['default_variant'][$fieldKey]))
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $fieldLabel }}</dt>
                                    <dd class="font-mono text-xs text-slate-700">{{ $catalogContext['default_variant'][$fieldKey] }}</dd>
                                </div>
                            @endif
                        @endforeach
                    @endif
                    @if (! empty($catalogContext['categories']))
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Category') }}</dt>
                            <dd class="text-right text-xs text-slate-700">{{ collect($catalogContext['categories'])->pluck('name')->take(2)->implode(', ') }}</dd>
                        </div>
                    @endif
                    <div class="pt-2">
                        <a href="{{ route('catalog.products.show', $product->uuid) }}" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">{{ __('Open product') }} &rarr;</a>
                    </div>
                </dl>
            </details>

            <details class="rounded-2xl border border-slate-200 bg-white shadow-sm" data-testid="passport-documents">
                <summary class="flex cursor-pointer list-none items-center justify-between rounded-2xl px-5 py-4 hover:bg-slate-50">
                    <span class="text-sm font-bold uppercase tracking-wider text-slate-500">{{ __('Documents') }}</span>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">{{ count($docRefs) }}</span>
                </summary>
                <div class="border-t border-slate-200 px-5 py-4 text-sm">
                    @if ($docRefs === [])
                        <p class="text-slate-600">{{ __('No passport documents added yet.') }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ __('Declarations, certificates and manuals can be included in the published passport.') }}</p>
                    @else
                        <ul class="space-y-2">
                            @foreach ($docRefs as $ref)
                                @php
                                    $doc = $docTitles->get($ref['document_uuid'] ?? null);
                                @endphp
                                <li class="flex items-center justify-between gap-3">
                                    <span class="truncate font-medium text-slate-900">{{ $doc?->currentVersion?->title ?? __('Document') }}</span>
                                    <span class="shrink-0 text-xs text-slate-400">{{ ucfirst((string) ($ref['role'] ?? 'other')) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <a href="{{ route('catalog.products.documents.index', $product->uuid) }}" class="mt-3 inline-flex text-sm font-semibold text-indigo-700 hover:text-indigo-900">{{ __('Manage documents') }} &rarr;</a>
                </div>
            </details>
        </aside>

        {{-- Active section panel --}}
        <script nonce="@cspNonce">window.passportSectionConfig = @json($alpineConfig);</script>
        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm" id="section-panel" data-testid="section-panel"
                 x-data="passportSection">
            <div class="flex flex-col gap-4 border-b border-slate-200 p-6 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($activeDef->core)
                            <span class="rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-0.5 text-xs font-semibold text-indigo-700" data-testid="section-required-badge">{{ __('Required section') }}</span>
                        @else
                            <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs font-semibold text-slate-600" data-testid="section-optional-badge">{{ __('Optional') }}</span>
                        @endif
                        @if ($hasLocales)
                            <select aria-label="{{ __('Language') }}" x-model="locale" @change="setLocale($event.target.value)" class="rounded-lg border-slate-300 py-1.5 pl-2.5 pr-7 text-xs font-semibold shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @foreach ($enabledLanguages as $locale)
                                    <option value="{{ $locale }}">{{ $localeLabels[$locale] }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                    <h2 class="mt-2 text-xl font-bold tracking-tight text-slate-900">{{ __($activeDef->key->label()) }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $sectionDescriptions[$activeSection] ?? __('Passport information for this area.') }}</p>
                </div>
                @if ($canManage && ! $activeDef->core && $activeEnabled)
                    <button type="button" class="inline-flex shrink-0 items-center rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50" @click="toggleSection(false)" data-testid="disable-section-button">{{ __('Disable section') }}</button>
                @endif
            </div>

            @if (! $activeEnabled)
                <div class="px-6 py-16 text-center" data-testid="section-disabled-state">
                    <svg class="mx-auto h-10 w-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <p class="mt-3 font-semibold text-slate-900">{{ __('This optional section is not enabled.') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">{{ __('Enable it only when this product needs this information in its public passport.') }}</p>
                    @if ($canManage)
                        <button type="button" class="mt-5 inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500" @click="toggleSection(true)" data-testid="enable-section-button">
                            {{ __('Enable section') }}
                        </button>
                    @endif
                </div>
            @else
                <div class="border-b border-slate-200 bg-slate-50/60 px-6 py-3" id="section-status-line" data-testid="section-status-line">
                    @if ($activeSectionRules !== [] || count($activeFieldHints) > 0)
                        @php
                            $totalBlockers = 0;
                            $totalWarnings = 0;
                            foreach ($activeSectionRules as $severity => $rules) {
                                if ($severity === 'blocker') { $totalBlockers += count($rules); }
                                else { $totalWarnings += count($rules); }
                            }
                            foreach ($activeFieldHints as $rules) {
                                foreach ($rules as $rule) {
                                    if ($rule->severity->value === 'blocker') { $totalBlockers++; } else { $totalWarnings++; }
                                }
                            }
                        @endphp
                        <p class="text-sm font-semibold @if ($totalBlockers > 0) text-red-700 @else text-amber-700 @endif">
                            @if ($totalBlockers > 0)
                                {{ trans_choice(':count blocking issue|:count blocking issues', $totalBlockers, ['count' => $totalBlockers]) }} · {{ trans_choice(':count suggested improvement|:count suggested improvements', $totalWarnings, ['count' => $totalWarnings]) }}
                            @else
                                {{ trans_choice(':count suggested improvement|:count suggested improvements', $totalWarnings, ['count' => $totalWarnings]) }}
                            @endif
                        </p>
                    @else
                        <p class="text-sm font-semibold text-emerald-700">{{ __('Up to date') }}</p>
                    @endif
                </div>

                <div class="space-y-6 p-6" data-testid="section-fields">
                    @foreach ($activeSectionRules['blocker'] ?? [] as $rule)
                        <div class="field-hint flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" data-testid="section-hint">
                            <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg>
                            <span>{{ $rulePresenter->message($rule) }} <span class="font-semibold">{{ __('Blocking issue — required before publication.') }}</span></span>
                        </div>
                    @endforeach
                    @foreach (array_merge($activeSectionRules['warning'] ?? [], $activeSectionRules['recommendation'] ?? []) as $rule)
                        <div class="field-hint flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" data-testid="section-hint">
                            <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                            <span>{{ $rulePresenter->message($rule) }} <span class="font-semibold">{{ __('Optional for publication; completing it improves readiness.') }}</span></span>
                        </div>
                    @endforeach

                    @foreach ($orderedFields as $field)
                        @php
                            $gateKey = $activeGates[$field->key] ?? null;
                            $hints = $activeFieldHints[$field->key] ?? [];
                            $chips = $translationChips[$field->key] ?? [];
                        @endphp
                        @if ($gateKey !== null)
                            <div x-show="values.{{ $gateKey }} === true" x-cloak class="space-y-6" data-gate-fields="{{ $gateKey }}">
                        @endif
                        @include('passports.partials.editor-field', [
                            'field' => $field,
                            'sectionKey' => $activeSection,
                            'canManage' => $canManage,
                            'hints' => $hints,
                            'gateOffLabel' => array_key_exists($field->key, $gateOffLabels) ? $gateOffLabels[$field->key] : null,
                            'chips' => $chips,
                            'showChips' => $hasLocales && $field->translatable,
                            'jsonDefaults' => $jsonRowDefaults[$field->key] ?? null,
                            'jsonRequired' => $jsonRequired[$field->key] ?? null,
                            'numberKind' => $numberKeys[$field->key] ?? null,
                            'rulePresenter' => $rulePresenter,
                            'countryOptions' => $countryOptions,
                        ])
                        @if ($gateKey !== null)
                            </div>
                        @endif
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-6 py-4" data-testid="section-footer">
                    <div class="flex items-center gap-3">
                        @if ($canManage)
                            <button type="button"
                                    class="inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition disabled:cursor-not-allowed disabled:opacity-60"
                                    :class="saving ? 'bg-indigo-400' : (saved && !dirty ? 'bg-emerald-600' : 'bg-indigo-600 hover:bg-indigo-500')"
                                    :disabled="saving || !dirty"
                                    @click="save()"
                                    data-testid="save-section-button">
                                <span x-show="!saving && saved && !dirty">{{ __('Saved') }}</span>
                                <span x-show="saving">{{ __('Saving…') }}</span>
                                <span x-show="!saving && !saved && !dirty">{{ __('Save changes') }}</span>
                                <span x-show="!saving && dirty">{{ __('Save changes') }}</span>
                            </button>
                            <p x-show="dirty && !saving" class="text-xs font-medium text-amber-600" x-cloak>{{ __('Unsaved changes') }}</p>
                        @else
                            <p class="text-sm italic text-slate-400">{{ __('Read-only') }}</p>
                        @endif
                    </div>
                    @if ($canManage && ! $activeDef->core)
                        <button type="button" class="inline-flex items-center rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50" @click="resetSection()" data-testid="reset-section-button">{{ __('Reset section') }}</button>
                    @endif
                </div>

                <div x-show="error !== null" x-cloak class="mx-6 mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert" data-testid="section-error-summary">
                    <p class="font-semibold">{{ __('Please correct the following fields:') }}</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <template x-for="line in errorLines" :key="line">
                            <li x-text="line"></li>
                        </template>
                    </ul>
                </div>
            @endif
        </section>
    </div>
</div>

<script nonce="@cspNonce">
(function () {
    window.passportSection = function (config) {
        return {
            locale: config.defaultLocale,
            values: config.values,
            locales: config.locales,
            translatableKeys: config.translatableKeys,
            fieldKeys: config.fieldKeys,
            gates: config.gates,
            gateOffLabels: config.gateOffLabels,
            jsonDefaults: config.jsonDefaults,
            jsonRequired: config.jsonRequired,
            numberKeys: config.numberKeys,
            enabledSections: config.enabledSections,
            canManage: config.canManage,
            sectionKey: config.sectionKey,
            productUuid: config.productUuid,
            saveUrl: config.saveUrl,
            settingsUrl: config.settingsUrl,
            csrfToken: config.csrfToken,
            expectedRevision: config.expectedRevision,
            hasLocales: config.hasLocales,
            dirty: false,
            saving: false,
            saved: false,
            error: null,
            errorLines: [],

            init() {
                for (const key of this.fieldKeys) {
                    if (this.jsonDefaults[key]) {
                        const raw = this.values[key];
                        this.values[key] = Array.isArray(raw)
                            ? raw.map((row) => Object.assign({}, this.jsonDefaults[key], row || {}))
                            : [];
                    }
                }

                this.$watch('values', () => this.markDirty(), { deep: true });

                window.addEventListener('beforeunload', (event) => {
                    if (this.dirty && !this.saving) {
                        event.preventDefault();
                        event.returnValue = '';
                    }
                });
            },

            markDirty() {
                if (this.saving) return;
                this.dirty = true;
                this.saved = false;
                this.error = null;
                this.errorLines = [];
            },

            setLocale(code) {
                if (code === this.locale) return;
                const snapshot = {};
                for (const key of this.translatableKeys) {
                    snapshot[key] = this.values[key];
                }
                this.locales[this.locale] = snapshot;
                this.locale = code;
                const incoming = this.locales[code] || {};
                for (const key of this.translatableKeys) {
                    this.values[key] = (key in incoming) ? incoming[key] : null;
                }
            },

            joinedStringList(value) {
                return Array.isArray(value) ? value.join('\n') : (value ?? '');
            },

            splitStringList(key, text) {
                const items = String(text || '')
                    .split(/[\r\n,]+/)
                    .map((item) => item.trim())
                    .filter(Boolean);
                this.values[key] = items.length ? items : null;
            },

            addJsonRow(key) {
                if (!Array.isArray(this.values[key])) {
                    this.values[key] = [];
                }
                this.values[key].push(Object.assign({}, this.jsonDefaults[key]));
            },

            removeJsonRow(key, index) {
                if (!Array.isArray(this.values[key])) return;
                if (this.values[key].length <= 1) {
                    this.values[key] = [Object.assign({}, this.jsonDefaults[key])];
                    return;
                }
                this.values[key].splice(index, 1);
            },

            toggleSection(enable) {
                if (this.saving) return;
                this.saving = true;
                const sections = new Set(this.enabledSections);
                if (enable) {
                    sections.add(this.sectionKey);
                } else {
                    sections.delete(this.sectionKey);
                }

                fetch(this.settingsUrl, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                    body: JSON.stringify({
                        settings: { enabled_sections: Array.from(sections) },
                        expected_revision: this.expectedRevision,
                    }),
                }).then((response) => {
                    if (response.ok) {
                        this.dirty = false;
                        window.location.reload();
                        return;
                    }
                    this.saving = false;
                    this.error = 'Unable to update the section.';
                }).catch(() => {
                    this.saving = false;
                    this.error = 'Unable to update the section.';
                });
            },

            resetSection() {
                if (!confirm('Reset this section? All its passport data will be cleared.')) return;
                if (this.saving) return;
                this.saving = true;

                fetch(this.saveUrl + '/reset', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                    body: JSON.stringify({ expected_revision: this.expectedRevision }),
                }).then((response) => {
                    if (response.ok) {
                        this.dirty = false;
                        window.location.reload();
                        return;
                    }
                    this.saving = false;
                    this.error = 'Unable to reset the section.';
                }).catch(() => {
                    this.saving = false;
                    this.error = 'Unable to reset the section.';
                });
            },

            buildPayload() {
                const payload = {};
                for (const key of this.fieldKeys) {
                    if (!(key in this.values)) continue;
                    if (this.gates[key] && this.values[this.gates[key]] !== true) continue;

                    let value = this.values[key];

                    if (typeof value === 'string' && value.trim() === '') {
                        value = null;
                    }

                    if (this.numberKeys[key] === 'integer' && value !== null && value !== undefined) {
                        const parsed = parseInt(value, 10);
                        value = Number.isNaN(parsed) ? null : String(parsed);
                    }

                    if (key === 'materials' && Array.isArray(value)) {
                        const rows = value
                            .filter((row) => String(row.name ?? '').trim() !== '')
                            .map((row) => {
                                const out = { name: String(row.name).trim() };
                                if (row.percentage !== '' && row.percentage !== null && row.percentage !== undefined) {
                                    out.percentage = parseFloat(row.percentage);
                                }
                                if (row.recycled_content_percentage !== '' && row.recycled_content_percentage !== null && row.recycled_content_percentage !== undefined) {
                                    out.recycled_content_percentage = parseFloat(row.recycled_content_percentage);
                                }
                                if (row.country_of_origin) out.country_of_origin = row.country_of_origin;
                                out.hazardous = row.hazardous === true;
                                return out;
                            });
                        value = rows.length ? rows : null;
                    } else if (this.jsonDefaults[key]) {
                        const required = this.jsonRequired[key] || [];
                        const rows = (Array.isArray(value) ? value : [])
                            .filter((row) => required.some((fieldKey) => String(row[fieldKey] ?? '').trim() !== ''));
                        value = rows.length ? rows : null;
                    }

                    if (Array.isArray(value) && value.length === 0) {
                        value = null;
                    }

                    payload[key] = value;
                }
                return payload;
            },

            async save() {
                if (this.saving || !this.dirty) return;
                this.saving = true;
                this.saved = false;
                this.error = null;
                this.errorLines = [];

                try {
                    const response = await fetch(this.saveUrl, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                        },
                        body: JSON.stringify({
                            section_payload: this.buildPayload(),
                            expected_revision: this.expectedRevision,
                            locale: this.locale,
                        }),
                    });

                    if (response.status === 409) {
                        const data = await response.json();
                        this.saving = false;
                        this.handleConflict(data.message || 'This passport was changed in another tab or by another user.');
                        return;
                    }

                    if (response.status === 422) {
                        const data = await response.json();
                        this.saving = false;
                        this.error = 'Validation failed.';
                        this.errorLines = this.flattenErrors(data.errors || {});
                        return;
                    }

                    if (response.status === 419) {
                        this.saving = false;
                        this.error = 'Your session has expired. Reload the page and try again.';
                        return;
                    }

                    if (response.status === 403 || response.status === 404) {
                        this.saving = false;
                        this.error = 'You do not have permission to edit this passport.';
                        return;
                    }

                    if (!response.ok) {
                        this.saving = false;
                        this.error = 'Unable to save changes. No data was intentionally discarded.';
                        return;
                    }

                    const data = await response.json();

                    if (data.data && data.data.draft_revision) {
                        this.expectedRevision = data.data.draft_revision;
                        window.updateEditorRevision(data.data.draft_revision);
                    }
                    if (data.data && data.data.readiness) {
                        window.updateEditorReadiness(data.data.readiness);
                    }

                    this.saving = false;
                    this.saved = true;
                    this.dirty = false;
                    this.hideLocalFeedback();
                } catch (e) {
                    this.saving = false;
                    this.error = 'Unable to save changes.';
                }
            },

            handleConflict(message) {
                this.error = message;
                if (confirm(message + '\n\nReload the latest version before saving again.\n\nPress OK to reload, Cancel to stay.')) {
                    this.dirty = false;
                    window.location.reload();
                }
            },

            flattenErrors(errors) {
                const lines = [];
                const walk = (value) => {
                    if (Array.isArray(value)) {
                        value.forEach((item) => {
                            if (typeof item === 'string') {
                                lines.push(item);
                            } else {
                                walk(item);
                            }
                        });
                    } else if (value && typeof value === 'object') {
                        Object.values(value).forEach(walk);
                    }
                };
                walk(errors);
                return lines.length ? lines : ['Validation failed.'];
            },

            hideLocalFeedback() {
                document.querySelectorAll('#section-panel .field-hint').forEach((el) => el.remove());
                const statusLine = document.getElementById('section-status-line');
                if (statusLine) {
                    statusLine.innerHTML = '<p class="text-sm font-semibold text-emerald-700">Up to date</p>';
                }
                const navRow = document.querySelector('[data-section-status="' + this.sectionKey + '"]');
                if (navRow) {
                    navRow.textContent = 'Complete';
                }
            },
        };
    };

    window.updateEditorRevision = function (revision) {
        const el = document.getElementById('editor-draft-revision');
        if (el) el.textContent = 'Revision ' + revision;
    };

    window.updateEditorReadiness = function (readiness) {
        const chip = document.getElementById('editor-readiness-chip');
        if (!chip) return;

        let text = readiness.score + '% complete';
        if (readiness.blockers > 0) {
            text += ' · ' + readiness.blockers + (readiness.blockers === 1 ? ' blocking issue' : ' blocking issues');
        }

        chip.textContent = text;
        chip.className = 'inline-flex items-center rounded-full border px-3 py-1 text-sm font-semibold ' + (
            readiness.blockers > 0
                ? 'border-red-200 bg-red-50 text-red-700'
                : (readiness.warnings > 0
                    ? 'border-amber-200 bg-amber-50 text-amber-700'
                    : 'border-emerald-200 bg-emerald-50 text-emerald-700')
        );
    };

    document.addEventListener('DOMContentLoaded', function () {
        const hash = window.location.hash;
        if (!hash) return;

        const navLinks = document.querySelectorAll('[data-section-nav]');
        let targetSection = null;

        navLinks.forEach(function (link) {
            const key = link.dataset.sectionNav;
            if (hash === '#section-' + key || hash.indexOf('#field-' + key + '-') === 0) {
                targetSection = key;
            }
        });

        if (targetSection === null) return;

        const current = new URLSearchParams(window.location.search).get('section');
        if (current === targetSection) return;

        const params = new URLSearchParams(window.location.search);
        params.set('section', targetSection);
        window.location.replace(window.location.pathname + '?' + params.toString() + hash);
    });
})();
</script>
</x-app-layout>
