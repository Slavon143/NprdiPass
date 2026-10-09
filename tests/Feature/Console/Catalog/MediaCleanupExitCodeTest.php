<?php

use App\Data\Catalog\Operations\MediaCleanupReport;
use App\Models\Company;
use App\Services\Catalog\Operations\CatalogMediaCleanupService;

test('media cleanup returns failure when a deletion or accounting acknowledgement failed', function () {
    $company = Company::factory()->create();
    $this->mock(CatalogMediaCleanupService::class)->shouldReceive('cleanup')->once()->andReturn(
        new MediaCleanupReport(scanned: 1, candidates: 1, deleted: 0, skipped: 0, failed: 1,
            bytesReclaimed: 0, failureReasons: ['Media cleanup requires reconciliation.'])
    );
    $this->artisan('catalog:media-cleanup', ['--company' => $company->uuid, '--execute' => true, '--format' => 'json'])
        ->expectsOutputToContain('"failed": 1')->assertFailed();
});
