<dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
    @if(!empty($fields['country_of_origin']))
        <div><dt class="font-semibold text-slate-700">Country of Origin</dt><dd class="text-slate-600">{{ $fields['country_of_origin'] }}</dd></div>
    @endif
    @if(!empty($fields['manufacturing_countries']))
        @php
            $manufacturingCountries = is_array($fields['manufacturing_countries'])
                ? array_values(array_filter($fields['manufacturing_countries'], fn ($country): bool => is_string($country) && preg_match('/^[A-Z]{2}$/', $country) === 1))
                : (is_string($fields['manufacturing_countries']) && preg_match('/^[A-Z]{2}$/', $fields['manufacturing_countries']) === 1 ? [$fields['manufacturing_countries']] : []);
        @endphp
        @if($manufacturingCountries !== [])
            <div><dt class="font-semibold text-slate-700">Manufacturing Countries</dt><dd class="text-slate-600">{{ implode(', ', $manufacturingCountries) }}</dd></div>
        @endif
    @endif
    @if(!empty($fields['production_date']))
        <div><dt class="font-semibold text-slate-700">Production Date</dt><dd class="text-slate-600">{{ $fields['production_date'] }}</dd></div>
    @endif
    @if(!empty($fields['batch_identification_instructions']))
        <div class="sm:col-span-2"><dt class="font-semibold text-slate-700">Batch Identification</dt><dd class="text-slate-600">{!! nl2br(e($fields['batch_identification_instructions'])) !!}</dd></div>
    @endif
    @if(!empty($fields['traceability_notes']))
        <div class="sm:col-span-2"><dt class="font-semibold text-slate-700">Traceability Notes</dt><dd class="text-slate-600">{!! nl2br(e($fields['traceability_notes'])) !!}</dd></div>
    @endif
</dl>
