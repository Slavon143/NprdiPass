<?php

use App\Http\Controllers\AcceptCompanyInvitationController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CancelCompanyInvitationController;
use App\Http\Controllers\CompanyInvitationRegistrationController;
use App\Http\Controllers\CompanyMembersController;
use App\Http\Controllers\CompanySelectionController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\CompanySwitchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NoCompanyController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\RemoveCompanyMemberController;
use App\Http\Controllers\ResendCompanyInvitationController;
use App\Http\Controllers\ShowCompanyInvitationController;
use App\Http\Controllers\StoreCompanyInvitationController;
use App\Http\Controllers\SuspendedCompanyController;
use App\Http\Controllers\UpdateCompanyMemberRoleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['invitation.secure', 'throttle:invitations.verify'])->group(function (): void {
    Route::get('/invitations/{invitation:uuid}', ShowCompanyInvitationController::class)
        ->name('invitations.show');
});

Route::middleware(['guest', 'invitation.secure', 'throttle:invitations.accept'])->group(function (): void {
    Route::get('/invitations/{invitation:uuid}/register', [CompanyInvitationRegistrationController::class, 'create'])
        ->name('invitations.register');
    Route::post('/invitations/{invitation:uuid}/register', [CompanyInvitationRegistrationController::class, 'store']);
});

Route::post('/invitations/{invitation:uuid}/accept', AcceptCompanyInvitationController::class)
    ->middleware(['auth', 'invitation.secure', 'throttle:invitations.accept'])
    ->name('invitations.accept');

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
    Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');

    Route::prefix('settings')->name('settings.')->group(function (): void {
        Route::get('/company', [CompanySettingsController::class, 'edit'])->name('company.edit');
        Route::patch('/company', [CompanySettingsController::class, 'update'])->name('company.update');
        Route::get('/members', CompanyMembersController::class)->name('members.index');
        Route::get('/api-tokens', [ApiTokenController::class, 'index'])
            ->name('api-tokens.index');
        Route::post('/api-tokens', [ApiTokenController::class, 'store'])
            ->middleware(['throttle:api-token-management', 'api-token.secret'])
            ->name('api-tokens.store');
        Route::delete('/api-tokens/{token}', [ApiTokenController::class, 'destroy'])
            ->whereNumber('token')
            ->middleware('throttle:api-token-management')
            ->name('api-tokens.destroy');
        Route::post('/members/invitations', StoreCompanyInvitationController::class)
            ->middleware('throttle:invitations.manage')
            ->name('members.invitations.store');
        Route::post('/members/invitations/{invitation}/resend', ResendCompanyInvitationController::class)
            ->whereUuid('invitation')
            ->middleware('throttle:invitations.manage')
            ->name('members.invitations.resend');
        Route::delete('/members/invitations/{invitation}', CancelCompanyInvitationController::class)
            ->whereUuid('invitation')
            ->middleware('throttle:invitations.manage')
            ->name('members.invitations.destroy');
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

Route::get('/ready', ReadinessController::class)
    ->name('ready');

require __DIR__.'/auth.php';
