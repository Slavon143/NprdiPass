<section class="app-content">
    <div class="app-page-heading"><div><p class="eyebrow">{{ $copy['reference']['All products'] }}</p><h1>{{ $copy['reference']['Products'] }}</h1><p>{{ $copy['reference']['In your catalogue'] }}</p></div>
        <a class="btn btn-primary" href="{{ route('demo', ['lang' => $showcaseLocale, 'view' => 'import']) }}" data-demo-nav="import">{{ $copy['reference']['Import data'] }}</a>
    </div>
    <article class="panel"><div class="app-table-wrap">
        <table class="app-table product-table"><thead><tr><th>{{ $copy['product'] }}</th><th>{{ $copy['reference']['Readiness'] }}</th><th class="secondary-column">{{ $copy['status'] }}</th><th>{{ $copy['reference']['Actions'] }}</th></tr></thead><tbody data-demo-products="all"></tbody></table>
    </div></article>
</section>
