        <section class="mt-6 rounded-2xl border border-indigo-100 bg-indigo-50/50 p-5" data-testid="dpp-profile-card">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-indigo-700">{{ __('DPP profile') }}</p>
                    <h2 class="mt-1 text-lg font-bold text-slate-900">{{ $readiness->profileDetails['name'] ?? $readiness->profile }}</h2>
                    <p class="mt-1 text-sm text-slate-600">{{ $readiness->profileDetails['description'] ?? __('Regulatory and operational requirements selected for this product.') }}</p>
                </div>
                <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-indigo-700">{{ $readiness->profile }} v{{ $readiness->profileVersion }}</span>
            </div>
            @if (($readiness->profileDetails['jurisdiction'] ?? null) || ($readiness->profileDetails['product_scope'] ?? null))
                <div class="mt-4 flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-600">
                    @if ($readiness->profileDetails['jurisdiction'] ?? null)
                        <span><strong>{{ __('Jurisdiction') }}:</strong> {{ $readiness->profileDetails['jurisdiction'] }}</span>
                    @endif
                    @if ($readiness->profileDetails['product_scope'] ?? null)
                        <span><strong>{{ __('Product scope') }}:</strong> {{ $readiness->profileDetails['product_scope'] }}</span>
                    @endif
                </div>
            @endif
        </section>

