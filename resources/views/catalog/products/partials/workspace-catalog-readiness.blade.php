            <details id="readiness" class="group rounded-lg border border-slate-200 bg-white shadow-sm">
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

