<?php

return [
    'mode' => 'Sync mode', 'incremental' => 'Changes since watermark', 'full' => 'Full catalogue scan', 'unknown' => 'Not recorded',
    'pages' => 'Pages fetched', 'watermark' => 'Data watermark', 'filter' => 'Changes since', 'unfiltered' => 'All visible articles',
    'no_changes' => 'No changes since :date. This does not mean the Fortnox catalogue is empty.',
    'empty_full' => 'The completed full scan returned no visible articles.',
    'empty_unknown' => 'No articles were returned. The filter for this historical scan was not recorded.',
    'partial' => 'The scan is incomplete. Continue from page :page; the data watermark has not advanced.',
    'failed' => 'The sync failed. The data watermark has not advanced; review the error and retry.',
    'running' => 'The sync is in progress. Its data watermark advances only after the scan completes.',
    'completed' => 'Fortnox sync completed: :fetched fetched, :created created, :updated updated, :skipped skipped, :conflicts conflicts.',
    'preview_button' => 'Preview full catalogue', 'preview_title' => 'Full catalogue preview',
    'preview_intro' => 'This scan reads all visible Fortnox articles. It does not change catalogue products, mappings or the data watermark.',
    'total' => 'Visible articles', 'mapped' => 'Articles already mapped', 'unmapped' => 'Articles without a mapping',
    'preview_note' => 'These counts are a preview, not guaranteed create/update totals. The catalogue can change before import. Existing mappings, local overrides and conflict checks are preserved.',
    'confirm' => 'Import full catalogue', 'cancel' => 'Back to integration',
    'test_only' => 'Full catalogue preview and import are currently available only in the test environment.',
];
