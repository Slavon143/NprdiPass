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
