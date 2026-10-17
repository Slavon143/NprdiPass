<dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
    @if(!empty($fields['manufacturer_display_name']))
        <div><dt class="font-semibold text-slate-700">{{ __('Manufacturer') }}</dt><dd class="text-slate-600">{{ $fields['manufacturer_display_name'] }}</dd></div>
    @endif
    @if(!empty($fields['manufacturer_country']))
        <div><dt class="font-semibold text-slate-700">{{ __('Manufacturer Country') }}</dt><dd class="text-slate-600">{{ $fields['manufacturer_country'] }}</dd></div>
    @endif
    @if(!empty($fields['manufacturer_email']))
        <div><dt class="font-semibold text-slate-700">{{ __('Manufacturer Email') }}</dt><dd class="text-slate-600"><a href="mailto:{{ $fields['manufacturer_email'] }}" class="text-blue-600 hover:underline">{{ $fields['manufacturer_email'] }}</a></dd></div>
    @endif
    @if(!empty($fields['manufacturer_website']))
        <div><dt class="font-semibold text-slate-700">{{ __('Manufacturer Website') }}</dt><dd class="text-slate-600"><a href="{{ $fields['manufacturer_website'] }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline">{{ $fields['manufacturer_website'] }}</a></dd></div>
    @endif
    @foreach (['manufacturer_address' => 'Manufacturer postal address', 'manufacturer_phone' => 'Manufacturer phone', 'manufacturer_registration_id' => 'Manufacturer registration ID',
        'importer_display_name' => 'Importer', 'importer_address' => 'Importer postal address', 'importer_email' => 'Importer email',
        'importer_website' => 'Importer website', 'importer_phone' => 'Importer phone', 'importer_country' => 'Importer country',
        'importer_registration_id' => 'Importer registration ID'] as $key => $label)
        @if (!empty($fields[$key]))
            <div><dt class="font-semibold text-slate-700">{{ __($label) }}</dt><dd class="text-slate-600 whitespace-pre-line">{{ $fields[$key] }}</dd></div>
        @endif
    @endforeach
    @if(!empty($fields['responsible_operator_display_name']))
        <div><dt class="font-semibold text-slate-700">{{ __('Responsible Operator') }}</dt><dd class="text-slate-600">{{ $fields['responsible_operator_display_name'] }}</dd></div>
    @endif
    @if(!empty($fields['responsible_operator_country']))
        <div><dt class="font-semibold text-slate-700">{{ __('Operator Country') }}</dt><dd class="text-slate-600">{{ $fields['responsible_operator_country'] }}</dd></div>
    @endif
    @if(!empty($fields['responsible_operator_email']))
        <div><dt class="font-semibold text-slate-700">{{ __('Operator Email') }}</dt><dd class="text-slate-600"><a href="mailto:{{ $fields['responsible_operator_email'] }}" class="text-blue-600 hover:underline">{{ $fields['responsible_operator_email'] }}</a></dd></div>
    @endif
    @if(!empty($fields['responsible_operator_website']))
        <div><dt class="font-semibold text-slate-700">{{ __('Operator Website') }}</dt><dd class="text-slate-600"><a href="{{ $fields['responsible_operator_website'] }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline">{{ $fields['responsible_operator_website'] }}</a></dd></div>
    @endif
    @if(!empty($fields['responsible_operator_role']))
        <div><dt class="font-semibold text-slate-700">{{ __('Operator Role') }}</dt><dd class="text-slate-600">{{ $fields['responsible_operator_role'] }}</dd></div>
    @endif
    @if(!empty($fields['responsible_operator_address']))
        <div><dt class="font-semibold text-slate-700">{{ __('Operator Address') }}</dt><dd class="text-slate-600">{!! nl2br(e($fields['responsible_operator_address'])) !!}</dd></div>
    @endif
    @if(!empty($fields['responsible_operator_phone']))
        <div><dt class="font-semibold text-slate-700">{{ __('Operator Phone') }}</dt><dd class="text-slate-600">{{ $fields['responsible_operator_phone'] }}</dd></div>
    @endif
    @if(!empty($fields['responsible_operator_registration_id']))
        <div><dt class="font-semibold text-slate-700">{{ __('Operator Registration ID') }}</dt><dd class="text-slate-600">{{ $fields['responsible_operator_registration_id'] }}</dd></div>
    @endif
    @if(!empty($fields['contact_notes']))
        <div class="sm:col-span-2"><dt class="font-semibold text-slate-700">{{ __('Contact Notes') }}</dt><dd class="text-slate-600">{!! nl2br(e($fields['contact_notes'])) !!}</dd></div>
    @endif
</dl>
