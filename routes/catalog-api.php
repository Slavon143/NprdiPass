<?php

use App\Enums\ApiTokenAbility;
use App\Http\Controllers\Api\V1\Catalog\AttributeDefinitionController;
use App\Http\Controllers\Api\V1\Catalog\AttributeOptionController;
use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\MediaContentController;
use App\Http\Controllers\Api\V1\Catalog\PassportReadinessController;
use App\Http\Controllers\Api\V1\Catalog\ProductAttributeController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Catalog\ProductDocumentController;
use App\Http\Controllers\Api\V1\Catalog\ProductLifecycleController;
use App\Http\Controllers\Api\V1\Catalog\ProductMediaController;
use App\Http\Controllers\Api\V1\Catalog\ProductPassportController;
use App\Http\Controllers\Api\V1\Catalog\ProductVariantController;
use App\Http\Controllers\Api\V1\Catalog\ProductVariantLifecycleController;
use App\Http\Controllers\Api\V1\Catalog\VariantAttributeController;
use App\Http\Controllers\Api\V1\Catalog\VariantMediaController;
use Illuminate\Support\Facades\Route;

Route::prefix('catalog')->name('catalog.')->group(function (): void {

    // Categories
    Route::get('/categories', [CategoryController::class, 'index'])
        ->middleware(['throttle:catalog-api-read', 'api.ability:'.ApiTokenAbility::CatalogRead->value])
        ->name('categories.index');
    Route::post('/categories', [CategoryController::class, 'store'])
        ->middleware(['throttle:catalog-api-write', 'api.ability:'.ApiTokenAbility::CatalogWrite->value])
        ->name('categories.store');
    Route::get('/categories/{category}', [CategoryController::class, 'show'])
        ->middleware(['throttle:catalog-api-read', 'api.ability:'.ApiTokenAbility::CatalogRead->value])
        ->name('categories.show');
    Route::patch('/categories/{category}', [CategoryController::class, 'update'])
        ->middleware(['throttle:catalog-api-write', 'api.ability:'.ApiTokenAbility::CatalogWrite->value])
        ->name('categories.update');
    Route::post('/categories/{category}/move', [CategoryController::class, 'move'])
        ->middleware(['throttle:catalog-api-write', 'api.ability:'.ApiTokenAbility::CatalogWrite->value])
        ->name('categories.move');
    Route::patch('/categories/reorder', [CategoryController::class, 'reorder'])
        ->middleware(['throttle:catalog-api-write', 'api.ability:'.ApiTokenAbility::CatalogWrite->value])
        ->name('categories.reorder');
    Route::post('/categories/{category}/archive', [CategoryController::class, 'archive'])
        ->middleware(['throttle:catalog-api-lifecycle', 'api.ability:'.ApiTokenAbility::CatalogLifecycle->value])
        ->name('categories.archive');
    Route::post('/categories/{category}/restore', [CategoryController::class, 'restore'])
        ->middleware(['throttle:catalog-api-lifecycle', 'api.ability:'.ApiTokenAbility::CatalogLifecycle->value])
        ->name('categories.restore');

    // Products
    Route::get('/products', [ProductController::class, 'index'])
        ->middleware(['throttle:catalog-api-read', 'api.ability:'.ApiTokenAbility::CatalogRead->value])
        ->name('products.index');
    Route::post('/products', [ProductController::class, 'store'])
        ->middleware(['throttle:catalog-api-write', 'api.ability:'.ApiTokenAbility::CatalogWrite->value])
        ->name('products.store');
    Route::get('/products/{product}', [ProductController::class, 'show'])
        ->middleware(['throttle:catalog-api-read', 'api.ability:'.ApiTokenAbility::CatalogRead->value])
        ->name('products.show');
    Route::patch('/products/{product}', [ProductController::class, 'update'])
        ->middleware(['throttle:catalog-api-write', 'api.ability:'.ApiTokenAbility::CatalogWrite->value])
        ->name('products.update');

    // Product variants (nested under products)
    Route::get('/products/{product}/variants', [ProductVariantController::class, 'index'])
        ->middleware(['throttle:catalog-api-read', 'api.ability:'.ApiTokenAbility::CatalogRead->value])
        ->name('products.variants.index');
    Route::post('/products/{product}/variants', [ProductVariantController::class, 'store'])
        ->middleware(['throttle:catalog-api-write', 'api.ability:'.ApiTokenAbility::CatalogWrite->value])
        ->name('products.variants.store');
    Route::get('/products/{product}/variants/{variant}', [ProductVariantController::class, 'show'])
        ->middleware(['throttle:catalog-api-read', 'api.ability:'.ApiTokenAbility::CatalogRead->value])
        ->name('products.variants.show');
    Route::patch('/products/{product}/variants/{variant}', [ProductVariantController::class, 'update'])
        ->middleware(['throttle:catalog-api-write', 'api.ability:'.ApiTokenAbility::CatalogWrite->value])
        ->name('products.variants.update');
    Route::post('/products/{product}/variants/{variant}/set-default', [ProductVariantController::class, 'setDefault'])
        ->middleware(['throttle:catalog-api-write', 'api.ability:'.ApiTokenAbility::CatalogWrite->value])
        ->name('products.variants.set-default');

    // Product lifecycle
    Route::get('/products/{product}/readiness', [ProductLifecycleController::class, 'readiness'])
        ->middleware(['throttle:catalog-api-read', 'api.ability:'.ApiTokenAbility::CatalogRead->value])
        ->name('products.readiness');
    Route::post('/products/{product}/activate', [ProductLifecycleController::class, 'activate'])
        ->middleware(['throttle:catalog-api-lifecycle', 'api.ability:'.ApiTokenAbility::CatalogLifecycle->value])
        ->name('products.activate');
    Route::post('/products/{product}/return-to-draft', [ProductLifecycleController::class, 'returnToDraft'])
        ->middleware(['throttle:catalog-api-lifecycle', 'api.ability:'.ApiTokenAbility::CatalogLifecycle->value])
        ->name('products.return-to-draft');
    Route::post('/products/{product}/archive', [ProductLifecycleController::class, 'archive'])
        ->middleware(['throttle:catalog-api-lifecycle', 'api.ability:'.ApiTokenAbility::CatalogLifecycle->value])
        ->name('products.archive');
    Route::post('/products/{product}/restore', [ProductLifecycleController::class, 'restore'])
        ->middleware(['throttle:catalog-api-lifecycle', 'api.ability:'.ApiTokenAbility::CatalogLifecycle->value])
        ->name('products.restore');

    // Variant lifecycle
    Route::post('/products/{product}/variants/{variant}/archive', [ProductVariantLifecycleController::class, 'archive'])
        ->middleware(['throttle:catalog-api-lifecycle', 'api.ability:'.ApiTokenAbility::CatalogLifecycle->value])
        ->name('products.variants.archive');
    Route::post('/products/{product}/variants/{variant}/restore', [ProductVariantLifecycleController::class, 'restore'])
        ->middleware(['throttle:catalog-api-lifecycle', 'api.ability:'.ApiTokenAbility::CatalogLifecycle->value])
        ->name('products.variants.restore');

    // Product attributes
    Route::get('/products/{product}/attributes', [ProductAttributeController::class, 'index'])
        ->middleware(['throttle:catalog-api-read', 'api.ability:'.ApiTokenAbility::CatalogRead->value])
        ->name('products.attributes.index');
    Route::put('/products/{product}/attributes', [ProductAttributeController::class, 'update'])
        ->middleware(['throttle:catalog-api-write', 'api.ability:'.ApiTokenAbility::CatalogWrite->value])
        ->name('products.attributes.update');

    // Variant attributes
    Route::get('/products/{product}/variants/{variant}/attributes', [VariantAttributeController::class, 'index'])
        ->middleware(['throttle:catalog-api-read', 'api.ability:'.ApiTokenAbility::CatalogRead->value])
        ->name('products.variants.attributes.index');
    Route::put('/products/{product}/variants/{variant}/attributes', [VariantAttributeController::class, 'update'])
        ->middleware(['throttle:catalog-api-write', 'api.ability:'.ApiTokenAbility::CatalogWrite->value])
        ->name('products.variants.attributes.update');

    // Attribute definitions
    Route::get('/attributes', [AttributeDefinitionController::class, 'index'])
        ->middleware(['throttle:catalog-api-read', 'api.ability:'.ApiTokenAbility::CatalogRead->value])
        ->name('attributes.index');
    Route::post('/attributes', [AttributeDefinitionController::class, 'store'])
        ->middleware(['throttle:catalog-api-write', 'api.ability:'.ApiTokenAbility::CatalogWrite->value])
        ->name('attributes.store');
    Route::get('/attributes/{attribute}', [AttributeDefinitionController::class, 'show'])
        ->middleware(['throttle:catalog-api-read', 'api.ability:'.ApiTokenAbility::CatalogRead->value])
        ->name('attributes.show');
    Route::patch('/attributes/{attribute}', [AttributeDefinitionController::class, 'update'])
        ->middleware(['throttle:catalog-api-write', 'api.ability:'.ApiTokenAbility::CatalogWrite->value])
        ->name('attributes.update');
    Route::post('/attributes/{attribute}/archive', [AttributeDefinitionController::class, 'archive'])
        ->middleware(['throttle:catalog-api-lifecycle', 'api.ability:'.ApiTokenAbility::CatalogLifecycle->value])
        ->name('attributes.archive');
    Route::post('/attributes/{attribute}/restore', [AttributeDefinitionController::class, 'restore'])
        ->middleware(['throttle:catalog-api-lifecycle', 'api.ability:'.ApiTokenAbility::CatalogLifecycle->value])
        ->name('attributes.restore');

    // Attribute options (nested under definitions)
    Route::get('/attributes/{attribute}/options', [AttributeOptionController::class, 'index'])
        ->middleware(['throttle:catalog-api-read', 'api.ability:'.ApiTokenAbility::CatalogRead->value])
        ->name('attributes.options.index');
    Route::post('/attributes/{attribute}/options', [AttributeOptionController::class, 'store'])
        ->middleware(['throttle:catalog-api-write', 'api.ability:'.ApiTokenAbility::CatalogWrite->value])
        ->name('attributes.options.store');
    Route::patch('/attributes/{attribute}/options/reorder', [AttributeOptionController::class, 'reorder'])
        ->middleware(['throttle:catalog-api-write', 'api.ability:'.ApiTokenAbility::CatalogWrite->value])
        ->name('attributes.options.reorder');
    Route::patch('/attributes/{attribute}/options/{option}', [AttributeOptionController::class, 'update'])
        ->middleware(['throttle:catalog-api-write', 'api.ability:'.ApiTokenAbility::CatalogWrite->value])
        ->name('attributes.options.update');
    Route::post('/attributes/{attribute}/options/{option}/archive', [AttributeOptionController::class, 'archive'])
        ->middleware(['throttle:catalog-api-lifecycle', 'api.ability:'.ApiTokenAbility::CatalogLifecycle->value])
        ->name('attributes.options.archive');
    Route::post('/attributes/{attribute}/options/{option}/restore', [AttributeOptionController::class, 'restore'])
        ->middleware(['throttle:catalog-api-lifecycle', 'api.ability:'.ApiTokenAbility::CatalogLifecycle->value])
        ->name('attributes.options.restore');

    // Product media
    Route::get('/products/{product}/media', [ProductMediaController::class, 'index'])
        ->middleware(['throttle:catalog-api-read', 'api.ability:'.ApiTokenAbility::CatalogRead->value])
        ->name('products.media.index');
    Route::post('/products/{product}/media', [ProductMediaController::class, 'store'])
        ->middleware(['throttle:catalog-api-media', 'api.ability:'.ApiTokenAbility::CatalogMedia->value])
        ->name('products.media.store');
    Route::patch('/products/{product}/media/{media}', [ProductMediaController::class, 'update'])
        ->middleware(['throttle:catalog-api-media', 'api.ability:'.ApiTokenAbility::CatalogMedia->value])
        ->name('products.media.update');
    Route::post('/products/{product}/media/{media}/set-primary', [ProductMediaController::class, 'setPrimary'])
        ->middleware(['throttle:catalog-api-media', 'api.ability:'.ApiTokenAbility::CatalogMedia->value])
        ->name('products.media.set-primary');
    Route::patch('/products/{product}/media/reorder', [ProductMediaController::class, 'reorder'])
        ->middleware(['throttle:catalog-api-media', 'api.ability:'.ApiTokenAbility::CatalogMedia->value])
        ->name('products.media.reorder');
    Route::delete('/products/{product}/media/{media}', [ProductMediaController::class, 'destroy'])
        ->middleware(['throttle:catalog-api-media', 'api.ability:'.ApiTokenAbility::CatalogMedia->value])
        ->name('products.media.destroy');

    // Variant media
    Route::get('/products/{product}/variants/{variant}/media', [VariantMediaController::class, 'index'])
        ->middleware(['throttle:catalog-api-read', 'api.ability:'.ApiTokenAbility::CatalogRead->value])
        ->name('products.variants.media.index');
    Route::post('/products/{product}/variants/{variant}/media', [VariantMediaController::class, 'store'])
        ->middleware(['throttle:catalog-api-media', 'api.ability:'.ApiTokenAbility::CatalogMedia->value])
        ->name('products.variants.media.store');
    Route::patch('/products/{product}/variants/{variant}/media/{media}', [VariantMediaController::class, 'update'])
        ->middleware(['throttle:catalog-api-media', 'api.ability:'.ApiTokenAbility::CatalogMedia->value])
        ->name('products.variants.media.update');
    Route::post('/products/{product}/variants/{variant}/media/{media}/set-primary', [VariantMediaController::class, 'setPrimary'])
        ->middleware(['throttle:catalog-api-media', 'api.ability:'.ApiTokenAbility::CatalogMedia->value])
        ->name('products.variants.media.set-primary');
    Route::patch('/products/{product}/variants/{variant}/media/reorder', [VariantMediaController::class, 'reorder'])
        ->middleware(['throttle:catalog-api-media', 'api.ability:'.ApiTokenAbility::CatalogMedia->value])
        ->name('products.variants.media.reorder');
    Route::delete('/products/{product}/variants/{variant}/media/{media}', [VariantMediaController::class, 'destroy'])
        ->middleware(['throttle:catalog-api-media', 'api.ability:'.ApiTokenAbility::CatalogMedia->value])
        ->name('products.variants.media.destroy');

    // Media content (authenticated delivery)
    Route::get('/media/{media}/content', MediaContentController::class)
        ->middleware(['throttle:catalog-api-read', 'api.ability:'.ApiTokenAbility::CatalogRead->value])
        ->name('media.content');

    // Product documents
    Route::get('/products/{product}/documents', [ProductDocumentController::class, 'index'])
        ->middleware(['throttle:documents-api-read', 'api.ability:'.ApiTokenAbility::DocumentsRead->value])
        ->name('products.documents.index');
    Route::post('/products/{product}/documents', [ProductDocumentController::class, 'store'])
        ->middleware(['throttle:documents-api-write', 'api.ability:'.ApiTokenAbility::DocumentsWrite->value])
        ->name('products.documents.store');
    Route::get('/products/{product}/documents/{document}', [ProductDocumentController::class, 'show'])
        ->middleware(['throttle:documents-api-read', 'api.ability:'.ApiTokenAbility::DocumentsRead->value])
        ->name('products.documents.show');
    Route::get('/products/{product}/documents/{document}/versions', [ProductDocumentController::class, 'versions'])
        ->middleware(['throttle:documents-api-read', 'api.ability:'.ApiTokenAbility::DocumentsRead->value])
        ->name('products.documents.versions.index');
    Route::post('/products/{product}/documents/{document}/versions', [ProductDocumentController::class, 'addVersion'])
        ->middleware(['throttle:documents-api-write', 'api.ability:'.ApiTokenAbility::DocumentsWrite->value])
        ->name('products.documents.versions.store');
    Route::get('/products/{product}/documents/{document}/versions/{version}/content', [ProductDocumentController::class, 'versionContent'])
        ->middleware(['throttle:documents-api-media', 'api.ability:'.ApiTokenAbility::DocumentsMedia->value])
        ->name('products.documents.versions.content');
    Route::post('/products/{product}/documents/{document}/archive', [ProductDocumentController::class, 'archive'])
        ->middleware(['throttle:documents-api-write', 'api.ability:'.ApiTokenAbility::DocumentsWrite->value])
        ->name('products.documents.archive');
    Route::post('/products/{product}/documents/{document}/restore', [ProductDocumentController::class, 'restore'])
        ->middleware(['throttle:documents-api-write', 'api.ability:'.ApiTokenAbility::DocumentsWrite->value])
        ->name('products.documents.restore');

    // Product Passports
    Route::get('/products/{product}/passport/readiness', [PassportReadinessController::class, 'show'])
        ->middleware(['throttle:passports-api-read', 'api.ability:'.ApiTokenAbility::PassportsRead->value])
        ->name('products.passport.readiness');
    Route::get('/products/{product}/passport', [ProductPassportController::class, 'show'])
        ->middleware(['throttle:passports-api-read', 'api.ability:'.ApiTokenAbility::PassportsRead->value])
        ->name('products.passport.show');
    Route::post('/products/{product}/passport', [ProductPassportController::class, 'store'])
        ->middleware(['throttle:passports-api-write', 'api.ability:'.ApiTokenAbility::PassportsWrite->value])
        ->name('products.passport.store');
    Route::get('/passports/schema', [ProductPassportController::class, 'schema'])
        ->middleware(['throttle:passports-api-read', 'api.ability:'.ApiTokenAbility::PassportsRead->value])
        ->name('passports.schema');
    Route::put('/products/{product}/passport/sections/{section}', [ProductPassportController::class, 'updateSection'])
        ->middleware(['throttle:passports-api-write', 'api.ability:'.ApiTokenAbility::PassportsWrite->value])
        ->name('products.passport.sections.update');
    Route::put('/products/{product}/passport/settings', [ProductPassportController::class, 'updateSettings'])
        ->middleware(['throttle:passports-api-write', 'api.ability:'.ApiTokenAbility::PassportsWrite->value])
        ->name('products.passport.settings.update');
    Route::put('/products/{product}/passport/documents', [ProductPassportController::class, 'syncDocuments'])
        ->middleware(['throttle:passports-api-write', 'api.ability:'.ApiTokenAbility::PassportsWrite->value])
        ->name('products.passport.documents.update');
    Route::post('/products/{product}/passport/sections/{section}/reset', [ProductPassportController::class, 'resetSection'])
        ->middleware(['throttle:passports-api-write', 'api.ability:'.ApiTokenAbility::PassportsWrite->value])
        ->name('products.passport.sections.reset');
});
