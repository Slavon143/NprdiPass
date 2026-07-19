@props(['readiness'])

@php
    $breakdown = app(\App\Services\Passports\Readiness\ReadinessScoreCalculator::class)->breakdown($readiness->rules);
    $rulePresenter = app(\App\Support\Passports\ReadinessRulePresenter::class);
    $failedRules = [];

    foreach ($readiness->rules as $rule) {
        if ($rule->status->value === 'failed') $failedRules[] = $rule;
    }
@endphp

<div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
    <h3 class="font-semibold text-slate-900">Why this score?</h3>

    <p class="mt-1 text-slate-600">
        Score is weighted readiness, not a simple rule count:
        <span class="font-mono">{{ $breakdown->earnedPoints }}</span> earned points /
        <span class="font-mono">{{ $breakdown->applicablePoints }}</span> applicable points =
        <span class="font-semibold">{{ $readiness->score }}%</span>.
        Not applicable rules are excluded.
    </p>

    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded border border-green-200 bg-green-50 px-3 py-2">
            <div class="text-xs font-semibold uppercase text-green-700">Passed rules / readiness points earned</div>
            <div class="text-xs text-green-800">{{ $readiness->counts->passed }} passed rules</div>
            <div class="font-mono text-green-900">{{ $breakdown->earnedPoints }}</div>
        </div>
        <div class="rounded border border-red-200 bg-red-50 px-3 py-2">
            <div class="text-xs font-semibold uppercase text-red-700">Failed blocker rules / lost points</div>
            <div class="text-xs text-red-800">{{ $readiness->counts->blockers }} failed rules</div>
            <div class="font-mono text-red-900">{{ $breakdown->failedPointsBySeverity['blocker'] }}</div>
        </div>
        <div class="rounded border border-amber-200 bg-amber-50 px-3 py-2">
            <div class="text-xs font-semibold uppercase text-amber-700">Failed warning rules / lost points</div>
            <div class="text-xs text-amber-800">{{ $readiness->counts->warnings }} failed rules</div>
            <div class="font-mono text-amber-900">{{ $breakdown->failedPointsBySeverity['warning'] }}</div>
        </div>
        <div class="rounded border border-blue-200 bg-blue-50 px-3 py-2">
            <div class="text-xs font-semibold uppercase text-blue-700">Failed recommendation rules / lost points</div>
            <div class="text-xs text-blue-800">{{ $readiness->counts->recommendations }} failed rules</div>
            <div class="font-mono text-blue-900">{{ $breakdown->failedPointsBySeverity['recommendation'] }}</div>
        </div>
    </div>

    @if ($failedRules !== [])
        <div class="mt-4">
            <div class="font-semibold text-slate-900">Needs attention</div>
            <p class="mt-1 text-xs text-slate-600">
                These items are not all activation blockers. Red blockers prevent activation; yellow warnings and blue recommendations reduce the readiness score and should be reviewed before publishing.
            </p>
            <div class="mt-2 space-y-2">
                @foreach (array_slice($failedRules, 0, 8) as $rule)
                    @php
                        $titleText = $rulePresenter->title($rule);
                        $actionUrl = isset($product) ? $rulePresenter->actionUrl($product, $rule) : null;
                    @endphp
                    <div class="rounded border border-white bg-white px-3 py-2">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <span title="{{ $rulePresenter->statusHelp($rule) }}" class="rounded border px-2 py-0.5 text-xs font-semibold {{ $rulePresenter->statusTone($rule) }}">
                                    {{ $rulePresenter->statusLabel($rule) }}
                                </span>
                                <span class="ml-2 text-slate-800">{{ $titleText }}</span>
                            </div>

                            @if ($actionUrl !== null)
                                <a href="{{ $actionUrl }}" class="text-xs font-semibold text-indigo-600 hover:underline">
                                    {{ __('Fix:') }} {{ $rulePresenter->actionLabel($rule) }} &rarr;
                                </a>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $rulePresenter->statusHelp($rule) }}
                        </p>
                    </div>
                @endforeach
            </div>

            @if (count($failedRules) > 8)
                <p class="mt-2 text-xs text-slate-500">
                    Showing first 8 of {{ count($failedRules) }} failed rules. Open the full readiness page for the complete grouped list.
                </p>
            @endif
        </div>
    @endif
</div>
