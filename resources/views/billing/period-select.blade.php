<label class="mb-3 block text-sm text-slate-700">
    {{ __('Billing period') }}
    <select name="interval" class="mt-1 w-full rounded-lg border-slate-300">
        <option value="month">{{ __('Monthly') }} · €{{ $plan['monthly_eur'] }}</option>
        <option value="year">{{ __('Annual') }} · €{{ $plan['annual_eur'] }}</option>
    </select>
</label>
