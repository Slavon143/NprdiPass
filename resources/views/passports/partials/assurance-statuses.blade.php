@php
    $assuranceStatuses = app(\App\Services\Passports\Readiness\PassportAssuranceSummary::class)->forResult($readiness);
@endphp
<section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5" data-testid="passport-assurance-statuses">
    <h2 class="text-lg font-semibold text-slate-900">{{ __('assurance.title') }}</h2>
    <p class="mt-2 text-sm text-slate-600">{{ __('assurance.intro') }}</p>
    @if (($readinessHistorical ?? false) || ($passportReadinessHistorical ?? false))
        <p class="mt-2 text-sm text-amber-800">{{ __('assurance.stored', ['date' => $readiness->evaluatedAt->toDateTimeString()]) }}</p>
    @endif
    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        @foreach ($assuranceStatuses as $area => $assessment)
            <div class="rounded-xl border border-slate-200 p-4" data-testid="assurance-{{ $area }}" data-status="{{ $assessment['status'] }}">
                <h3 class="font-semibold text-slate-900">{{ __($assessment['title']) }}</h3>
                <p class="mt-2 text-sm text-slate-700">{{ __($assessment['message']) }}</p>
            </div>
        @endforeach
    </div>
</section>
