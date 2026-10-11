<div class="breadcrumbs" aria-label="{{ __('Breadcrumb') }}">
    <a href="{{ $productIndexReturnUrl }}" aria-label="{{ __('Back to products') }}">{{ __('Products') }}</a>
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
    <span>{{ $product->name }}</span>
</div>

<div class="workspace-title">
    <div>
        <p class="eyebrow">{{ __('PRODUCT WORKSPACE') }}</p>
        <h1>{{ $product->name }}</h1>
        @if ($identifierLine !== '')<p class="muted">{{ $identifierLine }}</p>@endif
    </div>
    <div class="actions">
        @if (config('features.production_imports') && auth()->user()?->can(\App\Enums\CompanyPermission::CatalogCreate->value, $company))
            <a class="btn btn-outline" href="{{ route('catalog.imports.index') }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3v12m5-5-5-5-5 5M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /></svg>
                {{ __('Import data') }}
            </a>
        @endif
        @if ($canViewPassports && $product->managed && ! $isArchived && $passport?->currentDraftVersion)
            <a class="btn btn-primary" href="{{ route('catalog.products.passport.preview', $product->uuid) }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s4-8 10-8 10 8 10 8-4 8-10 8S2 12 2 12Z" /><circle cx="12" cy="12" r="3" /></svg>
                {{ __('Preview passport') }}
            </a>
        @endif
    </div>
</div>

<div class="product-meta-strip">
    <span><span class="status status-neutral">{{ $passport?->currentDraftVersion ? __('Draft revision :revision', ['revision' => $passport->currentDraftVersion->draft_revision]) : ucfirst($productStatus) }}</span></span>
    <span>
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="10" /><path d="M2 12h20M12 2c5 5 5 15 0 20-5-5-5-15 0-20" /></svg>
        {{ __('Display language: :language', ['language' => match (app()->getLocale()) { 'en' => 'English', 'sv' => 'Svenska', default => app()->getLocale() }]) }}
    </span>
    <span>
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="10" width="18" height="12" rx="2" /><path d="M7 10V7a5 5 0 0 1 10 0v3" /></svg>
        {{ $company->name }}
    </span>
</div>

<nav class="tab-list workspace-tabs" aria-label="{{ __('Product workspace') }}">
    @if ($readinessWorkspace ?? false)
        <a class="product-tab" href="{{ route('catalog.products.show', $product->uuid) }}">{{ __('Product overview') }}</a>
    @else
        <button type="button" class="product-tab" data-slot="tabs-trigger" @click="tab = 'overview'" :data-state="tab === 'overview' ? 'active' : 'inactive'" :aria-pressed="tab === 'overview'">{{ __('Product overview') }}</button>
    @endif
    @if ($canViewPassports && $passport)
        <a class="product-tab" data-slot="tabs-trigger" @if ($readinessWorkspace ?? false) data-state="active" aria-current="page" @endif href="{{ route('catalog.products.passport.readiness', $product->uuid) }}">{{ __('readiness.label') }}@if ($passportReadiness)<span class="tab-count">{{ $passportReadiness->counts->blockers }}</span>@endif</a>
    @endif
    @if ($canManageDocuments)
        @if ($readinessWorkspace ?? false)
            <a class="product-tab" href="{{ route('catalog.products.show', ['product' => $product->uuid, 'tab' => 'documents']) }}">{{ __('Documents') }}<span class="tab-count">{{ $workspaceDocumentCount }}</span></a>
        @else
            <button type="button" class="product-tab" data-slot="tabs-trigger" @click="tab = 'documents'" :data-state="tab === 'documents' ? 'active' : 'inactive'" :aria-pressed="tab === 'documents'">{{ __('Documents') }}<span class="tab-count">{{ $documents->count() }}</span></button>
        @endif
    @endif
</nav>
