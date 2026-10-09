@php
    $passport = $passport ?? null;
    $scope = $readiness->profileDetails['regulatory_scope'] ?? ['status' => 'not_assessed'];
    $draft = $passport?->currentDraftVersion;
    $facts = $draft?->payload['regulatory_context'] ?? [];
    $facts = is_array($facts) ? $facts : [];
    $canManageContext = ! ($readinessHistorical ?? false) && $draft && auth()->user()?->can(\App\Enums\CompanyPermission::PassportsManage->value, [$company]);
@endphp
<section class="mb-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" data-testid="regulatory-context">
    <h2 class="text-lg font-semibold text-slate-900">{{ __('dpp.title') }}</h2>
    <p class="mt-2 text-sm text-slate-600">{{ __('dpp.intro') }}</p>
    <p class="mt-2 text-sm font-medium text-slate-800">{{ __('dpp.status.'.$scope['status']) }}</p>
    @if ($scope['source'] ?? null)
        <p class="mt-1 text-xs text-slate-500">{{ $scope['source'] }} · {{ $scope['source_reference'] }}</p>
    @endif
    @if ($canManageContext)
        @if (($readiness->profileDetails['publication_profile_check']['status'] ?? null) === 'stale')
            <form method="POST" action="{{ route('catalog.products.passport.readiness.context', $product->uuid) }}" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
                @csrf
                <input type="hidden" name="expected_revision" value="{{ $draft->draft_revision }}">
                <input type="hidden" name="refresh_profile" value="1">
                <p class="text-sm text-amber-900">{{ __('dpp.refresh_help') }}</p>
                <button class="mt-3 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">{{ __('dpp.refresh') }}</button>
            </form>
        @endif
        <details class="mt-4">
            <summary class="cursor-pointer text-sm font-semibold text-indigo-700">{{ __('dpp.product_type') }}</summary>
            <form method="POST" action="{{ route('catalog.products.passport.readiness.context', $product->uuid) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                @csrf
                <input type="hidden" name="expected_revision" value="{{ $draft->draft_revision }}">
                <label class="text-sm">{{ __('dpp.market') }}
                    <select name="regulatory_context[market]" class="mt-1 block w-full rounded-lg border-slate-300">
                        @foreach (['EU', 'outside_EU'] as $market)
                            <option value="{{ $market }}" @selected(old('regulatory_context.market', $facts['market'] ?? 'EU') === $market)>{{ $market === 'EU' ? 'EU' : __('Outside EU') }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm">{{ __('dpp.product_type') }}
                    <select name="regulatory_context[product_type]" class="mt-1 block w-full rounded-lg border-slate-300">
                        @foreach (\App\Services\Dpp\DppRegulatoryContext::TYPES as $type)
                            <option value="{{ $type }}" @selected(old('regulatory_context.product_type', $facts['product_type'] ?? 'electrical') === $type)>{{ __('dpp.types.'.$type) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm">{{ __('dpp.capacity') }}
                    <input name="regulatory_context[battery_capacity_kwh]" type="number" step="any" min="0" max="1000000" value="{{ old('regulatory_context.battery_capacity_kwh', $facts['battery_capacity_kwh'] ?? '') }}" class="mt-1 block w-full rounded-lg border-slate-300">
                </label>
                <label class="text-sm">{{ __('dpp.level') }}
                    <select name="regulatory_context[passport_level]" class="mt-1 block w-full rounded-lg border-slate-300">
                        @foreach (['model', 'batch', 'item'] as $level)
                            <option value="{{ $level }}" @selected(old('regulatory_context.passport_level', $facts['passport_level'] ?? 'model') === $level)>{{ __($level) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm">{{ __('dpp.lifecycle_event') }}
                    <select name="regulatory_context[lifecycle_event]" class="mt-1 block w-full rounded-lg border-slate-300">
                        @foreach (['initial_placement', 'putting_into_service', 'remanufacturing', 'repurposing'] as $event)
                            <option value="{{ $event }}" @selected(old('regulatory_context.lifecycle_event', $facts['lifecycle_event'] ?? 'initial_placement') === $event)>{{ __('dpp.events.'.$event) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm">{{ __('dpp.market_event_date') }}
                    <input name="regulatory_context[market_event_date]" type="date" value="{{ old('regulatory_context.market_event_date', $facts['market_event_date'] ?? '') }}" class="mt-1 block w-full rounded-lg border-slate-300">
                </label>
                @foreach (['model_identifier', 'item_identifier', 'assessment_source'] as $field)
                    <label class="text-sm {{ $field === 'assessment_source' ? 'sm:col-span-2' : '' }}">{{ __('dpp.'.($field === 'assessment_source' ? 'source' : $field)) }}
                        <input name="regulatory_context[{{ $field }}]" maxlength="{{ $field === 'assessment_source' ? 1000 : 200 }}" @required($field === 'assessment_source') value="{{ old('regulatory_context.'.$field, $facts[$field] ?? '') }}" class="mt-1 block w-full rounded-lg border-slate-300">
                    </label>
                @endforeach
                @if ($errors->any())
                    <ul class="text-sm text-red-700 sm:col-span-2">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                @endif
                <div class="sm:col-span-2"><button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">{{ __('dpp.save') }}</button></div>
            </form>
        </details>
    @endif
</section>
