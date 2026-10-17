<?php

namespace App\Support\Fortnox;

use App\Enums\Fortnox\FortnoxSyncMode;
use App\Enums\Fortnox\FortnoxSyncRunStatus;
use App\Models\Fortnox\FortnoxSyncRun;

class FortnoxSyncPresenter
{
    public function message(FortnoxSyncRun $run): string
    {
        if ($run->status === FortnoxSyncRunStatus::Running) {
            return __('fortnox_sync.running');
        }
        if ($run->status === FortnoxSyncRunStatus::Failed) {
            return __('fortnox_sync.failed');
        }
        if (! $run->pagination_complete) {
            return __('fortnox_sync.partial', ['page' => $run->cursor_page ?? '—']);
        }
        if ((int) $run->articles_fetched === 0) {
            if ($run->sync_mode === null && $run->watermark_before === null) {
                return __('fortnox_sync.empty_unknown');
            }

            return $run->sync_mode === FortnoxSyncMode::Full ? __('fortnox_sync.empty_full')
                : __('fortnox_sync.no_changes', ['date' => $run->watermark_before?->toDateTimeString() ?? '—']);
        }

        return __('fortnox_sync.completed', [
            'fetched' => $run->articles_fetched, 'created' => $run->articles_created,
            'updated' => $run->articles_updated, 'skipped' => $run->articles_skipped, 'conflicts' => $run->conflicts_raised,
        ]);
    }
}
