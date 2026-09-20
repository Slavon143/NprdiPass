<?php

use App\Enums\ApiTokenAbility;
use App\Http\Controllers\Api\V1\Webhooks\WebhookDeliveryController;
use App\Http\Controllers\Api\V1\Webhooks\WebhookEndpointController;
use Illuminate\Support\Facades\Route;

Route::prefix('webhooks')->name('webhooks.')->group(function (): void {
    Route::get('endpoints', [WebhookEndpointController::class, 'index'])
        ->middleware(['throttle:api-authenticated', 'api.ability:'.ApiTokenAbility::WebhooksManage->value])
        ->name('endpoints.index');

    Route::post('endpoints', [WebhookEndpointController::class, 'store'])
        ->middleware(['throttle:api-authenticated', 'api.ability:'.ApiTokenAbility::WebhooksManage->value])
        ->name('endpoints.store');

    Route::patch('endpoints/{endpoint}', [WebhookEndpointController::class, 'update'])
        ->middleware(['throttle:api-authenticated', 'api.ability:'.ApiTokenAbility::WebhooksManage->value])
        ->whereUuid('endpoint')
        ->name('endpoints.update');

    Route::post('endpoints/{endpoint}/disable', [WebhookEndpointController::class, 'disable'])
        ->middleware(['throttle:api-authenticated', 'api.ability:'.ApiTokenAbility::WebhooksManage->value])
        ->whereUuid('endpoint')
        ->name('endpoints.disable');

    Route::get('deliveries', [WebhookDeliveryController::class, 'index'])
        ->middleware(['throttle:api-authenticated', 'api.ability:'.ApiTokenAbility::WebhooksManage->value])
        ->name('deliveries.index');
});
