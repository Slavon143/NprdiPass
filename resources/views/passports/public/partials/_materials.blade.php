@php
    $materials = $fields['materials'] ?? [];
@endphp
@if(!empty($materials) && is_array($materials))
    <div class="space-y-2 mb-4">
        @foreach($materials as $material)
            @php
                $percentage = $material['percentage'] ?? null;
                $recycledPct = $material['recycled_content_percentage'] ?? null;
                $hazardous = $material['hazardous'] ?? false;
            @endphp
            <div class="flex items-center gap-2 text-sm">
                <span class="font-medium text-slate-700">{{ $material['name'] ?? 'Unknown' }}</span>
                @if($percentage !== null)
                    <span class="text-slate-500">&mdash; {{ rtrim(rtrim(number_format($percentage, 1, '.', ''), '0'), '.') }}%</span>
                @endif
                @if($recycledPct !== null && $recycledPct > 0)
                    <span class="text-green-600 text-xs">({{ rtrim(rtrim(number_format($recycledPct, 1, '.', ''), '0'), '.') }}% recycled)</span>
                @endif
                @if($hazardous)
                    <span class="text-red-600 text-xs font-semibold">&#9888; Hazardous</span>
                @endif
            </div>
        @endforeach
    </div>
@endif
@if(!empty($fields['composition_notes']))
    <p class="text-slate-600 text-sm">{!! nl2br(e($fields['composition_notes'])) !!}</p>
@endif
