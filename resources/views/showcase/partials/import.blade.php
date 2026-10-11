<button type="button" class="dialog-close" data-demo-import-close aria-label="{{ $copy['reference']['Close'] }}">×</button>
<div class="import-heading"><h2 id="demo-import-heading" data-slot="dialog-title">{{ $copy['reference']['Bring your product data in'] }}</h2><p data-slot="dialog-description">{{ $copy['reference']['Choose a source, review every row, then confirm your import.'] }}</p></div>
<ol class="import-stepper" aria-label="{{ $copy['reference']['Import progress'] }}">
    @foreach(['Source', 'Preview', 'Complete'] as $step)<li data-demo-import-step="{{ $loop->index }}"><span>{{ $loop->iteration }}</span><b class="step-label">{{ $copy['reference'][$step] }}</b></li>@endforeach
</ol>
<div class="source-choice" aria-label="{{ $copy['reference']['Import source'] }}">
    <button type="button" data-demo-source="fortnox"><span class="fortnox-mark">F</span><strong>Fortnox</strong><small>{{ $copy['reference']['Product articles'] }}</small></button>
    <button type="button" class="selected" data-demo-source="csv"><span class="csv-mark"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M6 3h9l4 4v14H6zM14 3v5h5M9 12h2m3 0h2M9 16h2m3 0h2"/></svg></span><strong>{{ $copy['reference']['CSV file'] }}</strong><small>CSV · UTF-8</small></button>
</div>
<div data-demo-import-stage="source">
    <button type="button" class="upload-zone" data-demo-action="previewSelected"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3v12m-5-7 5-5 5 5M3 15v6h18v-6"/></svg><strong data-demo-sample-prompt>{{ $copy['reference']['Choose sample CSV'] }}</strong><span>{{ $copy['reference']['No live connection. Fixed example data only.'] }}</span></button>
    <div class="sample-actions"><button type="button" class="text-button" data-demo-action="previewCsv">{{ $copy['reference']['Use sample CSV'] }}</button><button type="button" class="text-button" data-demo-action="previewFortnox">{{ $copy['sample_fortnox'] }}</button></div>
    <p class="small muted">{{ $copy['no_connection'] }}</p>
</div>
<div data-demo-import-stage="preview" hidden>
    <h3>{{ $copy['reference']['Sample import preview'] }} · <span data-demo-source-name></span></h3>
    <div class="import-table-scroll"><table class="import-table"><thead><tr><th>{{ $copy['product'] }}</th><th>{{ $copy['sku'] }}</th><th>{{ $copy['status'] }}</th></tr></thead><tbody data-demo-import-rows></tbody></table></div>
    <div class="dialog-actions"><button type="button" class="btn btn-outline" data-demo-action="backToSource">{{ $copy['reference']['Back to source'] }}</button><button type="button" class="btn btn-primary" data-demo-action="apply">{{ $copy['reference']['Add sample rows'] }}</button></div>
</div>
<div data-demo-import-stage="complete" hidden><div class="import-success"><h3>{{ $copy['reference']['Sample rows added to the browser demo.'] }}</h3><p>{{ $copy['demo_detail'] }}</p></div><button type="button" class="btn btn-primary" data-demo-nav="catalogue">{{ $copy['reference']['View catalogue'] }}</button><button type="button" class="text-button" data-demo-action="backToSource">{{ $copy['reference']['Back to source'] }}</button></div>
<p class="import-privacy">{{ $copy['reference']['Public demo'] }} · {{ $copy['reference']['Sample data'] }} · {{ $copy['profile_note'] }}</p>
