<x-app-layout>
    <x-input-error :messages="$errors->get('passport')" class="mb-4" />
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
    @php
        $productStatus = $product->status->value;
        $statusTone = match ($productStatus) {
            'active' => 'emerald',
            'archived' => 'slate',
            default => 'slate',
        };
        $isArchived = $productStatus === 'archived';
        $allowedTabs = ['overview', 'product-data', 'passport', 'documents', 'variants', 'activity'];
        $initialTab = in_array((string) request('tab'), $allowedTabs, true) ? (string) request('tab') : 'overview';

        $totalAttributeDefinitions = $attributeDefinitions->count();
        $completedAttributePairs = [];
        foreach ($attributeDefinitions as $definition) {
            if ($attributeValues->has($definition->getKey())) {
                $completedAttributePairs[] = ['definition' => $definition, 'value' => $attributeValues->get($definition->getKey())];
            }
        }
        $completedAttributeCount = count($completedAttributePairs);
        $missingAttributeCount = $totalAttributeDefinitions - $completedAttributeCount;
        $requiredMissingAttributeCount = $attributeDefinitions
            ->filter(fn (App\Models\Catalog\AttributeDefinition $definition): bool => $definition->required && ! $attributeValues->has($definition->getKey()))
            ->count();
        $inlineAttributes = array_slice($completedAttributePairs, 0, 2);

        $identifierLine = trim(($product->brand ?: '').($product->defaultVariant?->sku ? ' · '.$product->defaultVariant->sku : ''));

        $updatedAt = $product->updated_at;
        $updatedLabel = $updatedAt instanceof \Illuminate\Support\Carbon
            ? $updatedAt->format($updatedAt->year === now()->year ? 'j M Y' : 'j M Y')
            : null;

        $commerce = is_array($product->commerce) ? $product->commerce : [];
        $salesPrice = $commerce['sales_price'] ?? null;
        $commerceCurrency = is_string($commerce['currency'] ?? null) ? $commerce['currency'] : 'SEK';
        $commerceVatCode = is_string($commerce['vat_code'] ?? null) ? $commerce['vat_code'] : null;
        $commerceVatLabel = $commerceVatCode === 'SE25' ? '25%' : ($commerceVatCode ?: __('Not set'));
        $commerceSource = is_string($commerce['source'] ?? null) ? $commerce['source'] : __('Not set');
        $commerceLastSynced = isset($commerce['last_synced_at']) && is_string($commerce['last_synced_at'])
            ? \Illuminate\Support\Carbon::parse($commerce['last_synced_at'])->format('Y-m-d H:i')
            : __('Not synced');
    @endphp

    <div class="np-workspace mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8" data-testid="product-show-page" x-data="{ tab: '{{ $initialTab }}' }">
        <div class="border-b border-slate-200 pb-6">
            <nav class="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-500" aria-label="{{ __('Breadcrumb') }}">
                <a href="{{ route('catalog.products.index') }}" class="hover:text-emerald-800">{{ __('Catalog') }}</a>
                <span aria-hidden="true">/</span>
                <a href="{{ $productIndexReturnUrl }}" class="hover:text-emerald-800">{{ __('Products') }}</a>
                <span aria-hidden="true">/</span>
                <span class="text-slate-700">{{ __('Product details') }}</span>
            </nav>

            <div class="mt-5 flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                <div class="flex min-w-0 items-start gap-4 sm:gap-5">
                    <div class="grid h-20 w-20 shrink-0 place-items-center overflow-hidden rounded-lg border border-slate-200 bg-slate-100 text-slate-400">
                        @if ($product->primaryMedia)
                            <img src="{{ route('catalog.media.content', $product->primaryMedia->uuid) }}" alt="{{ $product->primaryMedia->alt_text ?: $product->name }}" class="h-full w-full object-contain" loading="lazy" decoding="async">
                        @else
                            <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <rect x="3" y="3" width="18" height="18" rx="2" />
                                <circle cx="8.5" cy="8.5" r="1.5" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 15-5-5L5 21" />
                            </svg>
                            <span class="sr-only">{{ __('No image') }}</span>
                        @endif
                    </div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-3">
                            <h1 class="break-words text-3xl font-bold text-[#06112f]">{{ $product->name }}</h1>
                            <x-badge :tone="$statusTone">{{ ucfirst($productStatus) }}</x-badge>
                        </div>
                        @if ($identifierLine !== '')
                            <p class="mt-1 text-sm font-medium text-slate-600">{{ $identifierLine }}</p>
                        @endif
                        @if ($product->short_description)
                            <p class="mt-2 max-w-3xl text-sm text-slate-600">{{ $product->short_description }}</p>
                        @endif
                    </div>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <a href="{{ $productIndexReturnUrl }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Back to products') }}</a>
                    @if ($canUpdate)
                        <a href="{{ route('catalog.products.edit', ['product' => $product->uuid, 'return' => $productIndexReturnUrl]) }}" class="rounded-lg bg-emerald-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">{{ __('Edit product') }}</a>
                    @endif
                    @if ($canActivate || $canReturnToDraft || $canArchive || $canRestore)
                        <div class="relative" x-data="positionedMenu">
                            <button type="button" x-ref="button" @click="toggle" :aria-expanded="open" aria-haspopup="menu" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                <span>{{ __('More') }}</span>
                                <svg class="h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                                </svg>
                            </button>
                            <div x-ref="menu" x-cloak x-show="open" @click.outside="open = false" @keydown.escape.window="open = false" role="menu" class="fixed z-50 w-56 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-xl">
                                @if ($canActivate)
                                    <form method="POST" action="{{ route('catalog.products.activate', $product->uuid) }}">
                                        @csrf
                                        <button type="submit" role="menuitem" @disabled(!$readiness->ready) @if(!$readiness->ready) title="{{ __('Resolve all blockers before activation.') }}" @endif class="block w-full px-4 py-2 text-left text-sm text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:text-slate-400">{{ __('Activate product') }}</button>
                                    </form>
                                @endif
                                @if ($canReturnToDraft)
                                    <form method="POST" action="{{ route('catalog.products.return-to-draft', $product->uuid) }}">
                                        @csrf
                                        <button type="submit" role="menuitem" class="block w-full px-4 py-2 text-left text-sm text-slate-700 transition hover:bg-slate-50">{{ __('Return to draft') }}</button>
                                    </form>
                                @endif
                                @if ($canArchive && ! $isArchived)
                                    <form method="POST" action="{{ route('catalog.products.destroy', $product->uuid) }}" onsubmit="return confirm('{{ __('Archive this product? It will be hidden from normal catalog work, but passport history will be kept.') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" role="menuitem" class="block w-full px-4 py-2 text-left text-sm text-red-700 transition hover:bg-red-50">{{ __('Archive product') }}</button>
                                    </form>
                                @endif
                                @if ($canRestore && $isArchived)
                                    <form method="POST" action="{{ route('catalog.products.restore', $product->uuid) }}">
                                        @csrf
                                        <button type="submit" role="menuitem" class="block w-full px-4 py-2 text-left text-sm text-slate-700 transition hover:bg-slate-50">{{ __('Restore to draft') }}</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <nav class="mt-1 flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="{{ __('Product workspace') }}" role="tablist">
            <button type="button" role="tab" @click="tab = 'overview'" :aria-selected="tab === 'overview'" :class="tab === 'overview' ? 'border-emerald-800 text-emerald-800' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'" class="whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-inset focus:ring-emerald-700">{{ __('Overview') }}</button>
            <button type="button" role="tab" @click="tab = 'product-data'" :aria-selected="tab === 'product-data'" :class="tab === 'product-data' ? 'border-emerald-800 text-emerald-800' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'" class="whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-inset focus:ring-emerald-700">{{ __('Product data') }}</button>
            <button type="button" role="tab" @click="tab = 'passport'" :aria-selected="tab === 'passport'" :class="tab === 'passport' ? 'border-emerald-800 text-emerald-800' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'" class="whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-inset focus:ring-emerald-700">{{ __('Passport') }}</button>
            @if ($canManageDocuments)
                <button type="button" role="tab" @click="tab = 'documents'" :aria-selected="tab === 'documents'" :class="tab === 'documents' ? 'border-emerald-800 text-emerald-800' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'" class="whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-inset focus:ring-emerald-700">{{ __('Documents') }}</button>
            @endif
            <button type="button" role="tab" @click="tab = 'variants'" :aria-selected="tab === 'variants'" :class="tab === 'variants' ? 'border-emerald-800 text-emerald-800' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'" class="whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-inset focus:ring-emerald-700">{{ __('Variants') }}</button>
            <button type="button" role="tab" @click="tab = 'activity'" :aria-selected="tab === 'activity'" :class="tab === 'activity' ? 'border-emerald-800 text-emerald-800' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'" class="whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-inset focus:ring-emerald-700">{{ __('Activity') }}</button>
        </nav>

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

        {{-- Overview --}}
        <section x-cloak x-show="tab === 'overview'" role="tabpanel" class="mt-6 space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                @if ($passport instanceof \App\Models\Passports\ProductPassport)
                    @php
                        $passportReadinessStatus = $passportReadiness?->status->value ?? null;
                        $passportReadinessScore = $passportReadiness?->score ?? null;
                        $passportBlockers = (int) ($passportReadiness?->counts->blockers ?? 0);
                        $passportReady = $passportReadinessStatus === 'ready';
                        $passportScoreTone = match (true) {
                            $passportReady => 'text-emerald-700',
                            $passportReadinessScore !== null && $passportReadinessScore >= 80 => 'text-amber-700',
                            default => 'text-red-700',
                        };
                    @endphp
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-[#06112f]">{{ __('Passport readiness') }}</h2>
                            <p class="mt-1 text-xs text-slate-500">{{ __('Checks the current passport draft against publication requirements.') }}</p>
                        </div>
                        @php
                            $passportStatusLabel = match ($passport->status->value) {
                                'draft' => __('Draft'),
                                'published' => __('Published'),
                                'unpublished' => __('Unpublished'),
                                'archived' => __('Archived'),
                                default => ucfirst($passport->status->value),
                            };
                        @endphp
                        <x-badge :tone="$passport->status->value === 'published' ? 'emerald' : ($passport->status->value === 'draft' ? 'indigo' : 'slate')">{{ $passportStatusLabel }}</x-badge>
                    </div>

                    @if ($passportBlockers > 0)
                        <p class="np-callout mt-4 font-semibold">{{ trans_choice(':count blocker must be resolved before this draft can be published.|:count blockers must be resolved before this draft can be published.', $passportBlockers, ['count' => $passportBlockers]) }}</p>
                    @endif
                    <div class="mt-4 flex items-baseline gap-3">
                        <span class="text-3xl font-bold tracking-tight {{ $passportScoreTone }}">{{ $passportReadinessScore !== null ? $passportReadinessScore.'%' : '—' }}</span>
                        <span class="text-sm font-semibold {{ $passportReady ? 'text-emerald-700' : 'text-amber-700' }}">{{ $passportReady ? __('Ready') : __('Needs attention') }}</span>
                    </div>

                    @if ($passportBlockers === 0 && ! $passportReady)
                        <p class="mt-2 text-sm text-slate-600">{{ __('Warnings and recommendations should be reviewed before publishing.') }}</p>
                    @elseif ($passportReady)
                        <p class="mt-2 text-sm text-slate-600">{{ __('The current draft is ready for publication.') }}</p>
                    @endif

                    <div class="mt-5 flex flex-wrap gap-3 border-t border-slate-200 pt-5">
                        <a href="{{ route('catalog.products.passport.readiness', $product->uuid) }}" class="rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                            {{ $passportBlockers > 0 ? trans_choice('Review :count blocker|Review :count blockers', $passportBlockers, ['count' => $passportBlockers]) : __('Review readiness') }}
                        </a>
                        @if ($canManagePassports)
                            <a href="{{ route('catalog.products.passport.edit', $product->uuid) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-slate-50">{{ __('Edit passport') }}</a>
                        @else
                            <a href="{{ route('catalog.products.passport.readiness', $product->uuid) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-slate-50">{{ __('View readiness') }}</a>
                        @endif
                    </div>
                @else
                    <h2 class="text-lg font-bold text-[#06112f]">{{ __('Passport') }}</h2>
                    <p class="mt-2 text-sm text-slate-500">{{ __('No Product Passport has been created for this product. A passport is required to publish public product information.') }}</p>
                    @include('catalog.products.partials.passport-create-control')
                @endif
            </section>

            <details id="readiness" {{ $productStatus === 'draft' && ! $readiness->ready ? 'open' : '' }} class="group rounded-lg border border-slate-200 bg-white shadow-sm">
                <summary class="flex cursor-pointer select-none items-center justify-between gap-4 px-5 py-4 sm:px-6">
                    <div class="flex flex-wrap items-center gap-3">
                        <h2 class="text-lg font-bold text-[#06112f]">{{ __('Catalog readiness') }}</h2>
                        @if ($productStatus === 'draft')
                            <x-badge :tone="$readiness->ready ? 'emerald' : 'red'">{{ $readiness->ready ? __('Ready') : __('Not ready') }}</x-badge>
                            <p class="text-xs text-slate-500">{{ __('Evaluated :time', ['time' => $readiness->checkedAt->format('Y-m-d H:i:s')]) }}</p>
                        @elseif ($productStatus === 'active')
                            <x-badge tone="emerald">{{ __('Active') }}</x-badge>
                        @else
                            <x-badge tone="amber">{{ __('Archived') }}</x-badge>
                        @endif
                    </div>
                    <svg class="h-5 w-5 shrink-0 text-slate-400 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </summary>
                <div class="border-t border-slate-200 px-5 py-4 sm:px-6">
                    @if ($productStatus === 'draft')
                        <p class="text-sm text-slate-600">{{ __('Catalog readiness checks whether the product can be activated in the catalog. It is separate from passport publication readiness.') }}</p>
                    @elseif ($productStatus === 'active')
                        <p class="text-sm text-slate-600">{{ __('This product is already active. Activation checks are only needed while the product is a draft.') }}</p>
                    @else
                        <p class="text-sm text-slate-600">{{ __('This product is archived and hidden from normal catalog work until it is restored.') }}</p>
                    @endif

                    @php
                        $readinessHref = static function ($item) use ($product, $canUpdate, $canManageAttributes, $canManageMedia): string {
                            $fallback = '#'.$item->section;
                            $defaultVariant = $product->defaultVariant;

                            return match ($item->code) {
                                'invalid_product_status' => '#lifecycle-actions',
                                'missing_product_name' => $canUpdate ? route('catalog.products.edit', $product->uuid).'#name' : $fallback,
                                'missing_product_slug' => $canUpdate ? route('catalog.products.edit', $product->uuid).'#slug' : $fallback,
                                'missing_product_brand' => $canUpdate ? route('catalog.products.edit', $product->uuid).'#brand' : $fallback,
                                'missing_product_manufacturer' => $canUpdate ? route('catalog.products.edit', $product->uuid).'#manufacturer' : $fallback,
                                'missing_primary_category', 'invalid_primary_category', 'archived_primary_category', 'archived_secondary_category' => $canUpdate ? route('catalog.products.edit', $product->uuid).'#primary_category_uuid' : $fallback,
                                'missing_primary_media', 'missing_primary_media_file' => $canManageMedia ? route('catalog.products.media.index', $product->uuid).'#product-image-management' : $fallback,
                                'missing_default_variant', 'invalid_default_variant', 'no_available_variants' => route('catalog.products.variants.index', $product->uuid),
                                'missing_variant_sku' => $canUpdate && $defaultVariant ? route('catalog.products.variants.edit', [$product->uuid, $defaultVariant->uuid]).'#sku' : $fallback,
                                'missing_variant_gtin' => $canUpdate && $defaultVariant ? route('catalog.products.variants.edit', [$product->uuid, $defaultVariant->uuid]).'#gtin' : $fallback,
                                'missing_required_product_attribute' => $canManageAttributes ? route('catalog.products.attributes.edit', $product->uuid).'#required-attributes' : $fallback,
                                'missing_required_variant_attribute' => $canManageAttributes && $defaultVariant ? route('catalog.products.variants.attributes.edit', [$product->uuid, $defaultVariant->uuid]).'#required-attributes' : $fallback,
                                'invalid_attribute_value', 'archived_attribute_option' => $canManageAttributes && $item->entityType === 'ProductVariant' && $defaultVariant
                                    ? route('catalog.products.variants.attributes.edit', [$product->uuid, $defaultVariant->uuid]).'#required-attributes'
                                    : ($canManageAttributes ? route('catalog.products.attributes.edit', $product->uuid).'#required-attributes' : $fallback),
                                default => $fallback,
                            };
                        };
                    @endphp

                    @if ($readiness->blockers !== [])
                        <div class="mt-4">
                            <h3 class="text-sm font-semibold text-red-800">{{ __('Blockers') }}</h3>
                            <ul class="mt-2 space-y-2">
                                @foreach ($readiness->blockers as $item)
                                    <li class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">
                                        <a href="{{ $readinessHref($item) }}" class="font-medium underline decoration-red-300 underline-offset-2 hover:decoration-red-700">{{ $item->message }} <span class="whitespace-nowrap">{{ __('Open →') }}</span></a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($readiness->warnings !== [])
                        <div class="mt-4">
                            <h3 class="text-sm font-semibold text-amber-800">{{ __('Warnings') }}</h3>
                            <ul class="mt-2 space-y-2">
                                @foreach ($readiness->warnings as $item)
                                    <li class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                        <a href="{{ $readinessHref($item) }}" class="underline decoration-amber-300 underline-offset-2 hover:decoration-amber-700">{{ $item->message }} <span class="whitespace-nowrap">{{ __('Open →') }}</span></a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div id="lifecycle-actions" class="mt-4 flex scroll-mt-24 flex-wrap gap-3 border-t border-slate-200 pt-4">
                        @if ($productStatus === 'draft' && $canActivate)
                            <form method="POST" action="{{ route('catalog.products.activate', $product->uuid) }}">
                                @csrf
                                <button type="submit" @disabled(!$readiness->ready) class="rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-slate-300">{{ __('Activate product') }}</button>
                            </form>
                        @endif
                        @if ($productStatus === 'active' && $canReturnToDraft)
                            <form method="POST" action="{{ route('catalog.products.return-to-draft', $product->uuid) }}">
                                @csrf
                                <button type="submit" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-slate-50">{{ __('Return to draft') }}</button>
                            </form>
                        @endif
                        @if (! $isArchived && $canArchive)
                            <form method="POST" action="{{ route('catalog.products.destroy', $product->uuid) }}" onsubmit="return confirm('{{ __('Archive this product? It will be hidden from normal catalog work, but passport history will be kept.') }}')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">{{ __('Archive product') }}</button>
                            </form>
                        @endif
                        @if ($isArchived && $canRestore)
                            <form method="POST" action="{{ route('catalog.products.restore', $product->uuid) }}">
                                @csrf
                                <button type="submit" class="rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">{{ __('Restore to draft') }}</button>
                            </form>
                        @endif
                    </div>
                    @if ($productStatus === 'draft' && ! $readiness->ready)
                        <p class="mt-3 text-sm text-slate-600">{{ __('Resolve all blockers before activation.') }}</p>
                    @endif
                    <x-input-error :messages="$errors->get('lifecycle')" class="mt-3" />
                </div>
            </details>

            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-bold text-[#06112f]">{{ __('Product information') }}</h2>
                    @if ($canUpdate)
                        <a href="{{ route('catalog.products.edit', ['product' => $product->uuid, 'return' => $productIndexReturnUrl]) }}" class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">{{ __('Edit') }}</a>
                    @endif
                </div>
                <dl class="mt-4 grid gap-x-8 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('Brand') }}</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900">{{ $product->brand ?: __('Not set') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('Manufacturer') }}</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900">{{ $product->manufacturer ?: __('Not set') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('Primary category') }}</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900">{{ $product->primaryCategory?->name ?? __('Not assigned') }}</dd>
                    </div>
                    @foreach ($inlineAttributes as $inlineAttribute)
                        <div>
                            <dt class="text-sm text-slate-500">{{ $inlineAttribute['definition']->name }}</dt>
                            <dd class="mt-0.5 font-semibold text-slate-900">{{ $attributeFormatter->format($inlineAttribute['value']) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

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
        </section>

        {{-- Product data --}}
        <section x-cloak x-show="tab === 'product-data'" role="tabpanel" class="mt-6 space-y-6">
            <section id="details" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-bold text-[#06112f]">{{ __('Product details') }}</h2>
                    @if ($canUpdate)
                        <a href="{{ route('catalog.products.edit', ['product' => $product->uuid, 'return' => $productIndexReturnUrl]) }}" class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">{{ __('Edit') }}</a>
                    @endif
                </div>
                <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div><dt class="text-sm text-slate-500">{{ __('Brand') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $product->brand ?: __('Not set') }}</dd></div>
                    <div><dt class="text-sm text-slate-500">{{ __('Manufacturer') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $product->manufacturer ?: __('Not set') }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-sm text-slate-500">{{ __('Short description') }}</dt><dd class="mt-1 whitespace-pre-line text-slate-800">{{ $product->short_description ?: __('Not set') }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-sm text-slate-500">{{ __('Description') }}</dt><dd class="mt-1 whitespace-pre-line text-slate-800">{{ $product->description ?: __('Not set') }}</dd></div>
                </dl>
            </section>

            <section id="categories" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-lg font-bold text-[#06112f]">{{ __('Categories') }}</h2>
                <p class="mt-2 text-sm text-slate-600">{{ __('Primary:') }} <span class="font-semibold text-slate-900">{{ $product->primaryCategory?->name ?? __('Not assigned') }}</span></p>
                <div class="mt-4 flex flex-wrap gap-2">
                    @forelse ($product->categories as $category)
                        <x-badge :tone="$product->primaryCategory?->is($category) ? 'indigo' : 'slate'">{{ $category->name }}</x-badge>
                    @empty
                        <span class="text-sm text-slate-500">{{ __('No categories assigned.') }}</span>
                    @endforelse
                </div>
            </section>

            <section id="attributes" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-bold text-[#06112f]">{{ __('Product Attributes') }}</h2>
                    @if ($canManageAttributes)
                        <a href="{{ route('catalog.products.attributes.edit', $product->uuid) }}" class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">{{ __('Edit attributes') }}</a>
                    @endif
                </div>
                <dl class="mt-4 divide-y divide-slate-100">
                    @forelse ($attributeDefinitions as $definition)
                        @php
                            $attributeValue = $attributeValues->get($definition->getKey());
                            $hasArchivedOption = $attributeValue && ($attributeValue->selectedOption?->status?->value === 'archived' || $attributeValue->selectedOptions->contains(fn ($option) => $option->status->value === 'archived'));
                        @endphp
                        <div class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <dt class="flex items-center gap-2 text-sm font-medium text-slate-700">{{ $definition->name }}@if ($definition->required)<x-badge tone="amber">{{ __('Required') }}</x-badge>@endif</dt>
                            <dd class="text-sm font-semibold text-slate-900">
                                @if ($attributeValue)
                                    {{ $attributeFormatter->format($attributeValue) }}
                                    @if ($hasArchivedOption)<x-badge tone="gray">{{ __('Archived option') }}</x-badge>@endif
                                @elseif ($definition->required)
                                    <x-badge tone="red">{{ __('Missing required value') }}</x-badge>
                                @else
                                    <span class="font-normal text-slate-400">{{ __('Not set') }}</span>
                                @endif
                            </dd>
                        </div>
                    @empty
                        <p class="py-4 text-sm text-slate-500">{{ __('No Product attributes are defined.') }}</p>
                    @endforelse
                    @foreach ($archivedAttributeValues as $attributeValue)
                        <div class="flex items-center justify-between gap-4 py-3">
                            <dt class="text-sm text-slate-600">{{ $attributeValue->definition->name }} <x-badge tone="gray">{{ __('Archived') }}</x-badge></dt>
                            <dd class="text-sm font-semibold">{{ $attributeFormatter->format($attributeValue) }} @if ($attributeValue->selectedOption?->status?->value === 'archived')<x-badge tone="gray">{{ __('Archived option') }}</x-badge>@endif</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        </section>

        {{-- Passport --}}
        <section x-cloak x-show="tab === 'passport'" role="tabpanel" class="mt-6">
            <section id="passport" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-[#06112f]">{{ __('Product Passport') }}</h2>
                        <p class="mt-1 text-xs text-slate-500">{{ __('Digital Product Passport containing product information, safety instructions, documents and recycling information.') }}</p>
                        @if ($passport instanceof \App\Models\Passports\ProductPassport && $passport->status->value === 'published')
                            <p class="mt-1 text-xs text-slate-500">{{ __('Published means the last passport version is public. The readiness below checks the current draft and may change after edits.') }}</p>
                        @endif
                    </div>
                    @if ($passport instanceof \App\Models\Passports\ProductPassport)
                        @php
                            $passportStatusLabel = match ($passport->status->value) {
                                'draft' => __('Draft'),
                                'published' => __('Published'),
                                'unpublished' => __('Unpublished'),
                                'archived' => __('Archived'),
                                default => ucfirst($passport->status->value),
                            };
                        @endphp
                        <x-badge :tone="$passport->status->value === 'draft' ? 'indigo' : ($passport->status->value === 'published' ? 'emerald' : 'slate')">{{ $passportStatusLabel }}</x-badge>
                    @else
                        <x-badge>{{ __('Not created') }}</x-badge>
                    @endif
                </div>

                @if ($passport instanceof \App\Models\Passports\ProductPassport)
                    <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs text-slate-500">{{ __('Default language') }}</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $passport->default_language }}</dd>
                        </div>
                        @if ($passport->currentDraftVersion)
                            <div>
                                <dt class="text-xs text-slate-500">{{ __('Draft revision') }}</dt>
                                <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $passport->currentDraftVersion->draft_revision }}</dd>
                            </div>
                        @endif
                        @if ($passportReadinessHistorical ?? false)
                            <div>
                                <dt class="text-xs text-slate-500">{{ $passportReadiness ? __('Stored readiness evaluation') : __('No stored readiness evaluation') }}</dt>
                                @if($passportReadiness)
                                    <dd class="mt-1 text-xs text-slate-600">{{ $passportReadiness->evaluatedAt->toDateTimeString() }}</dd>
                                @endif
                            </div>
                        @endif
                        @if ($passportReadiness)
                            <div>
                                <dt class="text-xs text-slate-500">{{ __('readiness.label') }}</dt>
                                <dd class="mt-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold @if ($passportReadiness->score >= 80) text-emerald-700 @elseif ($passportReadiness->score >= 50) text-amber-700 @else text-red-700 @endif">{{ $passportReadiness->score }}%</span>
                                        <x-badge :tone="$passportReadiness->status->value === 'ready' ? 'emerald' : ($passportReadiness->status->value === 'ready_with_warnings' ? 'amber' : 'red')">{{ $passportReadiness->status->value === 'ready' ? __('Ready') : __('Needs attention') }}</x-badge>
                                    </div>
                                </dd>
                            </div>
                        @endif
                    </dl>
                    @if ($passportReadiness)
                        <div class="mt-4 flex flex-wrap gap-2 text-xs">
                            <span class="rounded-full bg-red-100 px-2 py-0.5 font-semibold text-red-800">{{ __(':count blockers', ['count' => $passportReadiness->counts->blockers]) }}</span>
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-800">{{ __(':count warnings', ['count' => $passportReadiness->counts->warnings]) }}</span>
                            <span class="rounded-full bg-blue-100 px-2 py-0.5 font-semibold text-blue-800">{{ __(':count recommendations', ['count' => $passportReadiness->counts->recommendations]) }}</span>
                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 font-semibold text-emerald-800">{{ __(':count passed', ['count' => $passportReadiness->counts->passed]) }}</span>
                        </div>
                    @endif
                    <div class="mt-5 flex flex-wrap gap-3 border-t border-slate-200 pt-5">
                        @if ($canManagePassports)
                            <a href="{{ route('catalog.products.passport.edit', $product->uuid) }}" class="rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">{{ __('Edit Passport') }}</a>
                        @endif
                        <a href="{{ route('catalog.products.passport.readiness', $product->uuid) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-slate-50">{{ __('View readiness report') }}</a>
                        <a href="{{ route('catalog.products.passport.qr.show', $product->uuid) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-slate-50">{{ $passport->status->value === 'published' ? __('View QR') : __('Preview QR') }}</a>
                    </div>
                @else
                    <p class="mt-4 text-sm text-slate-500">{{ __('No Product Passport has been created for this product.') }}</p>
                    @include('catalog.products.partials.passport-create-control')
                @endif
            </section>
        </section>

        {{-- Documents --}}
        @if ($canManageDocuments)
            <section x-cloak x-show="tab === 'documents'" role="tabpanel" class="mt-6">
                <section id="documents" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-bold text-[#06112f]">{{ __('Documents') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ trans_choice(':count document|:count documents', $documents->count(), ['count' => $documents->count()]) }} · {{ __(':approved approved', ['approved' => $documentApprovedCount]) }} · {{ __(':review awaiting review', ['review' => $documentAwaitingReviewCount]) }}
                            </p>
                        </div>
                        <a href="{{ route('catalog.products.documents.index', $product->uuid) }}" class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">{{ __('Manage documents') }}</a>
                    </div>
                    @if ($documents->isEmpty())
                        <p class="mt-4 text-sm text-slate-500">{{ __('No documents have been added to this product.') }}</p>
                    @else
                        <div class="mt-4 divide-y divide-slate-100 rounded-lg border border-slate-200">
                            @foreach ($documents->take(5) as $document)
                                <a href="{{ route('catalog.products.documents.show', [$product->uuid, $document->uuid]) }}" class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-slate-50">
                                    <span class="min-w-0">
                                        <span class="block truncate font-semibold text-slate-900">{{ $document->currentVersion?->title ?? __('Untitled') }}</span>
                                        <span class="text-xs text-slate-500">{{ $document->currentVersion?->document_type?->label() ?? '—' }}</span>
                                    </span>
                                    <span class="flex shrink-0 gap-2">
                                        @if ($document->currentVersion?->isApproved())
                                            <x-badge tone="emerald">{{ __('Approved') }}</x-badge>
                                        @elseif ($document->currentVersion?->review_status?->value === 'pending_review')
                                            <x-badge tone="amber">{{ __('In review') }}</x-badge>
                                        @else
                                            <x-badge>{{ __('Draft') }}</x-badge>
                                        @endif
                                    </span>
                                </a>
                            @endforeach
                        </div>
                        @if ($documents->count() > 5)
                            <p class="mt-3 text-xs text-slate-500">{{ __('Showing the latest 5 documents.') }}</p>
                        @endif
                    @endif
                </section>
            </section>
        @endif

        {{-- Variants --}}
        <section x-cloak x-show="tab === 'variants'" role="tabpanel" class="mt-6">
            <section id="variants" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-[#06112f]">{{ __('Variants') }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ trans_choice(':count variant|:count variants', $product->variants_count, ['count' => $product->variants_count]) }}</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="{{ route('catalog.products.variants.index', $product->uuid) }}" class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">{{ __('Manage variants') }}</a>
                        @if ($canCreateVariant)
                            <a href="{{ route('catalog.products.variants.create', $product->uuid) }}" class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">{{ __('Add variant') }}</a>
                        @endif
                    </div>
                </div>
                <div class="mt-4 divide-y divide-slate-100 rounded-lg border border-slate-200">
                    @foreach ($product->variants as $variant)
                        <a href="{{ route('catalog.products.variants.show', [$product->uuid, $variant->uuid]) }}" class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-slate-50">
                            <span class="min-w-0">
                                <span class="block truncate font-semibold text-slate-900">{{ $variant->displayName() }}</span>
                                <span class="text-sm text-slate-500">{{ $variant->sku ?: '—' }}</span>
                            </span>
                            <span class="flex shrink-0 gap-2">
                                <x-badge :tone="$variant->status->value === 'active' ? 'emerald' : ($variant->status->value === 'archived' ? 'amber' : 'slate')">{{ $variant->status->value }}</x-badge>
                                @if ($variant->isDefaultFor($product))<x-badge tone="indigo">{{ __('Default') }}</x-badge>@endif
                            </span>
                        </a>
                    @endforeach
                </div>
                @if ($product->variants_count > 5)
                    <p class="mt-3 text-xs text-slate-500">{{ __('Showing the first 5 variants in catalog order.') }}</p>
                @endif
            </section>
        </section>

        {{-- Activity --}}
        <section x-cloak x-show="tab === 'activity'" role="tabpanel" class="mt-6">
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-lg font-bold text-[#06112f]">{{ __('Activity') }}</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-slate-500">{{ __('Created by') }}</dt><dd class="mt-0.5 font-semibold text-slate-900">{{ $product->createdBy?->name ?? __('Unknown') }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Updated by') }}</dt><dd class="mt-0.5 font-semibold text-slate-900">{{ $product->updatedBy?->name ?? __('Unknown') }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Created') }}</dt><dd class="mt-0.5">{{ $product->created_at?->format('Y-m-d H:i:s') }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Updated') }}</dt><dd class="mt-0.5">{{ $product->updated_at?->format('Y-m-d H:i:s') }}</dd></div>
                </dl>
                @can('viewAny', [\App\Models\AuditLog::class, $company])
                    <div class="mt-5 border-t border-slate-200 pt-5">
                        <a href="{{ route('catalog.audit.index', ['resource_uuid' => $product->uuid]) }}" class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">{{ __('Open full activity history') }} →</a>
                    </div>
                @endcan
            </section>
        </section>
    </div>
</x-app-layout>
