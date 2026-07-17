<dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
    @if(!empty($fields['carbon_footprint_kg_co2e']))
        @php $cf = $fields['carbon_footprint_kg_co2e']; @endphp
        <div><dt class="font-semibold text-slate-700">Carbon Footprint</dt><dd class="text-slate-600">{{ rtrim(rtrim(number_format($cf, 2, '.', ''), '0'), '.') }} kg CO&#8322;e</dd></div>
    @endif
    @if(!empty($fields['recycled_content_percentage']))
        @php $rcp = $fields['recycled_content_percentage']; @endphp
        <div><dt class="font-semibold text-slate-700">Recycled Content</dt><dd class="text-slate-600">{{ rtrim(rtrim(number_format($rcp, 1, '.', ''), '0'), '.') }}%</dd></div>
    @endif
    @if(!empty($fields['expected_lifetime_years']))
        @php $lty = $fields['expected_lifetime_years']; @endphp
        <div><dt class="font-semibold text-slate-700">Expected Lifetime</dt><dd class="text-slate-600">{{ rtrim(rtrim(number_format($lty, 1, '.', ''), '0'), '.') }} years</dd></div>
    @endif
    @if(!empty($fields['energy_consumption_kwh']))
        @php $ec = $fields['energy_consumption_kwh']; @endphp
        <div><dt class="font-semibold text-slate-700">Energy Consumption</dt><dd class="text-slate-600">{{ rtrim(rtrim(number_format($ec, 2, '.', ''), '0'), '.') }} kWh</dd></div>
    @endif
</dl>

@php
    $claims = $fields['environmental_claims'] ?? [];
@endphp
@if(!empty($claims))
    <div class="mt-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Environmental Claims</h3>
        <ul class="list-disc list-inside text-sm text-slate-600 space-y-1">
            @foreach($claims as $claim)
                <li>{{ $claim }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if(!empty($fields['environmental_notes']))
    <div class="mt-3">
        <p class="text-slate-600 text-sm">{!! nl2br(e($fields['environmental_notes'])) !!}</p>
    </div>
@endif

<p class="text-xs text-slate-400 mt-3">Environmental information is supplied by the responsible company.</p>
