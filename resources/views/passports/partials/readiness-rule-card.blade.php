@props(['rule', 'rulePresenter', 'product'])

@php($actionUrl = $rulePresenter->actionUrl($product, $rule))

<article class="rounded-xl border p-4 {{ $rulePresenter->cardTone($rule) }}">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm font-semibold leading-5 text-slate-900">{{ $rulePresenter->title($rule) }}</p>
            <p class="mt-1 text-sm leading-5 text-slate-600">{{ $rulePresenter->message($rule) }}</p>
        </div>
        <span class="shrink-0 rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $rulePresenter->statusTone($rule) }}">{{ $rulePresenter->statusLabel($rule) }}</span>
    </div>

    <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">{{ $rulePresenter->statusHelp($rule) }}</p>
        @if ($actionUrl !== null)
            <a href="{{ $actionUrl }}" class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-indigo-700 hover:text-indigo-900">
                {{ $rulePresenter->actionLabel($rule) }} <span aria-hidden="true">&rarr;</span>
            </a>
        @endif
    </div>

    <details class="mt-3 border-t border-slate-200/70 pt-3" data-testid="rule-details">
        <summary class="cursor-pointer list-none text-xs font-semibold text-slate-500 hover:text-slate-700">{{ __('Rule details') }}</summary>
        <dl class="mt-2 grid gap-1.5 text-xs text-slate-600">
            <div class="grid grid-cols-[7rem_1fr] gap-2">
                <dt class="font-semibold text-slate-500">{{ __('Rule ID') }}</dt>
                <dd class="break-all font-mono text-slate-700">{{ $rule->code }}</dd>
            </div>
            <div class="grid grid-cols-[7rem_1fr] gap-2">
                <dt class="font-semibold text-slate-500">{{ __('Source') }}</dt>
                <dd>{{ $rulePresenter->groupLabel($rule->group) }}</dd>
            </div>
            <div class="grid grid-cols-[7rem_1fr] gap-2">
                <dt class="font-semibold text-slate-500">{{ __('Requirement level') }}</dt>
                <dd>{{ $rulePresenter->requirementLabel($rule) }}</dd>
            </div>
            @if ($rule->safeContext !== [])
                <div class="grid grid-cols-[7rem_1fr] gap-2">
                    <dt class="font-semibold text-slate-500">{{ __('Actual values') }}</dt>
                    <dd><pre class="overflow-auto whitespace-pre-wrap break-all font-mono text-[11px] text-slate-700">{{ json_encode($rule->safeContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></dd>
                </div>
            @endif
        </dl>
    </details>
</article>
