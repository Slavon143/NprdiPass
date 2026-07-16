<?php

use App\Data\Catalog\Audit\CatalogAuditSearchCriteria;
use App\Data\Catalog\Integrity\CatalogIntegrityIssue;
use App\Data\Catalog\Integrity\CatalogIntegrityReport;
use App\Data\Catalog\Operations\CatalogSummary;
use App\Data\Catalog\Operations\MediaCleanupReport;
use App\Enums\AuditEvent;
use App\Enums\Catalog\CatalogIntegritySeverity;
use Carbon\CarbonImmutable;

test('CatalogIntegritySeverity threshold ordering is correct', function () {
    expect(CatalogIntegritySeverity::Info->threshold())->toBe(0);
    expect(CatalogIntegritySeverity::Warning->threshold())->toBe(1);
    expect(CatalogIntegritySeverity::Error->threshold())->toBe(2);
    expect(CatalogIntegritySeverity::Critical->threshold())->toBe(3);
});

test('CatalogIntegritySeverity meetsOrExceeds works', function () {
    expect(CatalogIntegritySeverity::Warning->meetsOrExceeds(CatalogIntegritySeverity::Info))->toBeTrue();
    expect(CatalogIntegritySeverity::Warning->meetsOrExceeds(CatalogIntegritySeverity::Warning))->toBeTrue();
    expect(CatalogIntegritySeverity::Warning->meetsOrExceeds(CatalogIntegritySeverity::Error))->toBeFalse();
    expect(CatalogIntegritySeverity::Warning->meetsOrExceeds(CatalogIntegritySeverity::Critical))->toBeFalse();

    expect(CatalogIntegritySeverity::Error->meetsOrExceeds(CatalogIntegritySeverity::Warning))->toBeTrue();
    expect(CatalogIntegritySeverity::Critical->meetsOrExceeds(CatalogIntegritySeverity::Error))->toBeTrue();
    expect(CatalogIntegritySeverity::Info->meetsOrExceeds(CatalogIntegritySeverity::Warning))->toBeFalse();

    expect(CatalogIntegritySeverity::Critical->meetsOrExceeds(CatalogIntegritySeverity::Critical))->toBeTrue();
});

test('CatalogIntegrityIssue toArray returns correct structure', function () {
    $issue = new CatalogIntegrityIssue(
        code: 'catalog.test.code',
        severity: CatalogIntegritySeverity::Error,
        companyUuid: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        resourceType: 'product',
        resourceUuid: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        message: 'Test integrity message.',
        context: ['key' => 'value'],
        suggestedRemediation: 'Fix this issue.',
        repairable: false,
    );

    $array = $issue->toArray();

    expect($array)->toBe([
        'code' => 'catalog.test.code',
        'severity' => 'error',
        'company_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'resource_type' => 'product',
        'resource_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        'message' => 'Test integrity message.',
        'context' => ['key' => 'value'],
        'suggested_remediation' => 'Fix this issue.',
        'repairable' => false,
    ]);
});

test('CatalogIntegrityReport counts by severity correctly', function () {
    $report = new CatalogIntegrityReport;

    $report->addIssue(new CatalogIntegrityIssue(
        code: 'test.1',
        severity: CatalogIntegritySeverity::Info,
        companyUuid: 'a',
        resourceType: 'test',
        resourceUuid: 'b',
        message: 'Info 1',
    ));
    $report->addIssue(new CatalogIntegrityIssue(
        code: 'test.2',
        severity: CatalogIntegritySeverity::Info,
        companyUuid: 'a',
        resourceType: 'test',
        resourceUuid: 'b',
        message: 'Info 2',
    ));
    $report->addIssue(new CatalogIntegrityIssue(
        code: 'test.3',
        severity: CatalogIntegritySeverity::Warning,
        companyUuid: 'a',
        resourceType: 'test',
        resourceUuid: 'b',
        message: 'Warning 1',
    ));
    $report->addIssue(new CatalogIntegrityIssue(
        code: 'test.4',
        severity: CatalogIntegritySeverity::Error,
        companyUuid: 'a',
        resourceType: 'test',
        resourceUuid: 'b',
        message: 'Error 1',
    ));
    $report->addIssue(new CatalogIntegrityIssue(
        code: 'test.5',
        severity: CatalogIntegritySeverity::Critical,
        companyUuid: 'a',
        resourceType: 'test',
        resourceUuid: 'b',
        message: 'Critical 1',
    ));

    expect($report->issuesTotal())->toBe(5)
        ->and($report->info())->toBe(2)
        ->and($report->warning())->toBe(1)
        ->and($report->error())->toBe(1)
        ->and($report->critical())->toBe(1);
});

test('CatalogIntegrityReport hasIssuesAtOrAbove works', function () {
    $report = new CatalogIntegrityReport;

    $report->addIssue(new CatalogIntegrityIssue(
        code: 'test.warn',
        severity: CatalogIntegritySeverity::Warning,
        companyUuid: 'a',
        resourceType: 'test',
        resourceUuid: 'b',
        message: 'Warning',
    ));

    expect($report->hasIssuesAtOrAbove(CatalogIntegritySeverity::Info))->toBeTrue();
    expect($report->hasIssuesAtOrAbove(CatalogIntegritySeverity::Warning))->toBeTrue();
    expect($report->hasIssuesAtOrAbove(CatalogIntegritySeverity::Error))->toBeFalse();
    expect($report->hasIssuesAtOrAbove(CatalogIntegritySeverity::Critical))->toBeFalse();

    $report->addIssue(new CatalogIntegrityIssue(
        code: 'test.crit',
        severity: CatalogIntegritySeverity::Critical,
        companyUuid: 'a',
        resourceType: 'test',
        resourceUuid: 'b',
        message: 'Critical',
    ));

    expect($report->hasIssuesAtOrAbove(CatalogIntegritySeverity::Error))->toBeTrue();
    expect($report->hasIssuesAtOrAbove(CatalogIntegritySeverity::Critical))->toBeTrue();
});

test('CatalogSummary toArray returns correct structure', function () {
    $summary = new CatalogSummary(
        companyUuid: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        companyName: 'Test Corp',
        categoriesCount: 5,
        activeProducts: 10,
        draftProducts: 2,
        archivedProducts: 1,
        activeVariants: 20,
        draftVariants: 4,
        archivedVariants: 2,
        productsMissingPrimaryCategory: 1,
        productsMissingDefaultVariant: 0,
        productsMissingPrimaryMedia: 3,
        productsNotReady: 2,
        attributeDefinitionsCount: 8,
        attributeOptionsCount: 15,
        mediaCount: 30,
        missingPhysicalFiles: 0,
        staleDraftsCount: 1,
    );

    $array = $summary->toArray();

    expect($array)->toBe([
        'company_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'company_name' => 'Test Corp',
        'categories_count' => 5,
        'active_products' => 10,
        'draft_products' => 2,
        'archived_products' => 1,
        'active_variants' => 20,
        'draft_variants' => 4,
        'archived_variants' => 2,
        'products_missing_primary_category' => 1,
        'products_missing_default_variant' => 0,
        'products_missing_primary_media' => 3,
        'products_not_ready' => 2,
        'attribute_definitions_count' => 8,
        'attribute_options_count' => 15,
        'media_count' => 30,
        'missing_physical_files' => 0,
        'stale_drafts_count' => 1,
    ]);
});

test('MediaCleanupReport toArray returns correct structure', function () {
    $report = new MediaCleanupReport(
        scanned: 100,
        candidates: 20,
        deleted: 15,
        skipped: 3,
        failed: 2,
        bytesReclaimed: 1048576,
        failureReasons: ['Permission denied: file1.jpg', 'Locked: file2.jpg'],
    );

    $array = $report->toArray();

    expect($array)->toBe([
        'scanned' => 100,
        'candidates' => 20,
        'deleted' => 15,
        'skipped' => 3,
        'failed' => 2,
        'bytes_reclaimed' => 1048576,
        'failure_reasons' => ['Permission denied: file1.jpg', 'Locked: file2.jpg'],
    ]);
});

test('CatalogAuditSearchCriteria hasFilters detects active filters', function () {
    $noFilters = new CatalogAuditSearchCriteria;
    expect($noFilters->hasFilters())->toBeFalse();

    $eventFilter = new CatalogAuditSearchCriteria(event: AuditEvent::CatalogProductCreated);
    expect($eventFilter->hasFilters())->toBeTrue();

    $qFilter = new CatalogAuditSearchCriteria(q: 'search term');
    expect($qFilter->hasFilters())->toBeTrue();

    $dateFilter = new CatalogAuditSearchCriteria(
        dateFrom: CarbonImmutable::parse('2025-01-01'),
    );
    expect($dateFilter->hasFilters())->toBeTrue();

    $resourceFilter = new CatalogAuditSearchCriteria(
        resourceType: 'product',
        resourceUuid: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
    );
    expect($resourceFilter->hasFilters())->toBeTrue();

    $allFilters = new CatalogAuditSearchCriteria(
        event: AuditEvent::CatalogProductUpdated,
        actorUuid: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        resourceType: 'product',
        resourceUuid: 'cccccccc-cccc-cccc-cccc-cccccccccccc',
        requestId: 'req-123',
        dateFrom: CarbonImmutable::parse('2025-06-01'),
        dateTo: CarbonImmutable::parse('2025-06-30'),
        q: 'update',
    );
    expect($allFilters->hasFilters())->toBeTrue();
});
