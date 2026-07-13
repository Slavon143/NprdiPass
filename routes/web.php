<?php

use App\Http\Controllers\CompanyMembersController;
use App\Http\Controllers\CompanySelectionController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\CompanySwitchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NoCompanyController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RemoveCompanyMemberController;
use App\Http\Controllers\SuspendedCompanyController;
use App\Http\Controllers\UpdateCompanyMemberRoleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'verified', 'company.resolve'])->group(function (): void {
    Route::get('/companies/select', CompanySelectionController::class)->name('companies.select');
    Route::get('/companies/none', NoCompanyController::class)->name('companies.none');
    Route::get('/company-suspended', SuspendedCompanyController::class)->name('company.suspended');
    Route::post('/companies/{company:uuid}/switch', CompanySwitchController::class)->name('companies.switch');
});

Route::middleware([
    'auth',
    'verified',
    'company.resolve',
    'company.selected',
    'company.member',
    'company.active',
])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::prefix('settings')->name('settings.')->group(function (): void {
        Route::get('/company', [CompanySettingsController::class, 'edit'])->name('company.edit');
        Route::patch('/company', [CompanySettingsController::class, 'update'])->name('company.update');
        Route::get('/members', CompanyMembersController::class)->name('members.index');
        Route::patch('/members/{membership}/role', UpdateCompanyMemberRoleController::class)
            ->whereNumber('membership')
            ->name('members.role.update');
        Route::delete('/members/{membership}', RemoveCompanyMemberController::class)
            ->whereNumber('membership')
            ->name('members.destroy');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
