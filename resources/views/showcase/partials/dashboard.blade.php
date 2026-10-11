
<section class="app-content">
<div class="app-page-heading"><div><p class="eyebrow">{{ $copy['reference']['YOUR WORKSPACE'] }}</p><h1>{{ $copy['reference']['Dashboard'] }}</h1><p>{{ $copy['reference']['Keep your product data moving towards publication.'] }}</p></div>

<div class="actions"></div>
</div>

<div class="app-stats">
<article class="stat-card"><div><span>{{ $copy['reference']['Products'] }}</span><svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-package" aria-hidden="true"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"></path><path d="M12 22V12"></path><polyline points="3.29 7 12 12 20.71 7"></polyline><path d="m7.5 4.27 9 5.15"></path></svg></div>
<strong data-demo-stat="products">5</strong><small>{{ $copy['reference']['In your catalogue'] }}</small></article>

<article class="stat-card"><div><span>{{ $copy['reference']['Ready to publish'] }}</span><svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-shield-check" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"></path><path d="m9 12 2 2 4-4"></path></svg></div>
<strong data-demo-stat="ready_products">2</strong><small>{{ $copy['reference']['Required data checked'] }}</small></article>

<article class="stat-card"><div><span>{{ $copy['reference']['Published passports'] }}</span><svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-layers" aria-hidden="true"><path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"></path><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"></path><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"></path></svg></div>
<strong data-demo-stat="versions">0</strong><small>{{ $copy['reference']['Private demo versions'] }}</small></article>

<article class="stat-card"><div><span>{{ $copy['reference']['Required fixes'] }}</span><svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-triangle-alert" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"></path><path d="M12 9v4"></path><path d="M12 17h.01"></path></svg></div>
<strong data-demo-stat="required_fixes">4</strong><small>{{ $copy['reference']['Across your catalogue'] }}</small></article>
</div>

<div class="dashboard-grid">
<article class="panel">
<div class="panel-heading"><div><h2>{{ $copy['reference']['Needs your attention'] }}</h2><p class="muted">{{ $copy['reference']['Resolve required data before creating a passport version.'] }}</p></div>
<span class="status status-amber" data-demo-attention-count>{{ $copy['reference']['3 products'] }}</span></div>

<div class="app-table-wrap">
<table class="app-table product-table compact-table"><thead><tr><th>{{ $copy['reference']['Product'] }}</th><th>{{ $copy['reference']['Readiness'] }}</th><th class="secondary-column">{{ $copy['reference']['Status'] }}</th><th><span class="sr-only">{{ $copy['reference']['Actions'] }}</span></th></tr>
</thead>

<tbody data-demo-products="attention"></tbody>
</table>
</div>
<a class="text-button panel-bottom-link" href="{{ route('demo', ['lang' => $showcaseLocale, 'view' => 'catalogue']) }}" data-demo-nav="catalogue">{{ $copy['reference']['View all products'] }}</a></article>

<aside class="panel next-actions"><h2>{{ $copy['reference']['Next actions'] }}</h2><a href="{{ route('demo', ['lang' => $showcaseLocale, 'view' => 'import']) }}" data-demo-nav="import"><span class="icon-tile"><svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-upload" aria-hidden="true"><path d="M12 3v12"></path><path d="m17 8-5-5-5 5"></path><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path></svg></span><span><strong>{{ $copy['reference']['Import product data'] }}</strong><small>{{ $copy['reference']['Preview Fortnox or CSV articles'] }}</small></span></a><a href="{{ route('demo', ['lang' => $showcaseLocale, 'view' => 'readiness']) }}" data-demo-nav="readiness"><span class="icon-tile"><svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-file-text" aria-hidden="true"><path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"></path><path d="M14 2v5a1 1 0 0 0 1 1h5"></path><path d="M10 9H8"></path><path d="M16 13H8"></path><path d="M16 17H8"></path></svg></span><span><strong>{{ $copy['reference']['Review expiring documents'] }}</strong><small>{{ $copy['reference']['One declaration expires soon'] }}</small></span></a><button type="button" data-demo-info=""><span class="icon-tile"><svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-users" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><path d="M16 3.128a4 4 0 0 1 0 7.744"></path><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><circle cx="9" cy="7" r="4"></circle></svg></span><span><strong>{{ $copy['reference']['Invite your team'] }}</strong><small>{{ $copy['reference']['Share work with the right roles'] }}</small></span></button>
<div class="capacity-summary"><span>{{ $copy['reference']['Pilot · 5/100 products'] }}</span>
<div aria-valuemax="100" aria-valuemin="{{ $copy['reference']['0'] }}" aria-valuenow="{{ $copy['reference']['5'] }}" aria-valuetext="5%" role="progressbar" data-state="loading" data-value="5" data-max="100" data-slot="progress" class="relative h-2 w-full overflow-hidden rounded-full bg-primary/20">
<div data-state="loading" data-value="5" data-max="100" data-slot="progress-indicator" class="h-full w-full flex-1 bg-primary transition-all" style="transform: translateX(-95%);"></div>
</div>
<button class="text-button" type="button" data-demo-info="">{{ $copy['reference']['Manage plan'] }}</button></div>
</aside>
</div>

<article class="panel activity-panel">
<div class="panel-heading"><h2>{{ $copy['reference']['Recent activity'] }}</h2><button class="text-button" type="button" data-demo-info="">{{ $copy['reference']['Import history'] }}</button></div>

<div class="activity-row"><svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-upload" aria-hidden="true"><path d="M12 3v12"></path><path d="m17 8-5-5-5 5"></path><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path></svg><div><strong>{{ $copy['reference']['Fortnox sample'] }}</strong><span>{{ $copy['reference']['1 products · Previewed'] }}</span></div>
<time>{{ $copy['reference']['8 Oct 2026'] }}</time></div>

<div class="activity-row"><svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-upload" aria-hidden="true"><path d="M12 3v12"></path><path d="m17 8-5-5-5 5"></path><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path></svg><div><strong>{{ $copy['reference']['CSV import'] }}</strong><span>{{ $copy['reference']['5 products · Completed'] }}</span></div>
<time>{{ $copy['reference']['7 Oct 2026'] }}</time></div>
</article>
</section>
