@extends('layouts.public-passport')

@section('content')
{{-- Product Hero --}}
<section id="overview" class="np-passport-hero mb-8" aria-labelledby="passport-title">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        @php
            $primaryMedia = $passport->media[0] ?? null;
        @endphp
        <div class="md:col-span-1">
            @if($primaryMedia !== null)
                <img src="{{ ($isPreview ?? false) ? route('catalog.media.content', $primaryMedia->mediaUuid) : route('public.passports.media.show', ['publicId' => $passport->passportPublicId, 'asset' => $primaryMedia->mediaUuid]) }}"
                     alt="{{ $primaryMedia->altText ?? $passport->productName }}"
                     class="w-full rounded-lg object-cover aspect-square"
                     loading="eager"
                     width="{{ $primaryMedia->width ?? 800 }}"
                     height="{{ $primaryMedia->height ?? 800 }}">
            @else
                <div class="w-full rounded-lg aspect-square bg-slate-100 flex items-center justify-center text-slate-400 text-sm">{{ __('No image available') }}</div>
            @endif
        </div>
        <div class="md:col-span-2">
            <h1 id="passport-title" class="text-2xl sm:text-3xl font-bold text-slate-900 mb-3">{{ $passport->productName }}</h1>
            @if($passport->productBrand !== null)
                <p class="text-sm font-semibold text-blue-600">{{ $passport->productBrand }}</p>
            @endif
            @if(isset($passport->sectionData['identity']['public_description']))
                <p class="text-slate-600 leading-relaxed">{{ $passport->sectionData['identity']['public_description'] }}</p>
            @endif
            @if($passport->manufacturerDisplayName !== null)
                <p class="mt-4 text-sm text-slate-700"><span class="font-semibold">{{ __('Manufactured by') }}</span> {{ $passport->manufacturerDisplayName }}</p>
            @endif
            @if($passport->countryOfOrigin !== null)
                <p class="mt-1 text-sm text-slate-500">{{ $passport->countryOfOrigin }}</p>
            @endif
        </div>
    </div>
</section>

<nav class="mb-10 rounded-xl border border-slate-200 bg-slate-50/70 px-5 py-4 sm:px-6" aria-label="{{ __('Passport sections') }}">
    <div class="mb-3 flex items-center justify-between gap-3">
        <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Sections') }}</h2>
        <span class="text-xs text-slate-400">{{ __('Digital Product Passport') }}</span>
    </div>
    @php
        $navigationLabels = [
            'identity' => 'Product',
            'manufacturer_and_operator' => 'Manufacturer & operator',
            'origin_and_traceability' => 'Origin',
            'materials_and_composition' => 'Materials',
            'safety' => 'Safety',
            'usage_and_care' => 'Use & care',
            'repair_and_spare_parts' => 'Repair',
            'recycling_and_disposal' => 'Recycling',
            'certifications_and_documents' => 'Documents',
            'environmental_information' => 'Environmental',
            'support_and_contact' => 'Support',
        ];
        $publicFieldKeys = [
            'manufacturer_and_operator' => ['manufacturer_display_name', 'manufacturer_country', 'manufacturer_email', 'manufacturer_website', 'responsible_operator_display_name', 'responsible_operator_country', 'responsible_operator_email', 'responsible_operator_website', 'responsible_operator_role', 'responsible_operator_address', 'responsible_operator_phone', 'responsible_operator_registration_id', 'responsible_operator_source', 'contact_notes'],
            'origin_and_traceability' => ['country_of_origin', 'manufacturing_countries', 'production_date', 'batch_identification_instructions', 'traceability_notes'],
            'materials_and_composition' => ['materials', 'composition_notes'],
            'safety' => ['warnings', 'hazards', 'personal_protective_equipment', 'storage_instructions', 'emergency_instructions', 'age_restrictions'],
            'usage_and_care' => ['usage_instructions', 'usage_steps', 'usage_warnings', 'care_instructions', 'care_steps', 'care_warnings', 'maintenance_instructions', 'storage_recommendations'],
            'repair_and_spare_parts' => ['repairable', 'repairability_declaration', 'repair_skill_level', 'required_tools', 'estimated_repair_time_minutes', 'spare_parts_available', 'spare_parts_url', 'spare_parts', 'repair_instructions', 'disassembly_instructions', 'spare_parts_notes', 'service_information'],
            'recycling_and_disposal' => ['recycling_instructions', 'disposal_instructions', 'take_back_program', 'take_back_program_available', 'take_back_program_url', 'take_back_program_scope', 'disassembly_guidance', 'sorting_guidance', 'hazard_notes', 'waste_material_codes', 'recycling_codes'],
            'environmental_information' => ['carbon_footprint_kg_co2e', 'recycled_content_percentage', 'recycled_content_calculation_method', 'recycled_content_source', 'expected_lifetime_years', 'energy_consumption_kwh', 'environmental_metrics', 'environmental_claims', 'environmental_claim_records', 'environmental_notes'],
            'certifications_and_documents' => ['certification_notes', 'compliance_summary', 'compliance_metadata'],
            'support_and_contact' => ['support_email', 'support_phone', 'support_url', 'support_channels', 'warranty_available', 'warranty_duration', 'warranty_duration_unit', 'warranty_url', 'warranty_summary', 'warranty_conditions', 'warranty_exclusions', 'warranty_claim_instructions', 'support_notes'],
        ];
        $hasPublicValue = static function (mixed $value): bool {
            return $value !== null && $value !== '' && $value !== [];
        };
        $publicSectionKeys = [];
        foreach ($passport->enabledSections as $sectionKey) {
            if ($sectionKey === 'identity' || $sectionKey === 'certifications_and_documents') {
                $publicSectionKeys[] = $sectionKey;
                continue;
            }
            $fields = $passport->sectionData[$sectionKey] ?? [];
            foreach ($publicFieldKeys[$sectionKey] ?? [] as $fieldKey) {
                if (array_key_exists($fieldKey, $fields) && $hasPublicValue($fields[$fieldKey])) {
                    $publicSectionKeys[] = $sectionKey;
                    break;
                }
            }
        }
    @endphp
    <ul class="grid grid-cols-1 gap-x-8 sm:grid-cols-2 lg:grid-cols-3" role="list">
        <li><a href="#overview" class="flex items-center gap-2 border-b border-slate-200/80 py-2 text-sm font-medium text-slate-700 transition hover:text-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"><span class="text-slate-300" aria-hidden="true">&rarr;</span>{{ __('Overview') }}</a></li>
        <li><a href="#quick-facts" class="flex items-center gap-2 border-b border-slate-200/80 py-2 text-sm font-medium text-slate-700 transition hover:text-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"><span class="text-slate-300" aria-hidden="true">&rarr;</span>{{ __('Quick facts') }}</a></li>
        @foreach($publicSectionKeys as $sectionKey)
            @if(isset($navigationLabels[$sectionKey]))
                <li><a href="#{{ $sectionKey === 'certifications_and_documents' ? 'documents' : 'section-'.$sectionKey }}" class="flex items-center gap-2 border-b border-slate-200/80 py-2 text-sm font-medium text-slate-700 transition hover:text-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"><span class="text-slate-300" aria-hidden="true">&rarr;</span>{{ $navigationLabels[$sectionKey] }}</a></li>
            @endif
        @endforeach
        <li><a href="#publication-details" class="flex items-center gap-2 border-b border-slate-200/80 py-2 text-sm font-medium text-slate-700 transition hover:text-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"><span class="text-slate-300" aria-hidden="true">&rarr;</span>{{ __('Publication') }}</a></li>
    </ul>
</nav>

{{-- Language Selector --}}
@if(isset($passport->enabledLocales) && count($passport->enabledLocales) > 1)
<section class="mb-6" aria-labelledby="language-selector-title">
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <h2 id="language-selector-title" class="text-sm text-slate-500 font-medium">{{ __('Language') }}</h2>
        @foreach($passport->enabledLocales as $locale)
            @php
                $isActive = ($passport->requestedLocale ?? $passport->defaultLanguage) === $locale;
                $localeUrl = ($isPreview ?? false)
                    ? route('catalog.products.passport.preview', ['product' => $previewProductUuid, 'lang' => $locale])
                    : url('p/'.$passport->passportPublicId.($locale !== $passport->defaultLanguage ? '?lang='.$locale : ''));
            @endphp
            <a href="{{ $localeUrl }}"
               @class([
                   'px-3 py-1 rounded-full text-sm font-medium transition-colors',
                   'bg-blue-600 text-white' => $isActive,
                   'bg-slate-100 text-slate-600 hover:bg-slate-200' => !$isActive,
               ])
               hreflang="{{ $locale }}">
                {{ \App\Support\UiLocale::LABELS[$locale] ?? strtoupper($locale) }}
            </a>
        @endforeach
    </div>
</section>

@endif

{{-- Fallback Notice --}}
@if(isset($passport->isFallback) && $passport->isFallback)
<section class="mb-6 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
    Some information is shown in {{ $passport->defaultLanguage === 'en' ? 'English' : 'the default language' }} because a translation is not available in the selected language.
</section>
@endif

{{-- Gallery --}}
@if(count($passport->media) > 1)
    <section class="mb-8" aria-label="Product gallery">
        <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-3">
            @foreach($passport->media as $index => $mediaItem)
                @if($index === 0) @continue @endif
                <a href="{{ ($isPreview ?? false) ? route('catalog.media.content', $mediaItem->mediaUuid) : route('public.passports.media.show', ['publicId' => $passport->passportPublicId, 'asset' => $mediaItem->mediaUuid]) }}"
                   target="_blank" rel="noopener"
                   class="block">
                    <img src="{{ ($isPreview ?? false) ? route('catalog.media.content', $mediaItem->mediaUuid) : route('public.passports.media.show', ['publicId' => $passport->passportPublicId, 'asset' => $mediaItem->mediaUuid]) }}"
                         alt="{{ $mediaItem->altText ?? $passport->productName }}"
                         class="w-full rounded-lg object-cover aspect-square"
                         loading="lazy">
                </a>
            @endforeach
        </div>
    </section>
@endif

{{-- Quick Facts --}}
<section id="quick-facts" class="mb-8" aria-labelledby="quick-facts-title">
    <h2 id="quick-facts-title" class="text-lg font-semibold mb-3">{{ __('Quick Facts') }}</h2>
    <dl class="bg-slate-50 rounded-lg p-4">
        @if($passport->defaultVariantGtin !== null)
            <div class="quick-fact"><dt>{{ __('GTIN') }}</dt><dd>{{ $passport->defaultVariantGtin }}</dd></div>
        @endif
        @if($passport->defaultVariantSku !== null || $passport->defaultVariantMpn !== null)
            <div class="quick-fact"><dt>{{ __('Model / SKU') }}</dt><dd>{{ $passport->defaultVariantSku ?? $passport->defaultVariantMpn }}</dd></div>
        @endif
        @if($passport->countryOfOrigin !== null)
            <div class="quick-fact"><dt>{{ __('Country of Origin') }}</dt><dd>{{ $passport->countryOfOrigin }}</dd></div>
        @endif
        @if($passport->productCategory !== null)
            <div class="quick-fact"><dt>{{ __('Product Category') }}</dt><dd>{{ $passport->productCategory }}</dd></div>
        @endif
        @if($passport->productManufacturer !== null)
            <div class="quick-fact"><dt>{{ __('Manufacturer') }}</dt><dd>{{ $passport->productManufacturer }}</dd></div>
        @endif
        <div class="quick-fact"><dt>{{ __('Passport Version') }}</dt><dd>{{ ($isPreview ?? false) ? __('Draft preview') : __('Version :version', ['version' => $passport->versionNumber]) }}</dd></div>
        @if($passport->publishedAt !== '')
            @php
                $pubDate = \Carbon\CarbonImmutable::parse($passport->publishedAt);
            @endphp
            <div class="quick-fact"><dt>{{ __('Published') }}</dt><dd>{{ \App\Support\LocalizedDisplay::date($pubDate) }}</dd></div>
        @endif
    </dl>
</section>

{{-- Product Identity --}}
@if(in_array('identity', $passport->enabledSections))
    @php $s = $passport->sectionData['identity'] ?? []; @endphp
    <section class="passport-section" aria-labelledby="section-identity">
        <h2 id="section-identity">{{ __('Product Identity') }}</h2>
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
            <div><dt class="font-semibold text-slate-700">{{ __('Product name') }}</dt><dd class="text-slate-600">{{ $passport->productName }}</dd></div>
            @if(!empty($s['public_name']) && $s['public_name'] !== $passport->productName)
                <div><dt class="font-semibold text-slate-700">{{ __('Display name') }}</dt><dd class="text-slate-600">{{ $s['public_name'] }}</dd></div>
            @endif
            @if($passport->defaultVariantGtin !== null)<div><dt class="font-semibold text-slate-700">{{ __('GTIN') }}</dt><dd class="text-slate-600">{{ $passport->defaultVariantGtin }}</dd></div>@endif
            @if($passport->defaultVariantSku !== null)<div><dt class="font-semibold text-slate-700">{{ __('SKU') }}</dt><dd class="text-slate-600">{{ $passport->defaultVariantSku }}</dd></div>@endif
            @if($passport->defaultVariantMpn !== null)<div><dt class="font-semibold text-slate-700">{{ __('Model') }}</dt><dd class="text-slate-600">{{ $passport->defaultVariantMpn }}</dd></div>@endif
            @if($passport->productBrand !== null)<div><dt class="font-semibold text-slate-700">{{ __('Brand') }}</dt><dd class="text-slate-600">{{ $passport->productBrand }}</dd></div>@endif
            @if($passport->productCategory !== null)<div><dt class="font-semibold text-slate-700">{{ __('Product category') }}</dt><dd class="text-slate-600">{{ $passport->productCategory }}</dd></div>@endif
        </dl>
        @if(!empty($s['public_description'])) <p class="text-slate-600 text-sm mt-4">{!! nl2br(e($s['public_description'])) !!}</p> @endif
    </section>
@endif

{{-- Variants --}}
@php
    $variants = $passport->sectionData['_variants'] ?? [];
    $variantList = collect($passport->sectionData)->get('_variants', []) ?: [];
@endphp

{{-- DPP Sections --}}
@foreach($publicSectionKeys as $sectionKey)
    @php
        if ($sectionKey === 'identity') continue;
        $sectionLabel = $passport->sectionLabels[$sectionKey] ?? $sectionKey;
        $fields = $passport->sectionData[$sectionKey] ?? [];
        $hasContent = !empty(array_filter($fields, fn($v) => $v !== null && $v !== '' && $v !== []));

        if($sectionKey === 'certifications_and_documents') {
            $hasContent = true;
        }
    @endphp

    @if(!$hasContent)
        @continue
    @endif

    <section @class(['passport-section']) @if($sectionKey === 'certifications_and_documents') id="documents" @endif aria-labelledby="section-{{ $sectionKey }}">
        <h2 id="section-{{ $sectionKey }}">{{ __($sectionLabel) }}</h2>

        @switch($sectionKey)
            @case('manufacturer_and_operator')
                @include('passports.public.partials._manufacturer', ['fields' => $fields])
                @break

            @case('origin_and_traceability')
                @include('passports.public.partials._origin', ['fields' => $fields])
                @break

            @case('materials_and_composition')
                @include('passports.public.partials._materials', ['fields' => $fields])
                @break

            @case('safety')
                @include('passports.public.partials._safety', ['fields' => $fields])
                @break

            @case('usage_and_care')
                @include('passports.public.partials._usage', ['fields' => $fields])
                @break

            @case('repair_and_spare_parts')
                @include('passports.public.partials._repair', ['fields' => $fields])
                @break

            @case('recycling_and_disposal')
                @include('passports.public.partials._recycling', ['fields' => $fields])
                @break

            @case('environmental_information')
                @include('passports.public.partials._environmental', ['fields' => $fields])
                @break

            @case('certifications_and_documents')
                @include('passports.public.partials._documents', ['passport' => $passport, 'fields' => $fields])
                @break

            @case('support_and_contact')
                @include('passports.public.partials._support', ['fields' => $fields])
                @break

            @default
                {{-- Unsupported sections are intentionally not rendered publicly. --}}
        @endswitch
    </section>
@endforeach

{{-- Publication Details --}}
<section id="publication-details" class="mt-10 pt-6 border-t border-slate-200" aria-label="Publication details">
    <div class="text-xs text-slate-400 space-y-1">
        <div><span class="font-semibold text-slate-600">{{ __('Passport ID') }}</span> {{ $passport->passportPublicId }}</div>
        <div><span class="font-semibold text-slate-600">{{ __('Version') }}</span> {{ $passport->versionNumber }}
            @if($passport->publishedAt !== '')
                &middot; {{ __('Published') }} {{ \App\Support\LocalizedDisplay::date(\Carbon\CarbonImmutable::parse($passport->publishedAt)) }}
            @endif
        </div>
        <div><span class="font-semibold text-slate-600">{{ __('Passport level') }}</span> {{ __('Model ·') }} <span class="font-semibold text-slate-600">{{ __('Status') }}</span> {{ ($isPreview ?? false) ? __('Draft preview') : __('Published') }}</div>
        <div>{{ __('Language') }}: {{ strtoupper($passport->requestedLocale ?? $passport->defaultLanguage) }}</div>
        @if(!empty($passport->sectionData['manufacturer_and_operator']['responsible_operator_display_name']))
            <div>{{ __('Responsible Operator') }}: {{ $passport->sectionData['manufacturer_and_operator']['responsible_operator_display_name'] }}</div>
        @endif
    </div>
</section>
@stop
