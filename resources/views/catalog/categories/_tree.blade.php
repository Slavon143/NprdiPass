<ul class="space-y-0.5" role="group">
    @foreach ($nodes as $node)
        @php
            $category = $node['category'];
            $childNodes = $node['children'];
            $hasChildren = $childNodes !== [];
            $statusValue = $category->status->value;
            $childrenCount = (int) ($category->children_count ?? 0);
            $productCount = (int) ($category->assigned_products_count ?? 0);
            $primaryCount = (int) ($category->primary_products_count ?? 0);
            $reorder = $reorderPayloads[$category->uuid] ?? null;
        @endphp
        <li>
            <div class="group flex items-center gap-2 rounded-lg px-2.5 py-2 transition hover:bg-slate-50">
                @if ($hasChildren)
                    <button type="button" @click="expanded[{{ $category->id }}] = !expanded[{{ $category->id }}]" :aria-expanded="!!expanded[{{ $category->id }}]" class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500" aria-label="{{ __('Toggle subcategories') }}">
                        <svg class="h-4 w-4 shrink-0 transition-transform" :class="expanded[{{ $category->id }}] ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06.02z" clip-rule="evenodd" />
                        </svg>
                    </button>
                @else
                    <span class="h-7 w-7 shrink-0" aria-hidden="true"></span>
                @endif

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="truncate font-semibold text-slate-900">{{ $category->name }}</span>
                        <x-badge :tone="$statusValue === 'active' ? 'emerald' : 'amber'">{{ ucfirst($statusValue) }}</x-badge>
                    </div>
                    <div x-cloak x-show="expanded[{{ $category->id }}]" class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                        <span class="font-mono">{{ $category->slug }}</span>
                        @if ($childrenCount > 0)
                            <span aria-hidden="true">·</span>
                            <span>{{ trans_choice(':count subcategory|:count subcategories', $childrenCount, ['count' => $childrenCount]) }}</span>
                        @endif
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    @if ($productCount > 0)
                        <span class="mr-1 text-xs font-medium text-slate-500">{{ trans_choice(':count product|:count products', $productCount, ['count' => $productCount]) }}</span>
                    @endif

                    @if ($canManage)
                        @if ($reorder !== null)
                            @if ($reorder['up'] !== null)
                                <form method="POST" action="{{ route('catalog.categories.reorder') }}" class="opacity-0 transition focus-within:opacity-100 group-hover:opacity-100">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="parent_uuid" value="{{ $reorder['parent_uuid'] }}">
                                    @foreach ($reorder['up'] as $orderedUuid)<input type="hidden" name="ordered_uuids[]" value="{{ $orderedUuid }}">@endforeach
                                    <button type="submit" class="flex h-7 w-7 items-center justify-center rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500" aria-label="{{ __('Move up') }}" title="{{ __('Move up') }}">
                                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 01-1.06-.02L10 8.832 6.29 12.77a.75.75 0 11-1.08-1.04l4.25-4.5a.75.75 0 011.08 0l4.25 4.5a.75.75 0 01-.02 1.06z" clip-rule="evenodd" /></svg>
                                    </button>
                                </form>
                            @endif
                            @if ($reorder['down'] !== null)
                                <form method="POST" action="{{ route('catalog.categories.reorder') }}" class="opacity-0 transition focus-within:opacity-100 group-hover:opacity-100">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="parent_uuid" value="{{ $reorder['parent_uuid'] }}">
                                    @foreach ($reorder['down'] as $orderedUuid)<input type="hidden" name="ordered_uuids[]" value="{{ $orderedUuid }}">@endforeach
                                    <button type="submit" class="flex h-7 w-7 items-center justify-center rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500" aria-label="{{ __('Move down') }}" title="{{ __('Move down') }}">
                                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                                    </button>
                                </form>
                            @endif
                        @endif

                        <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                            <button type="button" @click="open = !open" :aria-expanded="open" aria-haspopup="menu" class="flex h-7 w-7 items-center justify-center rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500" aria-label="{{ __('Category actions') }}">
                                <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" /></svg>
                            </button>
                            <div x-cloak x-show="open" x-transition class="absolute right-0 z-30 mt-1 w-52 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl" role="menu">
                                <a href="{{ route('catalog.categories.edit', $category->uuid) }}" role="menuitem" class="block px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-50">{{ __('Edit') }}</a>
                                <a href="{{ route('catalog.categories.create', ['parent' => $category->uuid]) }}" role="menuitem" class="block px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-50">{{ __('Add subcategory') }}</a>
                                @if ($productCount > 0)
                                    <a href="{{ route('catalog.products.index', ['category_uuids' => [$category->uuid], 'category_mode' => $primaryCount > 0 ? 'primary' : 'any']) }}" role="menuitem" class="block px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-50">{{ __('View products') }}</a>
                                @endif
                                <a href="{{ route('catalog.categories.edit', $category->uuid) }}#hierarchy" role="menuitem" class="block px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-50">{{ __('Move') }}</a>
                                <div class="my-1 border-t border-slate-200"></div>
                                @if ($statusValue === 'active')
                                    <form method="POST" action="{{ route('catalog.categories.archive', $category->uuid) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" role="menuitem" class="block w-full px-4 py-2 text-left text-sm text-slate-700 transition hover:bg-slate-50">{{ __('Archive') }}</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('catalog.categories.restore', $category->uuid) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" role="menuitem" class="block w-full px-4 py-2 text-left text-sm text-slate-700 transition hover:bg-slate-50">{{ __('Restore') }}</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('catalog.categories.destroy', $category->uuid) }}" onsubmit="return confirm('{{ __('Delete this category? This is only allowed when it has no child categories or product assignments.') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" role="menuitem" class="block w-full px-4 py-2 text-left text-sm text-red-700 transition hover:bg-red-50">{{ __('Delete') }}</button>
                                </form>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            @if ($hasChildren)
                <div x-cloak x-show="expanded[{{ $category->id }}]" class="ml-6 border-l-2 border-slate-100 pl-3">
                    @include('catalog.categories._tree', ['nodes' => $childNodes])
                </div>
            @endif
        </li>
    @endforeach
</ul>
