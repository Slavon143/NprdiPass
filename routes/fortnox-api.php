<?php

use App\Enums\ApiTokenAbility;
use App\Http\Controllers\Api\V1\Fortnox\FortnoxController;
use Illuminate\Support\Facades\Route;

Route::prefix('fortnox')->name('fortnox.')->middleware('feature.fortnox')->group(function (): void {
    Route::get('/connection', [FortnoxController::class, 'connection'])
        ->middleware(['throttle:fortnox-api-read', 'api.ability:'.ApiTokenAbility::FortnoxRead->value])->name('connection');
    Route::get('/syncs', [FortnoxController::class, 'indexSyncs'])
        ->middleware(['throttle:fortnox-api-read', 'api.ability:'.ApiTokenAbility::FortnoxRead->value])->name('syncs.index');
    Route::post('/syncs', [FortnoxController::class, 'sync'])
        ->middleware(['throttle:fortnox-api-write', 'api.ability:'.ApiTokenAbility::FortnoxWrite->value])->name('syncs.store');
    Route::get('/syncs/{syncRun}', [FortnoxController::class, 'showSync'])
        ->middleware(['throttle:fortnox-api-read', 'api.ability:'.ApiTokenAbility::FortnoxRead->value])->whereUuid('syncRun')->name('syncs.show');
    Route::get('/mappings', [FortnoxController::class, 'indexMappings'])
        ->middleware(['throttle:fortnox-api-read', 'api.ability:'.ApiTokenAbility::FortnoxRead->value])->name('mappings.index');
    Route::get('/conflicts', [FortnoxController::class, 'indexConflicts'])
        ->middleware(['throttle:fortnox-api-read', 'api.ability:'.ApiTokenAbility::FortnoxRead->value])->name('conflicts.index');
    Route::post('/conflicts/{conflict}/resolve', [FortnoxController::class, 'resolve'])
        ->middleware(['throttle:fortnox-api-write', 'api.ability:'.ApiTokenAbility::FortnoxWrite->value])->whereUuid('conflict')->name('conflicts.resolve');
});
