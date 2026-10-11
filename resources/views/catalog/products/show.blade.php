<x-app-layout>
    <x-input-error :messages="$errors->get('passport')" class="mb-4" />
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

        $identifierLine = collect([$product->defaultVariant?->sku, $product->primaryCategory?->name, $product->manufacturer])->filter()->implode(' · ');

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

    <div class="workspace np-product-workspace" data-testid="product-show-page" x-data="{ tab: '{{ $initialTab }}' }">
        @include('catalog.products.partials.workspace-header')

        <section x-cloak x-show="tab === 'overview'" role="tabpanel" aria-label="{{ __('Product overview') }}" class="workspace-overview">
            @include('catalog.products.partials.workspace-overview')
            <div class="mt-6 space-y-6">
                @include('catalog.products.partials.workspace-catalog-readiness')
                @include('catalog.products.partials.workspace-extra-details')
            </div>
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
        <nav class="workspace-tools" aria-label="{{ __('Product tools') }}">
            @foreach (['product-data' => __('Product data'), 'passport' => __('Passport'), 'variants' => __('Variants'), 'activity' => __('Activity')] as $tool => $label)
                <button type="button" @click="tab = '{{ $tool }}'" :aria-pressed="tab === '{{ $tool }}'">{{ $label }}</button>
            @endforeach
            @include('catalog.products.partials.workspace-actions')
        </nav>
    </div>
</x-app-layout>
