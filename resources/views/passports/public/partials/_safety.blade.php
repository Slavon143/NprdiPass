<div class="safety-badge mb-3" role="alert" aria-label="Safety information">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
    </svg>
    <span>Safety Information</span>
</div>

@php
    $warnings = $fields['warnings'] ?? [];
    $hazards = $fields['hazards'] ?? [];
    $ppe = $fields['personal_protective_equipment'] ?? [];
@endphp

@if(!empty($warnings))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Warnings</h3>
        <ul class="list-disc list-inside text-sm text-slate-600 space-y-1">
            @foreach($warnings as $warning)
                <li>{{ $warning }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if(!empty($hazards))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Hazards</h3>
        <ul class="list-disc list-inside text-sm text-slate-600 space-y-1">
            @foreach($hazards as $hazard)
                <li>{{ $hazard }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if(!empty($ppe))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Personal Protective Equipment</h3>
        <ul class="list-disc list-inside text-sm text-slate-600 space-y-1">
            @foreach($ppe as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if(!empty($fields['storage_instructions']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Storage Instructions</h3>
        <p class="text-slate-600 text-sm">{!! nl2br(e($fields['storage_instructions'])) !!}</p>
    </div>
@endif

@if(!empty($fields['emergency_instructions']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Emergency Instructions</h3>
        <p class="text-slate-600 text-sm">{!! nl2br(e($fields['emergency_instructions'])) !!}</p>
    </div>
@endif

@if(!empty($fields['age_restrictions']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Age Restrictions</h3>
        <p class="text-slate-600 text-sm">{{ $fields['age_restrictions'] }}</p>
    </div>
@endif
