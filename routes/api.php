<?php

use App\Enums\ApiTokenAbility;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CompanyMembersController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', HealthController::class)
        ->middleware('throttle:api-public')
        ->name('health');

    Route::middleware([
        'auth:sanctum',
        'api.token.valid',
        'api.company.resolve',
        'api.company.member',
        'api.company.active',
        'throttle:api-authenticated',
    ])->group(function (): void {
        Route::get('/me', MeController::class)
            ->middleware('api.ability:'.ApiTokenAbility::CompanyRead->value)
            ->name('me');
        Route::get('/company', CompanyController::class)
            ->middleware('api.ability:'.ApiTokenAbility::CompanyRead->value)
            ->name('company.show');
        Route::get('/company/members', CompanyMembersController::class)
            ->middleware('api.ability:'.ApiTokenAbility::MembersRead->value)
            ->name('company.members.index');
    });
});
