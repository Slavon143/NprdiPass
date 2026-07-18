@extends('layouts.public-passport')

@section('content')
{{-- Product Hero --}}
<section class="mb-8">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        @php
            $primaryMedia = $passport->media[0] ?? null;
        @endphp
        <div class="md:col-span-1">
            @if($primaryMedia !== null)
                <img src="{{ route('public.passports.media.show', ['publicId' => $passport->passportPublicId, 'asset' => $primaryMedia->mediaUuid]) }}"
                     alt="{{ $primaryMedia->altText ?? $passport->productName }}"
                     class="w-full rounded-lg object-cover aspect-square"
                     loading="eager"
                     width="{{ $primaryMedia->width ?? 800 }}"
                     height="{{ $primaryMedia->height ?? 800 }}">
            @else
                <div class="w-full rounded-lg aspect-square bg-slate-100 flex items-center justify-center text-slate-400 text-sm">No image available</div>
            @endif
        </div>
        <div class="md:col-span-2">
            @if($passport->productBrand !== null)
                <div class="text-sm font-semibold text-blue-600 mb-1">{{ $passport->productBrand }}</div>
            @endif
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 mb-3">{{ $passport->productName }}</h1>
            @if(isset($passport->sectionData['identity']['public_description']))
                <p class="text-slate-600 leading-relaxed">{{ $passport->sectionData['identity']['public_description'] }}</p>
            @endif
            <div class="flex flex-wrap gap-3 mt-4 text-sm">
                @if($passport->productBrand !== null)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-blue-50 text-blue-700">{{ $passport->productBrand }}</span>
                @endif
                @if($passport->productManufacturer !== null)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-700">{{ $passport->productManufacturer }}</span>
                @endif
                @if($passport->productCategory !== null)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-700">{{ $passport->productCategory }}</span>
                @endif
            </div>
        </div>
    </div>
</section>

{{-- Language Selector --}}
@if(isset($passport->enabledLocales) && count($passport->enabledLocales) > 1)
<section class="mb-6">
    <div class="flex items-center gap-3 text-sm">
        <span class="text-slate-500 font-medium">Language</span>
        @foreach($passport->enabledLocales as $locale)
            @php
                $isActive = ($passport->requestedLocale ?? $passport->defaultLanguage) === $locale;
                $localeUrl = url('p/'.$passport->passportPublicId.($locale !== $passport->defaultLanguage ? '?lang='.$locale : ''));
            @endphp
            <a href="{{ $localeUrl }}"
               @class([
                   'px-3 py-1 rounded-full text-sm font-medium transition-colors',
                   'bg-blue-600 text-white' => $isActive,
                   'bg-slate-100 text-slate-600 hover:bg-slate-200' => !$isActive,
               ])
               hreflang="{{ $locale }}">
                {{ $locale === 'en' ? 'English' : ($locale === 'sv' ? 'Svenska' : strtoupper($locale)) }}
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
                <a href="{{ route('public.passports.media.show', ['publicId' => $passport->passportPublicId, 'asset' => $mediaItem->mediaUuid]) }}"
                   target="_blank" rel="noopener"
                   class="block">
                    <img src="{{ route('public.passports.media.show', ['publicId' => $passport->passportPublicId, 'asset' => $mediaItem->mediaUuid]) }}"
                         alt="{{ $mediaItem->altText ?? $passport->productName }}"
                         class="w-full rounded-lg object-cover aspect-square"
                         loading="lazy">
                </a>
            @endforeach
        </div>
    </section>
@endif

{{-- Quick Facts --}}
<section class="mb-8">
    <h2 class="text-lg font-semibold mb-3">Quick Facts</h2>
    <dl class="bg-slate-50 rounded-lg p-4">
        @if($passport->defaultVariantGtin !== null)
            <div class="quick-fact"><dt>GTIN</dt><dd>{{ $passport->defaultVariantGtin }}</dd></div>
        @endif
        @if($passport->defaultVariantSku !== null || $passport->defaultVariantMpn !== null)
            <div class="quick-fact"><dt>Model / SKU</dt><dd>{{ $passport->defaultVariantSku ?? $passport->defaultVariantMpn }}</dd></div>
        @endif
        @if($passport->countryOfOrigin !== null)
            <div class="quick-fact"><dt>Country of Origin</dt><dd>{{ $passport->countryOfOrigin }}</dd></div>
        @endif
        @if($passport->manufacturerDisplayName !== null)
            <div class="quick-fact"><dt>Manufacturer</dt><dd>{{ $passport->manufacturerDisplayName }}</dd></div>
        @endif
        <div class="quick-fact"><dt>Passport Version</dt><dd>Version {{ $passport->versionNumber }}</dd></div>
        @if($passport->publishedAt !== '')
            @php
                $pubDate = \Carbon\CarbonImmutable::parse($passport->publishedAt);
            @endphp
            <div class="quick-fact"><dt>Published</dt><dd>{{ $pubDate->format('d F Y') }}</dd></div>
        @endif
    </dl>
</section>

{{-- Product Identity --}}
@if(in_array('identity', $passport->enabledSections))
    @php $s = $passport->sectionData['identity'] ?? []; @endphp
    @if(!empty(array_filter($s, fn($v) => $v !== null && $v !== '')))
    <section class="passport-section">
        <h2>{{ $passport->sectionLabels['identity'] ?? 'Identity' }}</h2>
        @if(!empty($s['public_name']) && $s['public_name'] !== $passport->productName) <p class="text-slate-700">{{ $s['public_name'] }}</p> @endif
        @if(!empty($s['public_description'])) <p class="text-slate-600 text-sm mt-1">{!! nl2br(e($s['public_description'])) !!}</p> @endif
    </section>
    @endif
@endif

{{-- Variants --}}
@php
    $variants = $passport->sectionData['_variants'] ?? [];
    $variantList = collect($passport->sectionData)->get('_variants', []) ?: [];
@endphp

{{-- DPP Sections --}}
@foreach($passport->enabledSections as $sectionKey)
    @php
        if ($sectionKey === 'identity') continue;
        $sectionLabel = $passport->sectionLabels[$sectionKey] ?? $sectionKey;
        $fields = $passport->sectionData[$sectionKey] ?? [];
        $hasContent = !empty(array_filter($fields, fn($v) => $v !== null && $v !== '' && $v !== []));

        if($sectionKey === 'certifications_and_documents') {
            $hasContent = $hasContent || count($passport->documents) > 0;
        }
    @endphp

    @if(!$hasContent)
        @continue
    @endif

    <section class="passport-section">
        <h2>{{ $sectionLabel }}</h2>

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
                <div class="text-slate-600 text-sm">
                    @foreach($fields as $fieldKey => $value)
                        @if($value !== null && $value !== '')
                            <div class="mb-2">
                                <span class="font-medium text-slate-700">{{ ucwords(str_replace('_', ' ', $fieldKey)) }}:</span>
                                <span>
                                    @if(is_array($value))
                                        {{ implode(', ', $value) }}
                                    @else
                                        {!! nl2br(e((string) $value)) !!}
                                    @endif
                                </span>
                            </div>
                        @endif
                    @endforeach
                </div>
        @endswitch
    </section>
@endforeach

{{-- Publication Details --}}
<section class="mt-10 pt-6 border-t border-slate-200" aria-label="Publication details">
    <div class="text-xs text-slate-400 space-y-1">
        <div>Passport Version {{ $passport->versionNumber }}
            @if($passport->publishedAt !== '')
                &middot; Published {{ \Carbon\CarbonImmutable::parse($passport->publishedAt)->format('d F Y') }}
            @endif
        </div>
        <div>Language: {{ strtoupper($passport->defaultLanguage) }}</div>
        @if($passport->manufacturerDisplayName !== null)
            <div>Responsible company: {{ $passport->manufacturerDisplayName }}</div>
        @endif
    </div>
</section>
@stop
