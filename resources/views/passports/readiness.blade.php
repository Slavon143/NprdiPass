<x-app-layout>
    @php
        $rulePresenter = app(\App\Support\Passports\ReadinessRulePresenter::class);

        $blockerRules = [];
        $warningRules = [];
        $recommendationRules = [];
        $passedRules = [];
        $notApplicableRules = [];

        foreach ($readiness->rules as $rule) {
            if ($rule->status->value === 'passed') {
                $passedRules[] = $rule;
            } elseif ($rule->status->value === 'not_applicable') {
                $notApplicableRules[] = $rule;
            } else {
                match ($rule->severity->value) {
                    'blocker' => $blockerRules[] = $rule,
                    'warning' => $warningRules[] = $rule,
                    default => $recommendationRules[] = $rule,
                };
            }
        }

        $grouped = function (array $rules): array {
            $groups = [];

            foreach ($rules as $rule) {
                $groups[$rule->group->value][] = $rule;
            }

            return $groups;
        };

        $isNotReady = $readiness->status->value === 'not_ready';
        $statusTone = $isNotReady ? 'red' : ($readiness->status->value === 'ready_with_warnings' ? 'amber' : 'emerald');
        $statusTitle = $isNotReady ? __('Not ready to publish') : __('Ready to publish');
        $statusSubtitle = $isNotReady
            ? trans_choice(':count blocking issue must be fixed before publication.|:count blocking issues must be fixed before publication.', $readiness->counts->blockers, ['count' => $readiness->counts->blockers])
            : ($readiness->status->value === 'ready_with_warnings'
                ? trans_choice(':count warning should be reviewed before publishing.|:count warnings should be reviewed before publishing.', $readiness->counts->warnings, ['count' => $readiness->counts->warnings])
                : __('All checks passed.'));

        $user = auth()->user();
        $canPublish = $user && ($user->can(\App\Enums\CompanyPermission::PassportsPublish->value, [$company]) || $user->can(\App\Enums\CompanyPermission::PassportsManage->value, [$company]));
    @endphp

    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }} / <a href="{{ route('catalog.products.index') }}" class="hover:underline">{{ __('Products') }}</a> / <a href="{{ route('catalog.products.show', $product->uuid) }}" class="hover:underline">{{ $product->name }}</a> / {{ __('readiness.label') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Publication readiness') }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ __('Checks the passport draft against the active readiness profile. The score is a quality indicator; only blocking issues prevent publication.') }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('catalog.products.passport.edit', $product->uuid) }}" class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Passport editor') }}</a>
                <a href="{{ route('catalog.products.show', $product->uuid) }}" class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Product') }}</a>
            </div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8" data-testid="passport-readiness-page">
        {{-- Publication status --}}
        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm" data-testid="readiness-status-card">
            <div class="flex flex-col gap-6 p-6 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <p class="flex items-center gap-2 text-lg font-bold tracking-tight text-slate-900">
                        @if ($isNotReady)
                            <svg class="h-6 w-6 shrink-0 text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                        @elseif ($readiness->status->value === 'ready_with_warnings')
                            <svg class="h-6 w-6 shrink-0 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                        @else
                            <svg class="h-6 w-6 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        @endif
                        {{ $statusTitle }}
                    </p>
                    <p class="mt-1 text-sm text-slate-600">{{ $statusSubtitle }}</p>

                    <div class="mt-5">
                        <div class="flex items-center justify-between text-xs font-semibold">
                            <span class="text-slate-500">{{ __('Readiness score') }}</span>
                            <span class="text-slate-900">{{ $readiness->score }}%</span>
                        </div>
                        <div class="mt-1.5 h-2 w-full rounded-full bg-slate-100" role="progressbar" aria-valuenow="{{ $readiness->score }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('Readiness score') }}">
                            <div class="h-2 rounded-full {{ $isNotReady ? 'bg-red-500' : ($readiness->status->value === 'ready_with_warnings' ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ $readiness->score }}%"></div>
                        </div>
                        <p class="mt-1.5 text-xs text-slate-500">
                            {{ $readiness->scoreBreakdown->earnedPoints }} / {{ $readiness->scoreBreakdown->applicablePoints }} weighted points · {{ $readiness->counts->notApplicable }} {{ __('not applicable') }}
                        </p>
                    </div>
                </div>

                <div class="flex shrink-0 flex-col items-stretch gap-3 sm:flex-row lg:flex-col lg:items-end">
                    <div class="text-right">
                        <p class="text-3xl font-bold tracking-tight text-slate-900">{{ $readiness->score }}%</p>
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ __('complete') }}</p>
                    </div>
                    @if ($canPublish)
                        @if ($isNotReady)
                            <button type="button" class="inline-flex cursor-not-allowed items-center justify-center rounded-lg bg-slate-300 px-4 py-2.5 text-sm font-semibold text-white" disabled>{{ __('Publish Passport') }}</button>
                        @else
                            <a href="{{ route('catalog.products.passport.publish-confirm', $product->uuid) }}" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">{{ __('Publish Passport') }}</a>
                        @endif
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-2 gap-px border-t border-slate-200 bg-slate-200 sm:grid-cols-4">
                <div class="bg-white px-5 py-4" data-testid="count-blockers">
                    <p class="text-2xl font-bold text-red-600">{{ $readiness->counts->blockers }}</p>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ trans_choice('Blocker|Blockers', $readiness->counts->blockers) }}</p>
                </div>
                <div class="bg-white px-5 py-4" data-testid="count-warnings">
                    <p class="text-2xl font-bold text-amber-600">{{ $readiness->counts->warnings }}</p>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ trans_choice('Warning|Warnings', $readiness->counts->warnings) }}</p>
                </div>
                <div class="bg-white px-5 py-4" data-testid="count-recommendations">
                    <p class="text-2xl font-bold text-blue-600">{{ $readiness->counts->recommendations }}</p>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ trans_choice('Recommendation|Recommendations', $readiness->counts->recommendations) }}</p>
                </div>
                <div class="bg-white px-5 py-4" data-testid="count-passed">
                    <p class="text-2xl font-bold text-emerald-600">{{ $readiness->counts->passed }}</p>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Passed') }}</p>
                </div>
            </div>
        </section>

        {{-- Blockers --}}
        @if ($blockerRules !== [])
            <section class="mt-8" data-testid="readiness-blockers">
                <h2 class="flex items-center justify-between gap-3 px-1">
                    <span class="text-lg font-bold tracking-tight text-slate-900">{{ __('Must be fixed before publishing') }}</span>
                    <span class="rounded-full bg-red-100 px-3 py-1 text-sm font-semibold text-red-800">{{ count($blockerRules) }}</span>
                </h2>
                <div class="mt-4 grid gap-3">
                    @foreach ($blockerRules as $rule)
                        @php($actionUrl = $rulePresenter->actionUrl($product, $rule))
                        <article class="rounded-2xl border border-red-200 bg-red-50/60 p-5" data-testid="blocker-card">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-xs font-semibold uppercase tracking-wider text-red-700/80">{{ $rulePresenter->groupLabel($rule->group) }}</p>
                                <span class="rounded-full border border-red-200 bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-800">{{ $rulePresenter->statusLabel($rule) }}</span>
                            </div>
                            <p class="mt-2 font-semibold leading-5 text-slate-900">{{ $rulePresenter->title($rule) }}</p>
                            <p class="mt-1 text-sm leading-5 text-slate-600">{{ $rulePresenter->message($rule) }}</p>
                            <div class="mt-4 flex items-center justify-between gap-3">
                                <p class="text-xs text-red-700/80">{{ $rulePresenter->statusHelp($rule) }}</p>
                                @if ($actionUrl !== null)
                                    <a href="{{ $actionUrl }}" class="inline-flex shrink-0 items-center gap-1 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-500">
                                        {{ $rulePresenter->actionLabel($rule) }} <span aria-hidden="true">&rarr;</span>
                                    </a>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Other improvements --}}
        @if ($warningRules !== [] || $recommendationRules !== [])
            <section class="mt-8" data-testid="readiness-other-improvements">
                <h2 class="flex items-center justify-between gap-3 px-1">
                    <span class="text-lg font-bold tracking-tight text-slate-900">{{ __('Other improvements') }}</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700">{{ count($warningRules) + count($recommendationRules) }}</span>
                </h2>

                <div class="mt-4 space-y-3">
                    @if ($warningRules !== [])
                        <details class="rounded-2xl border border-slate-200 bg-white shadow-sm" @if ($blockerRules === []) open @endif data-testid="warnings-group">
                            <summary class="flex cursor-pointer list-none items-center justify-between rounded-2xl px-5 py-4 hover:bg-slate-50">
                                <span class="font-semibold text-slate-900">{{ trans_choice(':count warning|:count warnings', count($warningRules), ['count' => count($warningRules)]) }}</span>
                                <svg class="h-4 w-4 text-slate-400 transition group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                            </summary>
                            <div class="space-y-3 border-t border-slate-100 p-4">
                                @foreach ($grouped($warningRules) as $group => $groupRules)
                                    <details class="rounded-xl border border-slate-200" data-testid="warning-group-card">
                                        <summary class="flex cursor-pointer list-none items-center justify-between rounded-xl px-4 py-3 hover:bg-slate-50">
                                            <span class="text-sm font-semibold text-slate-900">{{ $rulePresenter->groupLabel($group) }}</span>
                                            <span class="text-xs font-semibold text-amber-700">{{ trans_choice(':count warning|:count warnings', count($groupRules), ['count' => count($groupRules)]) }}</span>
                                        </summary>
                                        <div class="grid gap-3 border-t border-slate-100 p-3 md:grid-cols-2">
                                            @foreach ($groupRules as $rule)
                                                @include('passports.partials.readiness-rule-card', ['rule' => $rule, 'rulePresenter' => $rulePresenter, 'product' => $product])
                                            @endforeach
                                        </div>
                                    </details>
                                @endforeach
                            </div>
                        </details>
                    @endif

                    @if ($recommendationRules !== [])
                        <details class="rounded-2xl border border-slate-200 bg-white shadow-sm" data-testid="recommendations-group">
                            <summary class="flex cursor-pointer list-none items-center justify-between rounded-2xl px-5 py-4 hover:bg-slate-50">
                                <span class="font-semibold text-slate-900">{{ trans_choice(':count recommendation|:count recommendations', count($recommendationRules), ['count' => count($recommendationRules)]) }}</span>
                                <svg class="h-4 w-4 text-slate-400 transition group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                            </summary>
                            <div class="space-y-3 border-t border-slate-100 p-4">
                                @foreach ($grouped($recommendationRules) as $group => $groupRules)
                                    <details class="rounded-xl border border-slate-200" data-testid="recommendation-group-card">
                                        <summary class="flex cursor-pointer list-none items-center justify-between rounded-xl px-4 py-3 hover:bg-slate-50">
                                            <span class="text-sm font-semibold text-slate-900">{{ $rulePresenter->groupLabel($group) }}</span>
                                            <span class="text-xs font-semibold text-blue-700">{{ trans_choice(':count recommendation|:count recommendations', count($groupRules), ['count' => count($groupRules)]) }}</span>
                                        </summary>
                                        <div class="grid gap-3 border-t border-slate-100 p-3 md:grid-cols-2">
                                            @foreach ($groupRules as $rule)
                                                @include('passports.partials.readiness-rule-card', ['rule' => $rule, 'rulePresenter' => $rulePresenter, 'product' => $product])
                                            @endforeach
                                        </div>
                                    </details>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </div>
            </section>
        @endif

        {{-- Passed --}}
        @if ($passedRules !== [] || $notApplicableRules !== [])
            <section class="mt-8" data-testid="readiness-passed">
                <details class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <summary class="flex cursor-pointer list-none items-center justify-between rounded-2xl px-5 py-4 hover:bg-slate-50">
                        <span class="flex items-center gap-2 font-semibold text-slate-900">
                            <svg class="h-5 w-5 shrink-0 text-emerald-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg>
                            {{ trans_choice(':count check passed|:count checks passed', count($passedRules), ['count' => count($passedRules)]) }}
                        </span>
                        <span class="flex items-center gap-3">
                            @if ($notApplicableRules !== [])
                                <span class="hidden text-xs text-slate-400 sm:inline">{{ trans_choice(':count not applicable|:count not applicable', count($notApplicableRules), ['count' => count($notApplicableRules)]) }}</span>
                            @endif
                            <svg class="h-4 w-4 text-slate-400 transition group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                        </span>
                    </summary>
                    <div class="space-y-3 border-t border-slate-100 p-4">
                        @foreach ($grouped($passedRules) as $group => $groupRules)
                            <div class="rounded-xl border border-slate-200 p-4" data-testid="passed-group">
                                <p class="flex items-center justify-between">
                                    <span class="text-sm font-semibold text-slate-900">{{ $rulePresenter->groupLabel($group) }}</span>
                                    <span class="text-xs font-semibold text-emerald-700">{{ trans_choice(':count passed|:count passed', count($groupRules), ['count' => count($groupRules)]) }}</span>
                                </p>
                                <ul class="mt-2 grid gap-1 sm:grid-cols-2">
                                    @foreach ($groupRules as $rule)
                                        <li class="flex items-start gap-2 text-sm text-slate-600">
                                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg>
                                            <span>{{ $rulePresenter->title($rule) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach

                        @if ($notApplicableRules !== [])
                            <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4" data-testid="not-applicable-group">
                                <p class="text-sm font-semibold text-slate-700">{{ __('Not applicable') }}</p>
                                <ul class="mt-2 grid gap-1 sm:grid-cols-2">
                                    @foreach ($notApplicableRules as $rule)
                                        <li class="text-sm text-slate-500">{{ $rulePresenter->title($rule) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </details>
            </section>
        @endif

        {{-- Technical details --}}
        <details class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm" data-testid="readiness-technical-details">
            <summary class="flex cursor-pointer list-none items-center justify-between rounded-2xl px-5 py-4 hover:bg-slate-50">
                <span class="font-semibold text-slate-900">{{ __('Technical details') }}</span>
                <svg class="h-4 w-4 text-slate-400 transition group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
            </summary>
            <div class="grid grid-cols-1 gap-4 border-t border-slate-100 px-5 py-4 text-sm sm:grid-cols-2">
                <div><dt class="font-semibold text-slate-500">{{ __('Profile') }}</dt><dd class="mt-0.5 text-slate-900">{{ $readiness->profile }}</dd></div>
                <div><dt class="font-semibold text-slate-500">{{ __('Profile Version') }}</dt><dd class="mt-0.5 text-slate-900">{{ $readiness->profileVersion }}</dd></div>
                <div><dt class="font-semibold text-slate-500">{{ __('Schema Version') }}</dt><dd class="mt-0.5 text-slate-900">{{ $readiness->schemaVersion }}</dd></div>
                <div><dt class="font-semibold text-slate-500">{{ __('Rule Set Version') }}</dt><dd class="mt-0.5 text-slate-900">{{ $readiness->ruleSetVersion }}</dd></div>
                <div><dt class="font-semibold text-slate-500">{{ __('Score Algorithm') }}</dt><dd class="mt-0.5 text-slate-900">{{ $readiness->scoreAlgorithm }} v{{ $readiness->scoreAlgorithmVersion }}</dd></div>
                <div class="sm:col-span-2"><dt class="font-semibold text-slate-500">{{ __('Rule Set Fingerprint') }}</dt><dd class="mt-0.5 break-all font-mono text-xs text-slate-700">{{ $readiness->ruleSetFingerprint }}</dd></div>
                <div><dt class="font-semibold text-slate-500">{{ __('Passport UUID') }}</dt><dd class="mt-0.5 font-mono text-xs text-slate-700">{{ $readiness->passportUuid }}</dd></div>
                <div><dt class="font-semibold text-slate-500">{{ __('Draft Version UUID') }}</dt><dd class="mt-0.5 font-mono text-xs text-slate-700">{{ $readiness->draftVersionUuid ?? 'N/A' }}</dd></div>
                <div><dt class="font-semibold text-slate-500">{{ __('Passport Revision') }}</dt><dd class="mt-0.5 text-slate-900">{{ $readiness->passportRevision }}</dd></div>
                <div><dt class="font-semibold text-slate-500">{{ __('Evaluated at') }}</dt><dd class="mt-0.5 text-slate-900">{{ $readiness->evaluatedAt->toISOString() }}</dd></div>
            </div>
        </details>

        {{-- Legal disclaimer --}}
        <div class="mt-8 rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs text-slate-500">
            {{ __('This is an internal NordiPass readiness score. It is not an official EU score, legal certification, or proof of regulatory compliance.') }}
        </div>
    </div>
</x-app-layout>
