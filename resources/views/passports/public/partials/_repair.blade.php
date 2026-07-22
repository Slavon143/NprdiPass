@php
    $repairable = $fields['repairable'] ?? null;
    $sparePartsAvailable = $fields['spare_parts_available'] ?? null;
@endphp

<div class="flex flex-wrap gap-4 mb-3 text-sm">
    @if($repairable !== null)
        <div class="flex items-center gap-2">
            <span class="font-semibold text-slate-700">Repairable:</span>
            <span class="@if($repairable) text-green-700 @else text-slate-500 @endif">{{ $repairable ? 'Yes' : 'No' }}</span>
        </div>
    @endif
    @if($sparePartsAvailable !== null)
        <div class="flex items-center gap-2">
            <span class="font-semibold text-slate-700">Spare Parts:</span>
            <span class="@if($sparePartsAvailable) text-green-700 @else text-slate-500 @endif">{{ $sparePartsAvailable ? 'Available' : 'Not available' }}</span>
        </div>
    @endif
    @if(!empty($fields['repairability_declaration']))
        <div class="flex items-center gap-2">
            <span class="font-semibold text-slate-700">Repair information:</span>
            <span class="text-slate-600">{{ $fields['repairability_declaration'] }}</span>
        </div>
    @endif
    @if(!empty($fields['repair_skill_level']))
        <div class="flex items-center gap-2">
            <span class="font-semibold text-slate-700">Skill level:</span>
            <span class="text-slate-600">{{ $fields['repair_skill_level'] }}</span>
        </div>
    @endif
</div>

@if(!empty($fields['required_tools']) && is_array($fields['required_tools']))
    <div class="mb-3 text-sm">
        <span class="font-semibold text-slate-700">Required tools:</span>
        <span class="text-slate-600">{{ implode(', ', $fields['required_tools']) }}</span>
    </div>
@endif

@if(!empty($fields['spare_parts_url']))
    <div class="mb-3 text-sm">
        <a href="{{ $fields['spare_parts_url'] }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline">Spare Parts Website</a>
    </div>
@endif

@if(!empty($fields['spare_parts']) && is_array($fields['spare_parts']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Spare Parts</h3>
        <ul class="text-sm text-slate-600 space-y-1">
            @foreach($fields['spare_parts'] as $part)
                <li>
                    <span class="font-medium">{{ $part['name'] ?? 'Spare part' }}</span>
                    @if(!empty($part['code'])) <span class="text-slate-400">{{ $part['code'] }}</span> @endif
                    @if(!empty($part['availability_status'])) <span> - {{ $part['availability_status'] }}</span> @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif

@if(!empty($fields['repair_instructions']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Repair Instructions</h3>
        <p class="text-slate-600 text-sm">{!! nl2br(e($fields['repair_instructions'])) !!}</p>
    </div>
@endif

@if(!empty($fields['disassembly_instructions']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Disassembly Instructions</h3>
        <p class="text-slate-600 text-sm">{!! nl2br(e($fields['disassembly_instructions'])) !!}</p>
    </div>
@endif

@if(!empty($fields['spare_parts_notes']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Spare Parts Notes</h3>
        <p class="text-slate-600 text-sm">{!! nl2br(e($fields['spare_parts_notes'])) !!}</p>
    </div>
@endif

@if(!empty($fields['service_information']))
    <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-700 mb-1">Service Information</h3>
        <p class="text-slate-600 text-sm">{!! nl2br(e($fields['service_information'])) !!}</p>
    </div>
@endif
