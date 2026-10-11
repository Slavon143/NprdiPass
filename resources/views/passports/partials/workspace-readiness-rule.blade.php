@php($actionUrl = $rulePresenter->actionUrl($product, $rule))
<article class="requirement-card {{ $blocker ? 'requirement-blocker' : '' }}" @if ($blocker) data-testid="blocker-card" @endif>
    <div class="requirement-icon" aria-hidden="true">
        <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m12 3 10 18H2L12 3Z"/><path d="M12 9v5m0 3v1"/></svg>
    </div>
    <div>
        <span class="status status-amber">{{ $blocker ? __('Required before publication') : $rulePresenter->statusLabel($rule) }}</span>
        <h3>{{ $rulePresenter->title($rule) }}</h3>
        <p>{{ $rulePresenter->message($rule) }}</p>
        <span class="requirement-source">{{ $rulePresenter->groupLabel($rule->group) }} · {{ $rulePresenter->statusHelp($rule) }}</span>
        @include('passports.partials.workspace-rule-details')
    </div>
    @if ($actionUrl !== null)
        <a class="btn btn-outline" href="{{ $actionUrl }}">{{ $rulePresenter->actionLabel($rule) }}</a>
    @endif
</article>
