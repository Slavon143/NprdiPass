@php
    $workspacePayload = $canViewPassports && is_array($passport?->currentDraftVersion?->payload) ? $passport->currentDraftVersion->payload : [];
    $manufacturingCountries = collect(data_get($workspacePayload, 'data.origin_and_traceability.manufacturing_countries', []))->filter(fn ($country): bool => is_string($country) && trim($country) !== '')->implode(', ');
    $materialNames = collect(data_get($workspacePayload, 'data.materials_and_composition.materials', []))->filter(fn ($material): bool => is_array($material) && is_string($material['name'] ?? null) && trim($material['name']) !== '')->pluck('name')->implode(', ');
    $workspaceScore = $canViewPassports ? $passportReadiness?->score : null;
    $workspaceReady = ! ($passportReadinessHistorical ?? false) && $workspaceScore !== null && in_array($passportReadiness?->status->value, ['ready', 'ready_with_warnings'], true);
@endphp

<div class="product-grid" data-testid="product-workspace-overview">
    <section class="panel product-information">
        <div class="panel-heading">
            <h2>{{ __('Product information') }}</h2>
            @if ($canUpdate)
                <a class="text-button" href="{{ route('catalog.products.edit', ['product' => $product->uuid, 'return' => $productIndexReturnUrl]) }}" aria-label="{{ __('Edit product') }}">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m16 3 5 5-13 13H3v-5L16 3Zm-2 2 5 5" /></svg>{{ __('Edit') }}
                </a>
            @endif
        </div>
        <div class="product-identity">
            <span class="large-icon-tile">
                @if ($product->primaryMedia)
                    <img src="{{ route('catalog.media.content', $product->primaryMedia->uuid) }}" alt="{{ $product->primaryMedia->alt_text ?: $product->name }}" loading="lazy" decoding="async">
                @else
                    <svg width="43" height="43" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" aria-hidden="true"><path d="m12 2 9 5v10l-9 5-9-5V7l9-5ZM3 7l9 5 9-5M12 12v10M7.5 4.5l9 5" /></svg>
                    <span class="sr-only">{{ __('No image') }}</span>
                @endif
            </span>
            <div>
                <span class="status status-green">{{ $product->primaryCategory?->name ?? __('Not assigned') }}</span>
                <h3>{{ $product->name }}</h3>
                @if ($product->short_description)<p class="muted">{{ $product->short_description }}</p>@endif
            </div>
        </div>
        <dl class="data-grid">
            <div><dt>{{ __('Model / SKU') }}</dt><dd>{{ $product->defaultVariant?->sku ?: __('Not set') }}</dd></div>
            <div><dt>{{ __('GTIN') }}</dt><dd>{{ $product->defaultVariant?->gtin ?: __('Not set') }}</dd></div>
            <div><dt>{{ __('Manufacturer') }}</dt><dd>{{ $product->manufacturer ?: __('Not set') }}</dd></div>
            <div><dt>{{ __('Country of manufacture') }}</dt><dd>{{ $manufacturingCountries ?: __('Not set') }}</dd></div>
            <div><dt>{{ __('Materials') }}</dt><dd>{{ $materialNames ?: __('Not set') }}</dd></div>
            <div><dt>{{ __('Data source') }}</dt><dd>{{ $commerceSource }}</dd></div>
        </dl>
        @if ($product->description)
            <div class="information-footer"><p>{{ $product->description }}</p></div>
        @endif
    </section>

    <aside class="panel readiness-side" aria-label="{{ __('Passport readiness') }}" data-testid="product-workspace-readiness">
        <p class="eyebrow">{{ __('PASSPORT READINESS') }}</p>
        <div class="score-big">{{ $workspaceScore ?? '—' }}@if ($workspaceScore !== null)<span>%</span>@endif</div>
        @if ($workspaceScore !== null)
            <div class="auth-progress" role="progressbar" aria-label="{{ __('Product completeness') }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $workspaceScore }}"><div @class(['blocked' => ! $workspaceReady]) style="width: {{ $workspaceScore }}%"></div></div>
            <p>{{ ($passportReadinessHistorical ?? false) ? __('Recalculate readiness before making a publication decision.') : ($workspaceReady ? __('Ready for publication. Review the current draft before publishing.') : __('Complete the required data to create a passport version.')) }}</p>
            @if ($passportReadinessHistorical ?? false)<p class="text-sm">{{ __('Stored readiness evaluation') }} · {{ $passportReadiness->evaluatedAt->toDateTimeString() }}</p>@endif
            <div class="side-stats"><span><strong>{{ $passportReadiness->counts->blockers }}</strong>{{ __('Blockers') }}</span><span><strong>{{ $passportReadiness->counts->warnings }}</strong>{{ __('Warnings') }}</span></div>
        @elseif (! $passport)
            <p>{{ __('No Product Passport has been created for this product. A passport is required to publish public product information.') }}</p>
        @else
            <p>{{ __('No readiness evaluation is available.') }}</p>
        @endif
        @if ($canViewPassports && $passport)
            <a class="btn btn-primary" href="{{ route('catalog.products.passport.readiness', $product->uuid) }}">{{ __('Review readiness') }}</a>
            @if ($canManagePassports)<a class="text-button workspace-edit-passport" href="{{ route('catalog.products.passport.edit', $product->uuid) }}">{{ __('Edit passport') }}</a>@endif
        @elseif (! $passport)
            @include('catalog.products.partials.passport-create-control')
        @endif
    </aside>
</div>
