    @if($company->commercial_model_version === 1)
        <div class="mx-auto mb-4 max-w-7xl rounded-lg border border-slate-200 bg-white p-4">
            <p class="text-sm font-semibold text-slate-900">{{ $product->managed ? __('Managed in DPP') : __('Unmanaged catalogue product') }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ __('DPP enrollment uses one product slot. Variants and publication versions do not add slots. Removing enrollment retains published history.') }}</p>
            <x-input-error :messages="$errors->get('entitlement')" class="mt-3" />
            <x-input-error :messages="$errors->get('managed')" class="mt-3" />
            @can('update', $product)
                @if(! app(\App\Services\Billing\CommercialAccessPolicy::class)->isReadOnly($company))
                    <form method="POST" action="{{ route('catalog.products.managed', $product->uuid) }}" class="mt-3">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="managed" value="{{ $product->managed ? '0' : '1' }}">
                        <button type="submit" class="rounded-lg border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700">{{ $product->managed ? __('Remove DPP enrollment') : __('Enroll in DPP') }}</button>
                    </form>
                @endif
            @endcan
        </div>
    @endif
        @if ($product->primaryCategory?->activeTemplate)
            <section class="mt-6 rounded-lg border {{ $taxonomyDiagnostics['needs_review'] ? 'border-amber-300 bg-amber-50' : 'border-emerald-200 bg-emerald-50' }} p-5 shadow-sm" data-testid="taxonomy-review-card">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-semibold text-slate-900">{{ __('Category taxonomy') }}</h2>
                            <x-badge :tone="$taxonomyDiagnostics['needs_review'] ? 'amber' : 'emerald'">{{ $taxonomyDiagnostics['needs_review'] ? __('Needs review') : __('Reviewed') }}</x-badge>
                        </div>
                        <p class="mt-1 text-sm text-slate-600">{{ $product->primaryCategory->activeTemplate->name }} · v{{ $product->primaryCategory->activeTemplate->version }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ __('Effective readiness profile') }}: {{ $effectiveReadinessProfile->name }} ({{ $effectiveReadinessProfile->code }} v{{ $effectiveReadinessProfile->version }})</p>

                        @if ($taxonomyDiagnostics['missing_required'] !== [])
                            <p class="mt-3 text-sm font-semibold text-amber-950">{{ __('Required before acknowledgement:') }}</p>
                            <ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-amber-900">
                                @foreach ($taxonomyDiagnostics['missing_required'] as $definition)
                                    <li>{{ $definition->name }} <span class="font-mono text-xs">{{ $definition->code }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($taxonomyDiagnostics['missing_recommended'] !== [])
                            <p class="mt-3 text-sm text-slate-700">{{ __('Recommended:') }} {{ collect($taxonomyDiagnostics['missing_recommended'])->pluck('name')->join(', ') }}</p>
                        @endif
                    </div>

                    @if ($taxonomyDiagnostics['needs_review'] && $canUpdate)
                        <div class="flex shrink-0 flex-col gap-2">
                            @if ($taxonomyDiagnostics['missing_required'] !== [])
                                <a href="{{ route('catalog.products.show', ['product' => $product->uuid, 'tab' => 'product-data']) }}" class="rounded-lg bg-emerald-900 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-emerald-800">{{ __('Complete attributes') }}</a>
                            @else
                                <form method="POST" action="{{ route('catalog.products.taxonomy.acknowledge', $product->uuid) }}">
                                    @csrf
                                    <x-primary-button data-testid="acknowledge-taxonomy">{{ __('Acknowledge review') }}</x-primary-button>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            </section>
        @endif

            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-lg font-bold text-[#06112f]">{{ __('Commerce') }}</h2>
                <dl class="mt-4 grid gap-x-8 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('Sales price') }}</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900">
                            @if (is_string($salesPrice) && $salesPrice !== '')
                                {{ $salesPrice }} {{ $commerceCurrency }}
                            @else
                                {{ __('Not set') }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('VAT') }}</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900">{{ $commerceVatLabel }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('Source') }}</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900">{{ $commerceSource }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('Last synced') }}</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900">{{ $commerceLastSynced }}</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-bold text-[#06112f]">{{ __('Attributes') }}</h2>
                    <a href="{{ route('catalog.products.attributes.edit', $product->uuid) }}" class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">{{ $canManageAttributes ? __('Manage') : __('View') }}</a>
                </div>
                <p class="mt-2 text-sm text-slate-700">
                    {{ __(':completed of :total attributes completed', ['completed' => $completedAttributeCount, 'total' => $totalAttributeDefinitions]) }}
                </p>
                @if ($missingAttributeCount > 0)
                    <p class="mt-1 text-sm text-slate-500">
                        {{ trans_choice(':count attribute is not set|:count attributes are not set', $missingAttributeCount, ['count' => $missingAttributeCount]) }}@if ($requiredMissingAttributeCount > 0) · <span class="font-semibold text-red-700">{{ trans_choice(':count required attribute is missing|:count required attributes are missing', $requiredMissingAttributeCount, ['count' => $requiredMissingAttributeCount]) }}</span>@endif
                    </p>
                    @if ($canManageAttributes)
                        <a href="{{ route('catalog.products.attributes.edit', $product->uuid) }}{{ $requiredMissingAttributeCount > 0 ? '#required-attributes' : '' }}" class="mt-3 inline-flex rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-slate-50">
                            {{ $requiredMissingAttributeCount > 0 ? __('Fix required attributes') : __('Complete attributes') }}
                        </a>
                    @endif
                @endif
            </section>

            <div class="grid gap-4 sm:grid-cols-3">
                <section id="media" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">{{ __('Images') }}</h2>
                    @if ($product->primaryMedia)
                        <img src="{{ route('catalog.media.content', $product->primaryMedia->uuid) }}" alt="{{ $product->primaryMedia->alt_text ?: $product->name }}" loading="lazy" decoding="async" class="mt-3 h-28 w-full rounded-lg bg-slate-100 object-contain">
                    @else
                        <div class="mt-3 flex h-28 flex-col items-center justify-center gap-2 rounded-lg bg-slate-100 text-slate-400">
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <rect x="3" y="3" width="18" height="18" rx="2" />
                                <circle cx="8.5" cy="8.5" r="1.5" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 15-5-5L5 21" />
                            </svg>
                            <span class="text-xs font-medium">{{ __('No primary image') }}</span>
                        </div>
                    @endif
                    <p class="mt-3 text-sm font-semibold text-slate-900">{{ trans_choice(':count image|:count images', $product->product_media_count, ['count' => $product->product_media_count]) }}</p>
                    <a href="{{ route('catalog.products.media.index', $product->uuid) }}" class="mt-1 inline-block text-sm font-semibold text-emerald-800 hover:text-emerald-950">{{ $canManageMedia ? __('Manage images') : __('View images') }}</a>
                </section>

                <section id="variants-overview" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">{{ __('Variants') }}</h2>
                    <p class="mt-3 text-sm font-semibold text-slate-900">{{ trans_choice(':count variant|:count variants', $product->variants_count, ['count' => $product->variants_count]) }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Identifiers such as SKU, GTIN and MPN live on each variant.') }}</p>
                    <a href="{{ route('catalog.products.variants.index', $product->uuid) }}" class="mt-2 inline-block text-sm font-semibold text-emerald-800 hover:text-emerald-950">{{ __('Manage variants') }}</a>
                </section>

                @if ($canManageDocuments)
                    <section id="documents-overview" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">{{ __('Documents') }}</h2>
                        <p class="mt-3 text-sm font-semibold text-slate-900">{{ trans_choice(':count document|:count documents', $documents->count(), ['count' => $documents->count()]) }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ __(':approved approved · :review awaiting review', ['approved' => $documentApprovedCount, 'review' => $documentAwaitingReviewCount]) }}</p>
                        <a href="{{ route('catalog.products.documents.index', $product->uuid) }}" class="mt-2 inline-block text-sm font-semibold text-emerald-800 hover:text-emerald-950">{{ __('Manage documents') }}</a>
                    </section>
                @endif
            </div>

            <p class="text-xs text-slate-500">
                {{ __('Last updated :date by :user', ['date' => $updatedLabel ?? '—', 'user' => $product->updatedBy?->name ?? __('Unknown')]) }}
            </p>
