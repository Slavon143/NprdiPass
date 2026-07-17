<dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
    @if(!empty($fields['support_email']))
        <div><dt class="font-semibold text-slate-700">Support Email</dt><dd class="text-slate-600"><a href="mailto:{{ $fields['support_email'] }}" class="text-blue-600 hover:underline">{{ $fields['support_email'] }}</a></dd></div>
    @endif
    @if(!empty($fields['support_phone']))
        <div><dt class="font-semibold text-slate-700">Support Phone</dt><dd class="text-slate-600"><a href="tel:{{ $fields['support_phone'] }}" class="text-blue-600 hover:underline">{{ $fields['support_phone'] }}</a></dd></div>
    @endif
    @if(!empty($fields['support_url']))
        <div><dt class="font-semibold text-slate-700">Support Website</dt><dd class="text-slate-600"><a href="{{ $fields['support_url'] }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline">{{ $fields['support_url'] }}</a></dd></div>
    @endif
    @if(!empty($fields['warranty_url']))
        <div><dt class="font-semibold text-slate-700">Warranty</dt><dd class="text-slate-600"><a href="{{ $fields['warranty_url'] }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline">{{ $fields['warranty_url'] }}</a></dd></div>
    @endif
    @if(!empty($fields['warranty_summary']))
        <div class="sm:col-span-2"><dt class="font-semibold text-slate-700">Warranty Summary</dt><dd class="text-slate-600">{!! nl2br(e($fields['warranty_summary'])) !!}</dd></div>
    @endif
    @if(!empty($fields['support_notes']))
        <div class="sm:col-span-2"><dt class="font-semibold text-slate-700">Support Notes</dt><dd class="text-slate-600">{!! nl2br(e($fields['support_notes'])) !!}</dd></div>
    @endif
</dl>
