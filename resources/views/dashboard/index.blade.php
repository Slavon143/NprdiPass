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
    $selectedSummary = $selectedProduct ? ($passportSummaries[$selectedProduct->uuid] ?? []) : [];
    $selectedBlockers = (int) ($selectedSummary['blockers'] ?? 0);
    $selectedCanPublish = ($selectedSummary['readiness_source'] ?? null) === 'live'
        && in_array($selectedSummary['readiness_status'] ?? null, ['ready', 'ready_with_warnings'], true)
        && $selectedBlockers === 0
        && (auth()->user()->can(\App\Enums\CompanyPermission::PassportsPublish->value, [$company])
            || auth()->user()->can(\App\Enums\CompanyPermission::PassportsManage->value, [$company]));
    $formatLimit = fn (?int $limit): string => $hasProductEntitlements
        ? ($limit === null ? __('unlimited') : (string) $limit)
        : __('—');
@endphp

<x-app-layout>
    <section class="app-content" data-testid="dashboard-page">
        <div class="app-page-heading"><div><p class="eyebrow">{{ __('Your workspace') }}</p><h1>{{ __('Dashboard') }}</h1><p>{{ __('Keep your product data moving towards publication.') }}</p></div>
            @if($canCreateProducts)<a href="{{ route('catalog.products.create') }}" class="btn btn-primary">{{ __('Add product') }}</a>@endif
        </div>
        <div class="app-stats">
            @foreach([['Products', $productCount, 'In your catalogue'], ['Documents', $documentCount, 'In your workspace'], ['Published passports', $publishedPassportCount, 'Published snapshots'], ['Draft passports', $draftPassportCount, 'In progress']] as [$label, $count, $caption])
                <article class="stat-card"><div><span>{{ __($label) }}</span><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3 3 8l9 5 9-5-9-5ZM3 8v9l9 5 9-5V8M12 13v9"/></svg></div><strong>{{ $count }}</strong><small>{{ __($caption) }}</small></article>
            @endforeach
        </div>
        <div class="dashboard-grid">
            <article class="panel"><div class="panel-heading"><div><h2>{{ __('Recent products') }}</h2><p class="muted">{{ __('Review current data and readiness before publication.') }}</p></div><a href="{{ route('catalog.products.index') }}" class="text-button">{{ __('View all products') }}</a></div>
                @if($recentProducts->isEmpty())
                    <div class="dashboard-empty"><p>{{ __('No products yet') }}</p><a href="{{ route('catalog.products.index') }}" class="btn btn-outline">{{ __('Open products') }}</a></div>
                @else
                    <div class="app-table-wrap"><table class="app-table product-table compact-table"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('readiness.label') }}</th><th class="secondary-column">{{ __('Status') }}</th><th><span class="sr-only">{{ __('Open product') }}</span></th></tr></thead><tbody>
                        @foreach($recentProducts as $product)
                            @php
                                $summary = $passportSummaries[$product->uuid] ?? [];
                                $score = $readinessFor($product);
                                $blockers = (int) ($summary['blockers'] ?? 0);
                                $rowStatus = $summary['readiness_status'] ?? null;
                            @endphp
                            <tr><td><a class="product-name-button" href="{{ route('catalog.products.show', $product->uuid) }}"><span class="table-product-icon">
                                @if($product->primaryMedia)<img src="{{ route('catalog.media.content', $product->primaryMedia->uuid) }}" alt="" class="h-full w-full object-cover">@else<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3 3 8l9 5 9-5-9-5ZM3 8v9l9 5 9-5V8M12 13v9"/></svg><span class="sr-only">{{ __('No image') }}</span>@endif
                            </span><span><strong>{{ $text($product->name) }}</strong><small>{{ $text($product->sku, '—') }}</small></span></a></td>
                            <td><a class="table-readiness {{ $blockers > 0 ? 'needs-data' : '' }}" href="{{ $product->passport ? route('catalog.products.passport.readiness', $product->uuid) : route('catalog.products.show', $product->uuid) }}"><strong>{{ $score === null ? '—' : $score.'%' }}</strong><span>{{ $blockers > 0 ? __(':count blockers', ['count' => $blockers]) : __('readiness.label').': '.str($rowStatus ?? 'not evaluated')->replace('_', ' ')->headline() }}</span>
                                @if($score !== null)<div class="auth-progress"><div class="{{ $blockers > 0 ? 'blocked' : '' }}" style="width:{{ $score }}%"></div></div>@endif
                            </a></td><td class="secondary-column"><span class="status status-neutral">{{ $product->passport ? str($product->passport->status->value)->headline() : __('Not created') }}</span></td><td class="action-column"><a class="icon-button" aria-label="{{ __('Open product') }}: {{ $text($product->name) }}" href="{{ route('catalog.products.show', $product->uuid) }}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m4 16 12-12 4 4L8 20H4v-4ZM14 6l4 4"/></svg></a></td></tr>
                        @endforeach
                    </tbody></table></div>
                @endif
            </article>
            <aside class="panel next-actions"><h2>{{ __('Next actions') }}</h2>
                <a href="{{ route('catalog.products.index') }}"><span class="icon-tile"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3v12m-5-7 5-5 5 5M3 15v6h18v-6"/></svg></span><span><strong>{{ __('Open products') }}</strong><small>{{ __('Review product data and documents') }}</small></span></a>
                @if($selectedProduct)
                    <a href="{{ $selectedProduct->passport ? route('catalog.products.passport.readiness', $selectedProduct->uuid) : route('catalog.products.show', $selectedProduct->uuid) }}"><span class="icon-tile"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3 3 7v6q0 6 9 9 9-3 9-9V7zM8 12l3 3 5-5"/></svg></span><span><strong>{{ $selectedProduct->passport ? __('Review readiness') : __('Complete product data') }}</strong><small>{{ $text($selectedProduct->name) }}</small></span></a>
                    @if($selectedCanPublish)<a class="btn btn-primary" href="{{ route('catalog.products.passport.publish-confirm', $selectedProduct->uuid) }}">{{ __('Publish passport') }}</a>@endif
                    @if($selectedProduct->passport)<a class="text-button" href="{{ route('catalog.products.passport.preview', $selectedProduct->uuid) }}">{{ __('Preview passport') }}</a>@endif
                @endif
                @if(config('features.fortnox_integration'))
                    @can(\App\Enums\CompanyPermission::ApiTokensView->value, $company)
                        <a href="{{ route('settings.integrations.fortnox.index') }}"><span class="icon-tile">F</span><span><strong>{{ __('Manage connection') }}</strong><small>{{ __('Review connection settings') }} · Fortnox</small></span></a>
                    @endcan
                @endif
                <div class="capacity-summary"><p>{{ $planName }} · {{ $productCount }} / {{ $formatLimit($productLimit) }} {{ __('products') }}</p><div class="auth-progress"><div style="width:{{ $productUsagePercent }}%"></div></div>
                    @if(config('features.billing')) @can(\App\Enums\CompanyPermission::BillingManage->value, $company)<a href="{{ route('settings.billing.index') }}" class="text-button">{{ __('Manage plan') }}</a>@endcan @endif
                </div>
            </aside>
        </div>
        <article class="panel activity-panel"><div class="panel-heading"><h2>{{ __('Workspace details') }}</h2><span class="status status-neutral">{{ $membership->role->value }}</span></div>
            <div class="data-grid"><div><dt>{{ __('Company') }}</dt><dd>{{ $text($company->name) }}</dd></div><div><dt>{{ __('Legal name') }}</dt><dd>{{ $text($company->legal_name) }}</dd></div><div><dt>{{ __('Organization number') }}</dt><dd>{{ $text($company->organization_number) }}</dd></div><div><dt>{{ __('Country') }}</dt><dd>{{ $text($company->country_code) }}</dd></div><div><dt>{{ __('Members') }}</dt><dd>{{ $memberCount }}</dd></div><div><dt>{{ __('Signed in as') }}</dt><dd>{{ $userName }}</dd></div></div>
        </article>
    </section>
</x-app-layout>
