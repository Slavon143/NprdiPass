<dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
    @if(!empty($fields['manufacturer_display_name']))
        <div><dt class="font-semibold text-slate-700">Manufacturer</dt><dd class="text-slate-600">{{ $fields['manufacturer_display_name'] }}</dd></div>
    @endif
    @if(!empty($fields['manufacturer_country']))
        <div><dt class="font-semibold text-slate-700">Manufacturer Country</dt><dd class="text-slate-600">{{ $fields['manufacturer_country'] }}</dd></div>
    @endif
    @if(!empty($fields['manufacturer_email']))
        <div><dt class="font-semibold text-slate-700">Manufacturer Email</dt><dd class="text-slate-600"><a href="mailto:{{ $fields['manufacturer_email'] }}" class="text-blue-600 hover:underline">{{ $fields['manufacturer_email'] }}</a></dd></div>
    @endif
    @if(!empty($fields['manufacturer_website']))
        <div><dt class="font-semibold text-slate-700">Manufacturer Website</dt><dd class="text-slate-600"><a href="{{ $fields['manufacturer_website'] }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline">{{ $fields['manufacturer_website'] }}</a></dd></div>
    @endif
    @if(!empty($fields['responsible_operator_display_name']))
        <div><dt class="font-semibold text-slate-700">Responsible Operator</dt><dd class="text-slate-600">{{ $fields['responsible_operator_display_name'] }}</dd></div>
    @endif
    @if(!empty($fields['responsible_operator_country']))
        <div><dt class="font-semibold text-slate-700">Operator Country</dt><dd class="text-slate-600">{{ $fields['responsible_operator_country'] }}</dd></div>
    @endif
    @if(!empty($fields['responsible_operator_email']))
        <div><dt class="font-semibold text-slate-700">Operator Email</dt><dd class="text-slate-600"><a href="mailto:{{ $fields['responsible_operator_email'] }}" class="text-blue-600 hover:underline">{{ $fields['responsible_operator_email'] }}</a></dd></div>
    @endif
    @if(!empty($fields['responsible_operator_website']))
        <div><dt class="font-semibold text-slate-700">Operator Website</dt><dd class="text-slate-600"><a href="{{ $fields['responsible_operator_website'] }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline">{{ $fields['responsible_operator_website'] }}</a></dd></div>
    @endif
    @if(!empty($fields['contact_notes']))
        <div class="sm:col-span-2"><dt class="font-semibold text-slate-700">Contact Notes</dt><dd class="text-slate-600">{!! nl2br(e($fields['contact_notes'])) !!}</dd></div>
    @endif
</dl>
