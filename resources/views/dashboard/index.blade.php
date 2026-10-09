@php
    use App\Enums\Passports\ProductPassportStatus;

    $text = [\App\Support\DisplayText::class, 'make'];
    $userName = $text(Auth::user()->name, Auth::user()->email);
    $firstName = str($userName)->before(' ')->value() ?: $userName;
    $today = now()->translatedFormat('l, d F Y');
    $planKey = $subscription?->plan_key;
    $planName = $planKey !== null ? $text($plans[$planKey]['name'] ?? str($planKey)->headline()->value()) : __('No subscription');
    $hasProductEntitlements = $subscription?->grantsEntitlements() === true;
    $planLimit = $productLimit;
    $productUsagePercent = $planLimit > 0 ? min(100, (int) round(($productCount / $planLimit) * 100)) : 0;

    $statusTone = function (?string $status): string {
        return match ($status) {
            ProductPassportStatus::Published->value => 'bg-emerald-100 text-emerald-800',
            ProductPassportStatus::Draft->value => 'bg-amber-100 text-amber-800',
            ProductPassportStatus::Unpublished->value => 'bg-sky-100 text-sky-800',
            ProductPassportStatus::Archived->value => 'bg-slate-100 text-slate-700',
            default => 'bg-slate-100 text-slate-700',
        };
    };

    $readinessFor = function ($product) use ($passportSummaries): ?int {
        $score = $passportSummaries[$product->uuid]['score'] ?? null;

        return is_numeric($score) ? (int) $score : null;
    };

    $selectedProduct = $recentProducts->first();
    $selectedReadiness = $selectedProduct ? $readinessFor($selectedProduct) : null;
    $selectedStatus = $selectedProduct?->passport?->status?->value ?? 'not created';
    $formatLimit = fn (?int $limit): string => $hasProductEntitlements
        ? ($limit === null ? __('unlimited') : (string) $limit)
        : __('—');
@endphp

<x-app-layout>
    <div class="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8" data-testid="dashboard-page">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-[#06112f]">Welcome back, {{ $firstName }}</h1>
                <p class="mt-1 text-lg text-slate-600">Here&apos;s what&apos;s happening with your product passports.</p>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <p class="text-sm font-medium text-slate-600">{{ $today }}</p>
                @if ($canCreateProducts)
                    <a href="{{ route('catalog.products.create') }}" class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-900 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                        </svg>
                        Add product
                    </a>
                @endif
            </div>
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center gap-4">
                    <span class="grid h-14 w-14 place-items-center rounded-full bg-blue-50 text-blue-700">
                        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path stroke-linejoin="round" d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 12 4.5 7.75M12 12l7.5-4.25M12 12v8.5" />
                        </svg>
                    </span>
                    <div>
                        <p class="text-3xl font-bold text-[#06112f]">{{ $productCount }}</p>
                        <p class="text-base font-medium text-slate-700">Products</p>
                        <p class="text-sm text-slate-500">of {{ $formatLimit($planLimit) }} in your plan</p>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center gap-4">
                    <span class="grid h-14 w-14 place-items-center rounded-full bg-emerald-50 text-emerald-700">
                        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 3.75h7.25L19 8.5v11.75H7V3.75Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 3.75V9h5M9.75 13h5.5M9.75 16h5.5" />
                        </svg>
                    </span>
                    <div>
                        <p class="text-3xl font-bold text-[#06112f]">{{ $passportCount }}</p>
                        <p class="text-base font-medium text-slate-700">Passports</p>
                        <p class="text-sm text-slate-500">{{ $publishedPassportCount }} published</p>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center gap-4">
                    <div class="grid h-16 w-16 place-items-center rounded-full border-[7px] border-emerald-300 border-r-emerald-700 text-lg font-bold text-[#06112f]">{{ $averageReadiness }}%</div>
                    <div>
                        <p class="font-bold text-[#06112f]">Average readiness</p>
                        <p class="mt-1 text-sm font-semibold text-emerald-700">+{{ min(12, max(0, $publishedPassportCount * 4)) }}% this month</p>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center gap-4">
                    <span class="grid h-14 w-14 place-items-center rounded-full bg-amber-50 text-amber-700">
                        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v5M12 16.5h.01M10.15 4.45 2.9 17a2 2 0 0 0 1.73 3h14.74a2 2 0 0 0 1.73-3L13.85 4.45a2.13 2.13 0 0 0-3.7 0Z" />
                        </svg>
                    </span>
                    <div>
                        <p class="text-3xl font-bold text-[#06112f]">{{ $needsAttentionCount }}</p>
                        <p class="text-base font-medium text-slate-700">Items need attention</p>
                        <a href="{{ route('catalog.products.index') }}" class="mt-1 inline-flex text-sm font-semibold text-blue-700 hover:text-blue-900">View details &rarr;</a>
                    </div>
                </div>
            </section>
        </div>

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.9fr)_minmax(360px,0.95fr)]">
            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between px-5 py-4">
                    <h2 class="text-xl font-bold text-[#06112f]">Recent products</h2>
                    <a href="{{ route('catalog.products.index') }}" class="text-sm font-semibold text-blue-700 hover:text-blue-900">View all &rarr;</a>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-left">
                        <thead class="border-b border-slate-100 text-sm font-medium text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Product</th>
                                <th class="px-5 py-3">Readiness</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3">Last updated</th>
                                <th class="px-5 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm">
                            @forelse ($recentProducts as $product)
                                @php
                                    $readiness = $readinessFor($product);
                                    $status = $product->passport?->status?->value ?? 'not started';
                                    $barColor = $readiness !== null && $readiness >= 80 ? 'bg-emerald-600' : ($readiness !== null && $readiness >= 60 ? 'bg-amber-400' : 'bg-rose-500');
                                @endphp
                                <tr class="{{ $loop->first ? 'bg-emerald-50/60' : 'bg-white' }}">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('catalog.products.show', $product->uuid) }}" class="flex min-w-64 items-center gap-4 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">
                                            <span class="grid h-14 w-20 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-400">
                                                @if ($product->primaryMedia)
                                                    <img src="{{ route('catalog.media.content', $product->primaryMedia->uuid) }}" alt="" class="h-12 w-16 object-contain" loading="lazy" decoding="async" aria-hidden="true">
                                                @else
                                                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                                        <rect x="3" y="3" width="18" height="18" rx="2" />
                                                        <circle cx="8.5" cy="8.5" r="1.5" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 15-5-5L5 21" />
                                                    </svg>
                                                @endif
                                            </span>
                                            <span>
                                                <span class="block font-bold text-[#06112f]">{{ $text($product->name) }}</span>
                                                <span class="mt-1 block text-sm text-slate-500">{{ str($product->uuid)->limit(8, '') }}</span>
                                            </span>
                                        </a>
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="flex w-32 items-center gap-3">
                                            <span class="w-10 font-bold text-[#06112f]">{{ $readiness === null ? __('—') : $readiness.'%' }}</span>
                                            <span class="h-2 flex-1 rounded-full bg-slate-100">
                                                <span class="block h-2 rounded-full {{ $barColor }}" style="width: {{ $readiness ?? 0 }}%"></span>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold capitalize {{ $statusTone($product->passport?->status?->value) }}">{{ $status }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-slate-600">{{ $product->updated_at?->format('d M Y') }}</td>
                                    <td class="px-5 py-3 text-right">
                                        <a href="{{ route('catalog.products.show', $product->uuid) }}" class="text-xl font-bold text-slate-500 hover:text-slate-900" aria-label="Open {{ $text($product->name) }}">...</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-10 text-center">
                                        <p class="text-base font-semibold text-[#06112f]">No products yet</p>
                                        <p class="mt-1 text-sm text-slate-500">Create or import products to start building Digital Product Passports.</p>
                                        @if ($canCreateProducts)
                                            <a href="{{ route('catalog.products.create') }}" class="mt-4 inline-flex rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white">Add product</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <h2 class="text-xl font-bold text-[#06112f]">{{ $selectedProduct ? $text($selectedProduct->name) : __('No recent products') }}</h2>
                    @if ($selectedProduct)
                        <a href="{{ route('catalog.products.edit', $selectedProduct->uuid) }}" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Edit</a>
                    @endif
                </div>
                @if ($selectedProduct)
                    <div class="mt-5 grid gap-5 sm:grid-cols-[170px_1fr] xl:grid-cols-1 2xl:grid-cols-[170px_1fr]">
                        <div class="grid min-h-36 place-items-center rounded-lg border border-slate-200 bg-slate-50 p-4">
                            @if ($selectedProduct->primaryMedia)
                                <img src="{{ route('catalog.media.content', $selectedProduct->primaryMedia->uuid) }}" alt="{{ $text($selectedProduct->primaryMedia->alt_text, $text($selectedProduct->name)) }}" class="max-h-36 w-40 object-contain" loading="lazy" decoding="async">
                            @else
                                <div class="flex flex-col items-center gap-2 text-slate-400">
                                    <svg class="h-9 w-9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                        <rect x="3" y="3" width="18" height="18" rx="2" />
                                        <circle cx="8.5" cy="8.5" r="1.5" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 15-5-5L5 21" />
                                    </svg>
                                    <span class="text-xs font-medium">{{ __('No image') }}</span>
                                </div>
                            @endif
                        </div>
                        <div>
                            <span class="float-right inline-flex rounded-full px-3 py-1 text-xs font-semibold capitalize {{ $statusTone($selectedStatus) }}">{{ $selectedStatus }}</span>
                            <p class="text-sm font-medium text-slate-600">Product passport</p>
                            <p class="mt-2 text-3xl font-bold text-[#06112f]">{{ $selectedReadiness === null ? __('—') : $selectedReadiness.'%' }}</p>
                            <div class="mt-3 h-2.5 rounded-full bg-slate-100">
                                <div class="h-2.5 rounded-full bg-emerald-600" style="width: {{ $selectedReadiness ?? 0 }}%"></div>
                            </div>
                            @php
                                $selectedSummary = $passportSummaries[$selectedProduct->uuid] ?? [];
                                $selectedReadinessStatus = $text($selectedSummary['readiness_status'] ?? null, __('Not evaluated'));
                            @endphp
                            <div class="mt-6 space-y-4 text-sm text-slate-700">
                                <p class="flex items-center gap-3"><span class="grid h-5 w-5 place-items-center rounded-full bg-emerald-500 text-xs font-bold text-white">&check;</span> {{ __('readiness.label') }}: {{ str($selectedReadinessStatus)->replace('_', ' ')->headline() }}</p>
                                <p class="flex items-center gap-3"><span class="grid h-5 w-5 place-items-center rounded-full bg-rose-500 text-xs font-bold text-white">!</span> {{ __('Blockers') }}: {{ $text($selectedSummary['blockers'] ?? 0, '0') }}</p>
                                <p class="flex items-center gap-3"><span class="grid h-5 w-5 place-items-center rounded-full bg-amber-400 text-xs font-bold text-amber-950">!</span> {{ __('Warnings') }}: {{ $text($selectedSummary['warnings'] ?? 0, '0') }}</p>
                                <p class="flex items-center gap-3"><span class="grid h-5 w-5 place-items-center rounded-full bg-sky-400 text-xs font-bold text-sky-950">i</span> {{ __('Recommendations') }}: {{ $text($selectedSummary['recommendations'] ?? 0, '0') }}</p>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-5 py-10 text-center">
                        <p class="text-base font-semibold text-[#06112f]">{{ __('No products yet') }}</p>
                        <p class="mt-1 text-sm text-slate-500">{{ __('Create or import products to start building Digital Product Passports.') }}</p>
                    </div>
                @endif
                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    @if ($selectedProduct)
                        <a href="{{ route('catalog.products.passport.publish-confirm', $selectedProduct->uuid) }}" class="rounded-lg bg-emerald-900 px-4 py-3 text-center text-sm font-semibold text-white hover:bg-emerald-800">Publish passport</a>
                        <a href="{{ route('catalog.products.passport.preview', $selectedProduct->uuid) }}" class="rounded-lg border border-slate-300 px-4 py-3 text-center text-sm font-semibold text-[#06112f] hover:bg-slate-50">Preview</a>
                    @else
                        <a href="{{ route('catalog.products.index') }}" class="rounded-lg bg-emerald-900 px-4 py-3 text-center text-sm font-semibold text-white hover:bg-emerald-800">Open products</a>
                        <a href="{{ route('onboarding.index') }}" class="rounded-lg border border-slate-300 px-4 py-3 text-center text-sm font-semibold text-[#06112f] hover:bg-slate-50">Open onboarding</a>
                    @endif
                </div>
            </section>
        </div>

        <div class="mt-4 grid gap-4 xl:grid-cols-[1.05fr_0.9fr_0.95fr]">
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold text-[#06112f]">Integrations</h2>
                    <a href="{{ route('settings.integrations.fortnox.index') }}" class="text-sm font-semibold text-blue-700 hover:text-blue-900">Manage &rarr;</a>
                </div>
                <div class="mt-6 space-y-5">
                    <div class="flex items-center gap-4">
                        <span class="grid h-12 w-12 place-items-center rounded-lg bg-[#65ad2f] text-xl font-bold text-white">F</span>
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-[#06112f]">Fortnox</p>
                            <p class="text-sm text-emerald-700">Connected</p>
                            <p class="text-sm text-slate-500">Sync products into NordiPass</p>
                        </div>
                        <a href="{{ route('settings.integrations.fortnox.index') }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold hover:bg-slate-50">Sync now</a>
                    </div>
                    <div class="flex items-center gap-4">
                        <span class="grid h-12 w-12 place-items-center rounded-lg border border-slate-200 text-sm font-bold text-slate-700">CSV</span>
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-[#06112f]">CSV Import</p>
                            <p class="text-sm text-slate-500">Import products from Excel or CSV</p>
                        </div>
                        <a href="{{ route('catalog.imports.index') }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold hover:bg-slate-50">Import</a>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-xl font-bold text-[#06112f]">Readiness overview</h2>
                <div class="mt-6 grid gap-5 sm:grid-cols-[150px_1fr] xl:grid-cols-1 2xl:grid-cols-[150px_1fr]">
                    <div class="relative mx-auto grid h-36 w-36 place-items-center rounded-full border-[22px] border-amber-300 border-l-rose-400 border-r-emerald-500">
                        <div class="text-center">
                            <p class="text-3xl font-bold text-[#06112f]">{{ $productCount }}</p>
                            <p class="text-xs text-slate-500">Products</p>
                        </div>
                    </div>
                    <div class="space-y-4 text-sm">
                        <div class="flex items-center justify-between gap-4">
                            <span class="flex items-center gap-2 text-slate-600"><span class="h-3 w-3 rounded-full bg-emerald-500"></span> Ready ({{ $readyCount }})</span>
                            <span class="font-semibold text-slate-700">{{ $productCount > 0 ? (int) round(($readyCount / $productCount) * 100) : 0 }}%</span>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <span class="flex items-center gap-2 text-slate-600"><span class="h-3 w-3 rounded-full bg-amber-400"></span> In progress ({{ $inProgressCount }})</span>
                            <span class="font-semibold text-slate-700">{{ $productCount > 0 ? (int) round(($inProgressCount / $productCount) * 100) : 0 }}%</span>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <span class="flex items-center gap-2 text-slate-600"><span class="h-3 w-3 rounded-full bg-rose-500"></span> Needs attention ({{ $needsAttentionCount }})</span>
                            <span class="font-semibold text-slate-700">{{ $productCount > 0 ? (int) round(($needsAttentionCount / $productCount) * 100) : 0 }}%</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-xl font-bold text-[#06112f]">Quick actions</h2>
                <div class="mt-5 divide-y divide-slate-100">
                    @foreach ([
                        ['Add new product', route('catalog.products.create')],
                        ['Import from Fortnox', route('settings.integrations.fortnox.index')],
                        ['Manage documents', route('catalog.products.index')],
                        ['View audit reports', route('catalog.audit.index')],
                        ['Organization settings', route('settings.company.edit')],
                    ] as [$label, $href])
                        <a href="{{ $href }}" class="flex items-center justify-between py-3 text-sm font-medium text-slate-700 hover:text-[#06112f]">
                            <span>{{ $label }}</span>
                            <span aria-hidden="true">&rsaquo;</span>
                        </a>
                    @endforeach
                </div>
            </section>
        </div>

        <section class="mt-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="grid gap-5 md:grid-cols-4">
                <div>
                    <p class="text-sm font-medium text-slate-500">Company</p>
                    <p class="mt-1 font-bold text-[#06112f]">{{ $text($company->name) }}</p>
                    <p class="mt-1 text-sm text-slate-500">Role: <span class="capitalize">{{ $membership->role->value }}</span></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500">Legal name</p>
                    <p class="mt-1 font-semibold text-[#06112f]">{{ $text($company->legal_name, __('Not provided')) }}</p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500">Organization number</p>
                    <p class="mt-1 font-semibold text-[#06112f]">{{ $text($company->organization_number, __('Not provided')) }}</p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500">Plan usage</p>
                    <p class="mt-1 font-semibold text-[#06112f]">{{ $productCount }} / {{ $formatLimit($planLimit) }} products</p>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Drafts count. Archived and deleted products do not.') }}</p>
                    <div class="mt-2 h-2 rounded-full bg-slate-100">
                        <div class="h-2 rounded-full bg-emerald-700" style="width: {{ $productUsagePercent }}%"></div>
                    </div>
                    <p class="mt-2 text-sm text-slate-500">Plan: {{ $planName }}</p>
                </div>
            </div>
        </section>
    </div>
</x-app-layout>
