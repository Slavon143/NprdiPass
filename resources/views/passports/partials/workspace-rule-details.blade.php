    <details class="mt-3 border-t border-slate-200/70 pt-3" data-testid="rule-details">
        <summary class="cursor-pointer list-none text-xs font-semibold text-slate-500 hover:text-slate-700">{{ __('Rule details') }}</summary>
        <dl class="mt-2 grid gap-1.5 text-xs text-slate-600">
            <div class="grid grid-cols-[7rem_1fr] gap-2">
                <dt class="font-semibold text-slate-500">{{ __('Rule ID') }}</dt>
                <dd class="break-all font-mono text-slate-700">{{ $rule->code }}</dd>
            </div>
            <div class="grid grid-cols-[7rem_1fr] gap-2">
                <dt class="font-semibold text-slate-500">{{ __('Requirement group') }}</dt>
                <dd>{{ $rulePresenter->groupLabel($rule->group) }}</dd>
            </div>
            @if ($rule->source !== null)
                <div class="grid grid-cols-[7rem_1fr] gap-2">
                    <dt class="font-semibold text-slate-500">{{ __('Source') }}</dt>
                    <dd>{{ $rule->source }}@if ($rule->sourceReference) · {{ $rule->sourceReference }}@endif</dd>
                </div>
            @endif
            <div class="grid grid-cols-[7rem_1fr] gap-2">
                <dt class="font-semibold text-slate-500">{{ __('Requirement level') }}</dt>
                <dd>{{ $rulePresenter->requirementLabel($rule) }}</dd>
            </div>
            @if ($rule->appliesBecause !== null)
                <div class="grid grid-cols-[7rem_1fr] gap-2">
                    <dt class="font-semibold text-slate-500">{{ __('Applies because') }}</dt>
                    <dd>{{ $rule->appliesBecause }}</dd>
                </div>
            @endif
            @if ($rule->applicability === 'not_applicable')
                <div class="grid grid-cols-[7rem_1fr] gap-2">
                    <dt class="font-semibold text-slate-500">{{ __('Applicability') }}</dt>
                    <dd>{{ $rule->applicabilityReason ?? __('Not applicable for this product.') }}</dd>
                </div>
            @endif
            @if ($rule->safeContext !== [])
                <div class="grid grid-cols-[7rem_1fr] gap-2">
                    <dt class="font-semibold text-slate-500">{{ __('Actual values') }}</dt>
                    <dd><pre class="overflow-auto whitespace-pre-wrap break-all font-mono text-[11px] text-slate-700">{{ json_encode($rule->safeContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></dd>
                </div>
            @endif
        </dl>
    </details>
