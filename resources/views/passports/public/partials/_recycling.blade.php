@if(!empty($fields['recycling_instructions']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Recycling Instructions</h3>
        <p class="text-slate-600 text-sm">{!! nl2br(e($fields['recycling_instructions'])) !!}</p>
    </div>
@endif

@if(!empty($fields['disposal_instructions']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Disposal Instructions</h3>
        <p class="text-slate-600 text-sm">{!! nl2br(e($fields['disposal_instructions'])) !!}</p>
    </div>
@endif

@if(!empty($fields['take_back_program']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Take-back Program</h3>
        <p class="text-slate-600 text-sm">{!! nl2br(e($fields['take_back_program'])) !!}</p>
    </div>
@endif

@if(array_key_exists('take_back_program_available', $fields) || !empty($fields['take_back_program_url']) || !empty($fields['take_back_program_scope']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Take-back Details</h3>
        <div class="text-slate-600 text-sm">
            @if(array_key_exists('take_back_program_available', $fields))
                <div>Available: {{ $fields['take_back_program_available'] ? 'Yes' : 'No' }}</div>
            @endif
            @if(!empty($fields['take_back_program_scope']))
                <div>Scope: {{ $fields['take_back_program_scope'] }}</div>
            @endif
            @if(!empty($fields['take_back_program_url']))
                <div><a href="{{ $fields['take_back_program_url'] }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline">Program information</a></div>
            @endif
        </div>
    </div>
@endif

@foreach(['disassembly_guidance' => 'Disassembly Guidance', 'sorting_guidance' => 'Sorting Guidance', 'hazard_notes' => 'Hazard Notes'] as $field => $label)
    @if(!empty($fields[$field]))
        <div class="mb-3">
            <h3 class="text-sm font-semibold text-slate-700 mb-1">{{ $label }}</h3>
            <p class="text-slate-600 text-sm">{!! nl2br(e($fields[$field])) !!}</p>
        </div>
    @endif
@endforeach

@if(!empty($fields['recycling_codes']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Recycling Codes</h3>
        <p class="text-slate-600 text-sm">{{ is_array($fields['recycling_codes']) ? implode(', ', $fields['recycling_codes']) : $fields['recycling_codes'] }}</p>
    </div>
@endif

@if(!empty($fields['waste_material_codes']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Waste/Material Codes</h3>
        <p class="text-slate-600 text-sm">{{ is_array($fields['waste_material_codes']) ? implode(', ', $fields['waste_material_codes']) : $fields['waste_material_codes'] }}</p>
    </div>
@endif
