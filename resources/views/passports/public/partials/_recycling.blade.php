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

@if(!empty($fields['recycling_codes']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Recycling Codes</h3>
        <p class="text-slate-600 text-sm">{{ is_array($fields['recycling_codes']) ? implode(', ', $fields['recycling_codes']) : $fields['recycling_codes'] }}</p>
    </div>
@endif
