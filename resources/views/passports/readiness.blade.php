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
        $statusTitle = ($readinessHistorical ?? false) ? __('Stored readiness evaluation') : ($isNotReady ? __('Not ready to publish') : __('Ready to publish'));
        $statusSubtitle = $isNotReady
            ? trans_choice(':count blocking issue must be fixed before publication.|:count blocking issues must be fixed before publication.', $readiness->counts->blockers, ['count' => $readiness->counts->blockers])
            : ($readiness->status->value === 'ready_with_warnings'
                ? trans_choice(':count warning should be reviewed before publishing.|:count warnings should be reviewed before publishing.', $readiness->counts->warnings, ['count' => $readiness->counts->warnings])
                : __('All checks passed.'));

        $user = auth()->user();
        $canPublish = ! ($readinessHistorical ?? false) && $user && ($user->can(\App\Enums\CompanyPermission::PassportsPublish->value, [$company]) || $user->can(\App\Enums\CompanyPermission::PassportsManage->value, [$company]));
        $readinessWorkspace = true;
        $passportReadiness = $readiness;
        $productIndexReturnUrl = route('catalog.products.index');
        $identifierLine = collect([$product->defaultVariant?->sku, $product->primaryCategory?->name, $product->manufacturer])->filter()->implode(' · ');
        $canViewPassports = true;
        $isArchived = ($readinessHistorical ?? false) || $product->status === \App\Enums\Catalog\ProductStatus::Archived;
        $productStatus = $product->status->value;
        $canManageDocuments = $user?->can('viewAny', [\App\Models\Catalog\ProductDocument::class, $company]) === true;
        $workspaceDocumentCount = $canManageDocuments ? $product->documents()->where('company_id', $company->id)->active()->count() : 0;
        $canEditPassport = ! ($readinessHistorical ?? false) && $user?->can(\App\Enums\CompanyPermission::PassportsManage->value, [$company]);
    @endphp
    <div class="workspace np-product-workspace np-readiness-workspace" data-testid="passport-readiness-page">
        @include('catalog.products.partials.workspace-header')
        @if ($readinessHistorical ?? false)
            <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                {{ __('Stored readiness evaluation from :date. This result may be out of date. An active subscription and a managed product are required to recalculate or publish.', ['date' => $readiness->evaluatedAt->toDateTimeString()]) }}
            </div>
        @endif
        <div class="readiness-grid">
            <aside class="panel readiness-overview" data-testid="readiness-status-card">
                <p class="eyebrow">{{ __('READINESS OVERVIEW') }}</p>
                <div class="score-big">{{ $readiness->score }}<span>%</span></div>
                <div class="h-2 overflow-hidden rounded-full bg-emerald-100" role="progressbar" aria-valuenow="{{ $readiness->score }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('Readiness score') }}">
                    <div class="h-full {{ $isNotReady ? 'bg-amber-500' : 'bg-emerald-700' }}" style="width: {{ $readiness->score }}%"></div>
                </div>
                <p class="muted">{{ __('Checks the passport draft against the active readiness profile. The score is a quality indicator; only blocking issues prevent publication.') }}</p>
                <div class="check-stats">
                    <div data-testid="count-blockers"><span class="amber-text">{{ __('Required before publication') }}</span><strong>{{ $readiness->counts->blockers }}</strong></div>
                    <div data-testid="count-warnings"><span>{{ __('Warnings') }}</span><strong>{{ $readiness->counts->warnings }}</strong></div>
                    <div data-testid="count-recommendations"><span>{{ __('Recommendations') }}</span><strong>{{ $readiness->counts->recommendations }}</strong></div>
                    <div data-testid="count-passed"><span>{{ __('Passed checks') }}</span><strong>{{ $readiness->counts->passed }}</strong></div>
                </div>
                @unless ($readinessHistorical ?? false)
                    <a class="btn btn-outline" href="{{ route('catalog.products.passport.readiness', $product->uuid) }}">{{ __('Run readiness check') }}</a>
                @endunless
                <p class="check-time">{{ __('Evaluated at') }}: {{ $readiness->evaluatedAt->toDateTimeString() }} · {{ __('Profile') }} v{{ $readiness->profileVersion }}</p>
                <p class="profile-note">{{ $readiness->scoreBreakdown->earnedPoints }} / {{ $readiness->scoreBreakdown->applicablePoints }} weighted points · {{ $readiness->counts->notApplicable }} {{ __('not applicable') }}</p>
            </aside>
            <div class="readiness-results">
                <div class="results-heading">
                    <div><p class="eyebrow">{{ __('Publication readiness') }}</p><h2>{{ $statusTitle }}</h2><p>{{ $statusSubtitle }}</p></div>
                </div>
                @if ($blockerRules !== [])
                    <section data-testid="readiness-blockers">
                        <h2 class="sr-only">{{ __('Must be fixed before publishing') }}</h2>
                        @foreach ($blockerRules as $rule)
                            @include('passports.partials.workspace-readiness-rule', ['blocker' => true])
                        @endforeach
                    </section>
                @endif
                @if ($warningRules !== [] || $recommendationRules !== [])
                    <section data-testid="readiness-other-improvements">
                        <h2 class="mb-4 mt-6 font-semibold">{{ __('Other improvements') }}</h2>
                        <details data-testid="warnings-group" @if ($blockerRules === []) open @endif>
                            <summary class="mb-4 cursor-pointer text-sm font-semibold">{{ trans_choice(':count warning|:count warnings', count($warningRules), ['count' => count($warningRules)]) }}</summary>
                            @foreach ($warningRules as $rule)
                                @include('passports.partials.workspace-readiness-rule', ['blocker' => false])
                            @endforeach
                        </details>
                        @if ($recommendationRules !== [])
                            <details class="mt-4" data-testid="recommendations-group">
                                <summary class="mb-4 cursor-pointer text-sm font-semibold">{{ trans_choice(':count recommendation|:count recommendations', count($recommendationRules), ['count' => count($recommendationRules)]) }}</summary>
                                @foreach ($recommendationRules as $rule)
                                    @include('passports.partials.workspace-readiness-rule', ['blocker' => false])
                                @endforeach
                            </details>
                        @endif
                    </section>
                @endif
                @include('passports.partials.workspace-readiness-details')
                <div class="publish-bar" data-testid="readiness-publication-actions">
                    <div><strong>{{ $statusTitle }}</strong><p>{{ ($readinessHistorical ?? false) ? __('Recalculate readiness before making a publication decision.') : $statusSubtitle }}</p></div>
                    @if ($canPublish)
                        @if ($isNotReady)
                            <button class="btn btn-primary" type="button" disabled>{{ __('Publish Passport') }}</button>
                        @else
                            <a class="btn btn-primary" href="{{ route('catalog.products.passport.publish-confirm', $product->uuid) }}">{{ __('Publish Passport') }}</a>
                        @endif
                    @endif
                </div>
            </div>
        </div>
        @include('passports.partials.workspace-readiness-profile')
        @include('passports.partials.regulatory-context')
        <div class="workspace-tools">
            @if ($canEditPassport)<a href="{{ route('catalog.products.passport.edit', $product->uuid) }}">{{ __('Passport editor') }}</a>@endif
            @if ($passport)<a href="{{ route('catalog.products.passport.registry', $product->uuid) }}">{{ __('EU registry and battery fields') }}</a>@endif
        </div>
    </div>
</x-app-layout>
