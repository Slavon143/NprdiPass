<x-app-layout>
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Passport Readiness: {{ $product->name }}</h1>
        <div>
            <a href="{{ route('catalog.products.passport.edit', $product->uuid) }}" class="text-blue-600 hover:underline mr-4">Back to Passport Editor</a>
            <a href="{{ route('catalog.products.show', $product->uuid) }}" class="text-blue-600 hover:underline">Back to Product</a>
        </div>
    </div>

    <div class="space-y-6">
        {{-- Publication Action Card --}}
        <div class="bg-white shadow rounded-lg p-6">
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-lg font-semibold mb-1">Publication Status</h2>
                    @if($readiness->status->value === 'not_ready')
                        <div class="flex items-center gap-2">
                            <span class="px-3 py-1 rounded-full text-sm font-semibold bg-red-100 text-red-800">Not Ready</span>
                            <span class="text-sm text-gray-600">Resolve blockers before publishing</span>
                        </div>
                    @elseif($readiness->status->value === 'ready_with_warnings')
                        <div class="flex items-center gap-2">
                            <span class="px-3 py-1 rounded-full text-sm font-semibold bg-amber-100 text-amber-800">Ready with Warnings</span>
                            <span class="text-sm text-gray-600">{{ $readiness->counts->warnings }} warning(s)</span>
                        </div>
                    @else
                        <div class="flex items-center gap-2">
                            <span class="px-3 py-1 rounded-full text-sm font-semibold bg-green-100 text-green-800">Ready for Publication</span>
                        </div>
                    @endif
                </div>
                <div>
                    @php
                        $user = auth()->user();
                        $canPublish = $user && ($user->can(\App\Enums\CompanyPermission::PassportsPublish->value, [$company]) || $user->can(\App\Enums\CompanyPermission::PassportsManage->value, [$company]));
                    @endphp
                    @if($canPublish)
                        @if($readiness->status->value === 'not_ready')
                            <button type="button" class="bg-gray-400 text-white px-4 py-2 rounded text-sm cursor-not-allowed" disabled>
                                Publish Passport
                            </button>
                        @else
                            <a href="{{ route('catalog.products.passport.publish-confirm', $product->uuid) }}"
                               class="inline-block bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700 text-sm font-semibold">
                                Publish Passport
                            </a>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        {{-- Status & Score --}}
        <div class="bg-white shadow rounded-lg p-6">
            <div class="flex items-center gap-4 mb-4">
                <span class="px-4 py-2 rounded-full text-sm font-semibold @if($readiness->status->value === 'ready') bg-green-100 text-green-800 @elseif($readiness->status->value === 'ready_with_warnings') bg-amber-100 text-amber-800 @else bg-red-100 text-red-800 @endif">
                    {{ str_replace('_', ' ', ucfirst($readiness->status->value)) }}
                </span>
                <span class="text-lg font-bold">Score: {{ $readiness->score }}%</span>
            </div>

            <div class="w-full bg-gray-200 rounded-full h-4">
                <div class="h-4 rounded-full @if($readiness->score >= 80) bg-green-500 @elseif($readiness->score >= 50) bg-amber-500 @else bg-red-500 @endif" style="width: {{ $readiness->score }}%"></div>
            </div>

            @include('passports.partials.readiness-score-breakdown', ['readiness' => $readiness, 'product' => $product])
        </div>

        {{-- Profile & Passport Info --}}
        <details class="rounded-lg bg-white shadow">
            <summary class="cursor-pointer p-6 text-lg font-semibold">Technical details</summary>
            <div class="grid grid-cols-1 gap-4 border-t border-slate-100 px-6 pb-6 pt-4 text-sm sm:grid-cols-2">
                <div><strong>Profile:</strong> {{ $readiness->profile }}</div>
                <div><strong>Profile Version:</strong> {{ $readiness->profileVersion }}</div>
                <div><strong>Schema Version:</strong> {{ $readiness->schemaVersion }}</div>
                <div><strong>Passport UUID:</strong> {{ $readiness->passportUuid }}</div>
                <div><strong>Draft Version UUID:</strong> {{ $readiness->draftVersionUuid ?? 'N/A' }}</div>
                <div><strong>Passport Revision:</strong> {{ $readiness->passportRevision }}</div>
                <div><strong>Evaluated at:</strong> {{ $readiness->evaluatedAt->toISOString() }}</div>
            </div>
        </details>

        {{-- Counts Summary --}}
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4">Rule Summary</h2>
            <div class="grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-5">
                <span class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 font-semibold text-red-800">Blockers: {{ $readiness->counts->blockers }}</span>
                <span class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 font-semibold text-amber-800">Warnings: {{ $readiness->counts->warnings }}</span>
                <span class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 font-semibold text-blue-800">Recommendations: {{ $readiness->counts->recommendations }}</span>
                <span class="rounded-lg border border-green-200 bg-green-50 px-3 py-2 font-semibold text-green-800">Passed: {{ $readiness->counts->passed }}</span>
                <span class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 font-semibold text-gray-600">Not applicable: {{ $readiness->counts->notApplicable }}</span>
            </div>
        </div>

        {{-- Rules by Group --}}
        @php
            $rulePresenter = app(\App\Support\Passports\ReadinessRulePresenter::class);
            $rulesByGroup = [];
            foreach ($readiness->rules as $rule) {
                $rulesByGroup[$rule->group->value][] = $rule;
            }

            $ruleSections = [
                ['title' => 'Needs attention', 'status' => 'failed', 'count' => $readiness->counts->blockers + $readiness->counts->warnings + $readiness->counts->recommendations, 'open' => true],
                ['title' => 'Passed', 'status' => 'passed', 'count' => $readiness->counts->passed, 'open' => false],
                ['title' => 'Not applicable', 'status' => 'not_applicable', 'count' => $readiness->counts->notApplicable, 'open' => false],
            ];
        @endphp

        @foreach ($ruleSections as $ruleSection)
        <details class="rounded-lg border border-slate-200 bg-white shadow" @if($ruleSection['open']) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between rounded-lg p-5 hover:bg-slate-50">
                <h2 class="text-lg font-semibold text-slate-900">{{ $ruleSection['title'] }}</h2>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700">{{ $ruleSection['count'] }} rules</span>
            </summary>
            <div class="space-y-3 border-t border-slate-100 p-4">
        @foreach ($rulesByGroup as $group => $allGroupRules)
        @php
            $rules = array_values(array_filter(
                $allGroupRules,
                fn ($rule) => $rule->status->value === $ruleSection['status'],
            ));

            if ($rules === []) continue;

            $groupCounts = [
                'blocker' => 0,
                'warning' => 0,
                'recommendation' => 0,
                'passed' => 0,
                'not_applicable' => 0,
            ];

            foreach ($rules as $groupRule) {
                if ($groupRule->status->value === 'passed') {
                    $groupCounts['passed']++;
                } elseif ($groupRule->status->value === 'not_applicable') {
                    $groupCounts['not_applicable']++;
                } else {
                    $groupCounts[$groupRule->severity->value] = ($groupCounts[$groupRule->severity->value] ?? 0) + 1;
                }
            }
        @endphp
        <details class="group rounded-lg border border-slate-200 bg-white" @if($ruleSection['status'] === 'failed') open @endif>
            <summary class="flex cursor-pointer list-none flex-col gap-3 rounded-lg p-4 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">{{ $rulePresenter->groupLabel($group) }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ $rulePresenter->groupDescription($group) }}</p>
                </div>
                <div class="flex flex-wrap gap-1.5 text-xs">
                    @if($groupCounts['blocker'] > 0)<span class="rounded-full bg-red-100 px-2 py-0.5 font-semibold text-red-800">{{ $groupCounts['blocker'] }} blockers</span>@endif
                    @if($groupCounts['warning'] > 0)<span class="rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-800">{{ $groupCounts['warning'] }} warnings</span>@endif
                    @if($groupCounts['recommendation'] > 0)<span class="rounded-full bg-blue-100 px-2 py-0.5 font-semibold text-blue-800">{{ $groupCounts['recommendation'] }} recommendations</span>@endif
                    <span class="rounded-full bg-green-100 px-2 py-0.5 font-semibold text-green-800">{{ $groupCounts['passed'] }} passed</span>
                    @if($groupCounts['not_applicable'] > 0)<span class="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-600">{{ $groupCounts['not_applicable'] }} n/a</span>@endif
                </div>
            </summary>
            <div class="grid gap-2 border-t border-slate-100 p-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($rules as $rule)
                @php
                        $titleText = $rulePresenter->title($rule);
                        $msgText = $rulePresenter->message($rule);
                        $actionUrl = $rulePresenter->actionUrl($product, $rule);
                    @endphp
                <div class="rounded-lg border p-3 {{ $rulePresenter->cardTone($rule) }}">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-sm font-semibold leading-5 text-slate-900">{{ $titleText }}</p>
                        <span title="{{ $rulePresenter->statusHelp($rule) }}" class="shrink-0 rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $rulePresenter->statusTone($rule) }}">{{ $rulePresenter->resultLabel($rule) }}</span>
                    </div>
                    <p class="mt-1 text-xs leading-5 text-slate-600">{{ $msgText }}</p>
                    <dl class="mt-2 grid gap-1 text-[11px] text-slate-600">
                        <div><dt class="inline font-semibold">{{ __('Source:') }}</dt> <dd class="inline">{{ $rulePresenter->groupLabel($rule->group) }}</dd></div>
                        <div><dt class="inline font-semibold">{{ __('Current:') }}</dt> <dd class="inline">{{ $msgText }}</dd></div>
                        <div><dt class="inline font-semibold">{{ __('Result:') }}</dt> <dd class="inline">{{ $rulePresenter->resultLabel($rule) }}</dd></div>
                        <div><dt class="inline font-semibold">{{ __('Requirement level:') }}</dt> <dd class="inline">{{ $rulePresenter->requirementLabel($rule) }}</dd></div>
                    </dl>

                    @if ($rule->status->value !== 'passed')
                        <p class="mt-1 text-[11px] leading-4 text-slate-500">{{ $rulePresenter->statusHelp($rule) }}</p>
                    @endif

                    @if ($actionUrl !== null)
                    <div class="mt-2 border-t border-white/70 pt-2">
                        <a href="{{ $actionUrl }}" class="inline-flex items-center gap-1 text-indigo-600 hover:underline text-xs font-semibold">
                            {{ __('Fix:') }} {{ $rulePresenter->actionLabel($rule) }} &rarr;
                        </a>
                    </div>
                    @endif
                    @if(config('app.debug'))
                        <details class="mt-2 text-[11px] text-slate-500">
                            <summary class="cursor-pointer font-semibold">{{ __('Technical details') }}</summary>
                            <div class="mt-1 font-mono">{{ $rule->code }}</div>
                            @if($rule->safeContext !== [])<pre class="mt-1 overflow-auto whitespace-pre-wrap">{{ json_encode($rule->safeContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>@endif
                        </details>
                    @endif
                </div>
                @endforeach
            </div>
        </details>
        @endforeach
            </div>
        </details>
        @endforeach

        {{-- Legal Disclaimer --}}
        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-500">
            This is an internal NordiPass readiness score. It is not an official EU score, legal certification, or proof of regulatory compliance.
        </div>

    </div>
</div>
</x-app-layout>
