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
