<?php

use App\Http\Controllers\AcceptCompanyInvitationController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CancelCompanyInvitationController;
use App\Http\Controllers\Catalog\CategoryArchiveController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\CategoryMoveController;
use App\Http\Controllers\Catalog\CategoryReorderController;
use App\Http\Controllers\Catalog\CategoryRestoreController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\ProductVariantController;
use App\Http\Controllers\Catalog\SetDefaultProductVariantController;
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

    Route::prefix('catalog/products')->name('catalog.products.')->group(function (): void {
        Route::get('/', [ProductController::class, 'index'])->name('index');
        Route::get('/create', [ProductController::class, 'create'])->name('create');
        Route::post('/', [ProductController::class, 'store'])->name('store');
        Route::prefix('/{product}/variants')->whereUuid('product')->name('variants.')->group(function (): void {
            Route::get('/', [ProductVariantController::class, 'index'])->name('index');
            Route::get('/create', [ProductVariantController::class, 'create'])->name('create');
            Route::post('/', [ProductVariantController::class, 'store'])->name('store');
            Route::get('/{variant}', [ProductVariantController::class, 'show'])->whereUuid('variant')->name('show');
            Route::get('/{variant}/edit', [ProductVariantController::class, 'edit'])->whereUuid('variant')->name('edit');
            Route::patch('/{variant}', [ProductVariantController::class, 'update'])->whereUuid('variant')->name('update');
            Route::post('/{variant}/set-default', SetDefaultProductVariantController::class)->whereUuid('variant')->name('set-default');
        });
        Route::get('/{product}', [ProductController::class, 'show'])->whereUuid('product')->name('show');
        Route::get('/{product}/edit', [ProductController::class, 'edit'])->whereUuid('product')->name('edit');
        Route::patch('/{product}', [ProductController::class, 'update'])->whereUuid('product')->name('update');
    });

    Route::prefix('settings/catalog/categories')->name('catalog.categories.')->group(function (): void {
        Route::get('/', [CategoryController::class, 'index'])->name('index');
        Route::get('/create', [CategoryController::class, 'create'])->name('create');
        Route::post('/', [CategoryController::class, 'store'])->name('store');
        Route::patch('/reorder', CategoryReorderController::class)->name('reorder');
        Route::get('/{category}/edit', [CategoryController::class, 'edit'])->whereUuid('category')->name('edit');
        Route::patch('/{category}', [CategoryController::class, 'update'])->whereUuid('category')->name('update');
        Route::patch('/{category}/move', CategoryMoveController::class)->whereUuid('category')->name('move');
        Route::patch('/{category}/archive', CategoryArchiveController::class)->whereUuid('category')->name('archive');
        Route::patch('/{category}/restore', CategoryRestoreController::class)->whereUuid('category')->name('restore');
    });

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
