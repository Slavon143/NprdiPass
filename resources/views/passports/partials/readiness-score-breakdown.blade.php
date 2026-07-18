@props(['readiness'])

@php
    $weights = config('passport_readiness.score_weights', []);
    $passedPoints = 0;
    $failedPointsBySeverity = [
        'blocker' => 0,
        'warning' => 0,
        'recommendation' => 0,
    ];
    $failedRules = [];

    foreach ($readiness->rules as $rule) {
        if ($rule->status->value === 'not_applicable') {
            continue;
        }

        $weight = (int) ($weights[$rule->severity->value] ?? 0);

        if ($rule->status->value === 'passed') {
            $passedPoints += $weight;

            continue;
        }

        $failedPointsBySeverity[$rule->severity->value] = ($failedPointsBySeverity[$rule->severity->value] ?? 0) + $weight;
        $failedRules[] = $rule;
    }

    $failedPoints = array_sum($failedPointsBySeverity);
    $totalPoints = $passedPoints + $failedPoints;
@endphp

<div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
    <h3 class="font-semibold text-slate-900">Why this score?</h3>

    <p class="mt-1 text-slate-600">
        Score is weighted readiness, not a simple rule count:
        <span class="font-mono">{{ $passedPoints }}</span> passed points /
        <span class="font-mono">{{ $totalPoints }}</span> applicable points =
        <span class="font-semibold">{{ $readiness->score }}%</span>.
        Not applicable rules are excluded.
    </p>

    <div class="mt-3 grid gap-2 sm:grid-cols-4">
        <div class="rounded border border-green-200 bg-green-50 px-3 py-2">
            <div class="text-xs font-semibold uppercase text-green-700">Passed</div>
            <div class="font-mono text-green-900">{{ $passedPoints }}</div>
        </div>
        <div class="rounded border border-red-200 bg-red-50 px-3 py-2">
            <div class="text-xs font-semibold uppercase text-red-700">Failed blockers</div>
            <div class="font-mono text-red-900">{{ $failedPointsBySeverity['blocker'] ?? 0 }}</div>
        </div>
        <div class="rounded border border-amber-200 bg-amber-50 px-3 py-2">
            <div class="text-xs font-semibold uppercase text-amber-700">Failed warnings</div>
            <div class="font-mono text-amber-900">{{ $failedPointsBySeverity['warning'] ?? 0 }}</div>
        </div>
        <div class="rounded border border-blue-200 bg-blue-50 px-3 py-2">
            <div class="text-xs font-semibold uppercase text-blue-700">Failed recommendations</div>
            <div class="font-mono text-blue-900">{{ $failedPointsBySeverity['recommendation'] ?? 0 }}</div>
        </div>
    </div>

    @if ($failedRules !== [])
        <div class="mt-4">
            <div class="font-semibold text-slate-900">Needs attention</div>
            <div class="mt-2 space-y-2">
                @foreach (array_slice($failedRules, 0, 8) as $rule)
                    @php
                        $titleText = __("readiness.{$rule->code}.title", []);
                        if ($titleText === "readiness.{$rule->code}.title") {
                            $titleText = __($rule->titleKey);
                        }
                        if ($titleText === $rule->titleKey) {
                            $titleText = ucfirst(str_replace(['.', '_'], ' ', $rule->code));
                        }
                    @endphp
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded border border-white bg-white px-3 py-2">
                        <div>
                            <span class="rounded px-2 py-0.5 text-xs font-semibold @if($rule->severity->value === 'blocker') bg-red-100 text-red-800 @elseif($rule->severity->value === 'warning') bg-amber-100 text-amber-800 @else bg-blue-100 text-blue-800 @endif">
                                {{ ucfirst($rule->severity->value) }}
                            </span>
                            <span class="ml-2 text-slate-800">{{ $titleText }}</span>
                        </div>

                        @if ($rule->navigationTarget !== null)
                            <a href="{{ route($rule->navigationTarget->routeName, $rule->navigationTarget->routeParameters) }}" class="text-xs font-semibold text-indigo-600 hover:underline">
                                Open {{ $rule->navigationTarget->label }} &rarr;
                            </a>
                        @endif
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
