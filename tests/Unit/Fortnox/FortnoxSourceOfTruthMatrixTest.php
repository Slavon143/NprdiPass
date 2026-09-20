<?php

use App\Enums\Fortnox\FortnoxConflictStatus;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Enums\Fortnox\FortnoxField;
use App\Enums\Fortnox\FortnoxSyncRunStatus;
use App\Enums\Fortnox\FortnoxSyncTrigger;
use App\Services\Fortnox\FortnoxSourceOfTruthMatrix;

test('source of truth matrix implements the ADR R3-006 contract', function () {
    $matrix = new FortnoxSourceOfTruthMatrix;

    expect($matrix->authority(FortnoxField::ArticleNumber))->toBe(FortnoxSourceOfTruthMatrix::FORTNOX)
        ->and($matrix->authority(FortnoxField::Price))->toBe(FortnoxSourceOfTruthMatrix::FORTNOX)
        ->and($matrix->authority(FortnoxField::Stock))->toBe(FortnoxSourceOfTruthMatrix::FORTNOX)
        ->and($matrix->authority(FortnoxField::Name))->toBe(FortnoxSourceOfTruthMatrix::BIDIRECTIONAL)
        ->and($matrix->authority(FortnoxField::Description))->toBe(FortnoxSourceOfTruthMatrix::BIDIRECTIONAL)
        ->and($matrix->authority(FortnoxField::Gtin))->toBe(FortnoxSourceOfTruthMatrix::BIDIRECTIONAL);
});

test('price maps to product commerce while stock has no catalog write target yet', function () {
    $matrix = new FortnoxSourceOfTruthMatrix;

    expect($matrix->isCatalogWritable(FortnoxField::Price))->toBeTrue()
        ->and($matrix->isCatalogWritable(FortnoxField::Stock))->toBeFalse()
        ->and($matrix->isCatalogWritable(FortnoxField::Name))->toBeTrue()
        ->and($matrix->isCatalogWritable(FortnoxField::Gtin))->toBeTrue();
});

test('only bidirectional writable fields participate in conflicts', function () {
    $matrix = new FortnoxSourceOfTruthMatrix;

    expect($matrix->isConflictCapable(FortnoxField::Name))->toBeTrue()
        ->and($matrix->isConflictCapable(FortnoxField::Description))->toBeTrue()
        ->and($matrix->isConflictCapable(FortnoxField::Gtin))->toBeTrue()
        ->and($matrix->isConflictCapable(FortnoxField::ArticleNumber))->toBeFalse()
        ->and($matrix->isConflictCapable(FortnoxField::Price))->toBeFalse();
});

test('default resolution follows the authority side', function () {
    $matrix = new FortnoxSourceOfTruthMatrix;

    expect($matrix->defaultResolution(FortnoxField::Price))->toBe(FortnoxSourceOfTruthMatrix::FORTNOX)
        ->and($matrix->defaultResolution(FortnoxField::Name))->toBe(FortnoxSourceOfTruthMatrix::FORTNOX);
});

test('fortnox field and status enums expose stable values', function () {
    expect(array_column(FortnoxField::cases(), 'value'))
        ->toBe(['article_number', 'name', 'description', 'price', 'stock', 'gtin'])
        ->and(array_column(FortnoxConnectionStatus::cases(), 'value'))
        ->toBe(['active', 'reconnect_required', 'revoked'])
        ->and(array_column(FortnoxSyncRunStatus::cases(), 'value'))
        ->toBe(['running', 'completed', 'failed'])
        ->and(array_column(FortnoxConflictStatus::cases(), 'value'))
        ->toBe(['open', 'resolved_fortnox', 'resolved_nordipass'])
        ->and(array_column(FortnoxSyncTrigger::cases(), 'value'))
        ->toBe(['manual', 'scheduled', 'webhook']);
});
