<div class="recycling-badge mb-3" aria-label="Recycling and disposal information">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M9.707 14.707a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 1.414L7.414 9H15a1 1 0 110 2H7.414l2.293 2.293a1 1 0 010 1.414z" clip-rule="evenodd" />
    </svg>
    <span>Recycling &amp; Disposal</span>
</div>

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
