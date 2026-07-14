<?php

use App\Exceptions\Catalog\ProductOperationException;
use App\Services\Catalog\ProductCategoryService;

test('a product aggregate is limited to twenty category assignments', function () {
    expect(ProductCategoryService::MAX_CATEGORIES_PER_PRODUCT)->toBe(20);
});

test('product domain failures expose stable safe field errors', function (
    ProductOperationException $exception,
    string $code,
    string $field,
) {
    expect($exception->errorCode)->toBe($code)
        ->and($exception->field)->toBe($field)
        ->and($exception->getMessage())->not->toBeEmpty();
})->with([
    'tenant mismatch' => [ProductOperationException::tenantMismatch(), 'product_tenant_mismatch', 'product'],
    'slug conflict' => [ProductOperationException::slugConflict(), 'product_slug_conflict', 'slug'],
    'primary unavailable' => [ProductOperationException::primaryCategoryUnavailable(), 'primary_category_unavailable', 'primary_category_uuid'],
    'categories unavailable' => [ProductOperationException::categoriesUnavailable(), 'categories_unavailable', 'category_uuids'],
    'archived category' => [ProductOperationException::archivedCategory(), 'archived_category', 'category_uuids'],
    'category limit' => [ProductOperationException::tooManyCategories(), 'too_many_categories', 'category_uuids'],
]);
