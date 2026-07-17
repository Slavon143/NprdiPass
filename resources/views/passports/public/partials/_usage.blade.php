@if(!empty($fields['usage_instructions']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Usage Instructions</h3>
        <p class="text-slate-600 text-sm">{!! nl2br(e($fields['usage_instructions'])) !!}</p>
    </div>
@endif

@if(!empty($fields['care_instructions']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Care Instructions</h3>
        <p class="text-slate-600 text-sm">{!! nl2br(e($fields['care_instructions'])) !!}</p>
    </div>
@endif

@if(!empty($fields['maintenance_instructions']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Maintenance Instructions</h3>
        <p class="text-slate-600 text-sm">{!! nl2br(e($fields['maintenance_instructions'])) !!}</p>
    </div>
@endif

@if(!empty($fields['storage_recommendations']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Storage Recommendations</h3>
        <p class="text-slate-600 text-sm">{!! nl2br(e($fields['storage_recommendations'])) !!}</p>
    </div>
@endif
