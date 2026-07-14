<?php

use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyPermission;
use App\Support\Catalog\CatalogIdentifierNormalizer;

test('catalog enum values match the schema contract', function () {
    expect(array_column(ProductStatus::cases(), 'value'))->toBe(['draft', 'active', 'archived'])
        ->and(array_column(ProductVariantStatus::cases(), 'value'))->toBe(['draft', 'active', 'archived'])
        ->and(array_column(CategoryStatus::cases(), 'value'))->toBe(['active', 'archived'])
        ->and(array_column(AttributeDefinitionStatus::cases(), 'value'))->toBe(['active', 'archived'])
        ->and(array_column(AttributeOptionStatus::cases(), 'value'))->toBe(['active', 'archived'])
        ->and(array_column(AttributeDataType::cases(), 'value'))->toBe([
            'text', 'integer', 'decimal', 'boolean', 'date', 'select', 'multiselect',
        ])
        ->and(array_column(AttributeScope::cases(), 'value'))->toBe(['product', 'variant', 'both']);
});

test('permission and audit event strings remain unique', function () {
    $permissions = array_column(CompanyPermission::cases(), 'value');
    $events = array_column(AuditEvent::cases(), 'value');

    expect(array_unique($permissions))->toHaveCount(count($permissions))
        ->and(array_unique($events))->toHaveCount(count($events));
});

test('catalog identifiers are normalized according to their own rules', function () {
    $normalizer = new CatalogIdentifierNormalizer;

    expect($normalizer->normalizeProductSlug('  Ångström  -- Lamp  '))->toBe('angstrom-lamp')
        ->and($normalizer->normalizeCategorySlug(' Hem & Trädgård '))->toBe('hem-tradgard')
        ->and($normalizer->normalizeSku(" ab  \t 12-c "))->toBe('AB12-C')
        ->and($normalizer->normalizeGtin(' 95012346 '))->toBe('95012346')
        ->and($normalizer->normalizeGtin('036000291452'))->toBe('036000291452')
        ->and($normalizer->normalizeGtin('4006381333931'))->toBe('4006381333931')
        ->and($normalizer->normalizeGtin('10614141000415'))->toBe('10614141000415')
        ->and($normalizer->normalizeMpn('  Ab  12  '))->toBe('Ab  12')
        ->and($normalizer->normalizeAttributeCode('  Material Type  '))->toBe('material_type')
        ->and($normalizer->normalizeOptionCode('  Blå / Stor  '))->toBe('bla_stor');
});

test('empty optional identifiers normalize to null and empty required identifiers stay empty', function () {
    $normalizer = new CatalogIdentifierNormalizer;

    expect($normalizer->normalizeGtin('  '))->toBeNull()
        ->and($normalizer->normalizeMpn(''))->toBeNull()
        ->and($normalizer->normalizeSku(' '))->toBe('')
        ->and($normalizer->normalizeProductSlug(' '))->toBe('')
        ->and($normalizer->normalizeAttributeCode(' '))->toBe('');
});

test('invalid GTIN and SKU input is rejected rather than silently repaired', function (string $method, string $value) {
    $normalizer = new CatalogIdentifierNormalizer;

    expect(fn () => $normalizer->{$method}($value))->toThrow(InvalidArgumentException::class);
})->with([
    ['normalizeGtin', 'ABC12345678'],
    ['normalizeGtin', '9501-2346'],
    ['normalizeGtin', '95012345'],
    ['normalizeGtin', '1234567'],
    ['normalizeSku', 'SKU/123'],
]);

test('identifier maximum boundaries are accepted and overflow is rejected', function () {
    $normalizer = new CatalogIdentifierNormalizer;

    expect($normalizer->normalizeSku(str_repeat('a', 100)))->toHaveLength(100)
        ->and($normalizer->normalizeAttributeCode(str_repeat('a', 100)))->toHaveLength(100)
        ->and(fn () => $normalizer->normalizeSku(str_repeat('a', 101)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $normalizer->normalizeOptionCode(str_repeat('a', 101)))
        ->toThrow(InvalidArgumentException::class);
});
