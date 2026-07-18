<?php

use App\Enums\Passports\ProductPassportAssetKind;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;

test('ProductPassportStatus has exact values', function () {
    expect(ProductPassportStatus::Draft->value)->toBe('draft')
        ->and(ProductPassportStatus::Published->value)->toBe('published')
        ->and(ProductPassportStatus::Unpublished->value)->toBe('unpublished')
        ->and(ProductPassportStatus::Archived->value)->toBe('archived');
});

test('ProductPassportVersionStatus has exact values', function () {
    expect(ProductPassportVersionStatus::Draft->value)->toBe('draft')
        ->and(ProductPassportVersionStatus::Published->value)->toBe('published')
        ->and(ProductPassportVersionStatus::Superseded->value)->toBe('superseded')
        ->and(ProductPassportVersionStatus::Withdrawn->value)->toBe('withdrawn');
});

test('ProductPassportAssetKind has exact values', function () {
    expect(ProductPassportAssetKind::ProductMedia->value)->toBe('product_media')
        ->and(ProductPassportAssetKind::VariantMedia->value)->toBe('variant_media')
        ->and(ProductPassportAssetKind::Document->value)->toBe('document');
});

test('ProductPassportAssetKind includes document kind', function () {
    $values = array_column(ProductPassportAssetKind::cases(), 'value');
    expect($values)->toContain('document');
});
