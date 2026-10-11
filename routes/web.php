<?php

use App\Enums\PlatformPermission;
use App\Http\Controllers\AcceptCompanyInvitationController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Billing\StripeWebhookController;
use App\Http\Controllers\CancelCompanyInvitationController;
use App\Http\Controllers\Catalog\AttributeDefinitionController;
use App\Http\Controllers\Catalog\AttributeOptionController;
use App\Http\Controllers\Catalog\AttributeUsageController;
use App\Http\Controllers\Catalog\CatalogAuditController;
use App\Http\Controllers\Catalog\CategoryArchiveController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\CategoryMoveController;
use App\Http\Controllers\Catalog\CategoryReorderController;
use App\Http\Controllers\Catalog\CategoryRestoreController;
use App\Http\Controllers\Catalog\CategoryTemplateController;
use App\Http\Controllers\Catalog\ImportController;
use App\Http\Controllers\Catalog\MediaContentController;
use App\Http\Controllers\Catalog\PassportPublicationController;
use App\Http\Controllers\Catalog\PassportReadinessController;
use App\Http\Controllers\Catalog\PassportRegistryController;
use App\Http\Controllers\Catalog\PassportVersionController;
use App\Http\Controllers\Catalog\ProductAttributeController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\ProductDocumentController;
use App\Http\Controllers\Catalog\ProductLifecycleController;
use App\Http\Controllers\Catalog\ProductManagedController;
use App\Http\Controllers\Catalog\ProductMediaController;
use App\Http\Controllers\Catalog\ProductPassportController;
use App\Http\Controllers\Catalog\ProductPassportQrController;
use App\Http\Controllers\Catalog\ProductTaxonomyController;
use App\Http\Controllers\Catalog\ProductVariantController;
use App\Http\Controllers\Catalog\ProductVariantLifecycleController;
use App\Http\Controllers\Catalog\SetDefaultProductVariantController;
use App\Http\Controllers\Catalog\VariantAttributeController;
use App\Http\Controllers\Catalog\VariantMediaController;
use App\Http\Controllers\CompanyInvitationRegistrationController;
use App\Http\Controllers\CompanyMembersController;
use App\Http\Controllers\CompanySelectionController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\CompanySwitchController;
use App\Http\Controllers\CreateCompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeedbackConversationController;
use App\Http\Controllers\Integrations\FortnoxController;
use App\Http\Controllers\JobCenterController;
use App\Http\Controllers\NoCompanyController;
use App\Http\Controllers\NotificationCenterController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Passports\PublicPassportAssetController;
use App\Http\Controllers\Passports\PublicPassportController;
use App\Http\Controllers\Platform\PlatformOperationsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\RemoveCompanyMemberController;
use App\Http\Controllers\ResendCompanyInvitationController;
use App\Http\Controllers\ShowcaseController;
use App\Http\Controllers\ShowCompanyInvitationController;
use App\Http\Controllers\StoreCompanyInvitationController;
use App\Http\Controllers\SuspendedCompanyController;
use App\Http\Controllers\UpdateCompanyMemberRoleController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Public examples never resolve a company or use a shared demo login/session.
Route::withoutMiddleware([
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
])->group(function (): void {
    Route::get('/', [ShowcaseController::class, 'home'])->name('home');
    Route::get('/demo', [ShowcaseController::class, 'demo'])->name('demo');
});

Route::post('/stripe/webhook', StripeWebhookController::class)
    ->middleware(['feature.billing', 'throttle:stripe-webhook'])
    ->name('stripe.webhook');

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
    Route::get('/companies/create', [CreateCompanyController::class, 'create'])->name('companies.create');
    Route::post('/companies/create', [CreateCompanyController::class, 'store'])->name('companies.create.store');
    Route::get('/company-suspended', SuspendedCompanyController::class)->name('company.suspended');
    Route::post('/companies/{company:uuid}/switch', CompanySwitchController::class)->name('companies.switch');
});

Route::middleware([
    'auth',
    'verified',
    'feature.platform-operations',
    'platform.permission:'.PlatformPermission::PlatformAccess->value,
])
    ->prefix('platform/operations')
    ->name('platform.operations.')
    ->group(function (): void {
        Route::get('/feedback', [PlatformOperationsController::class, 'feedback'])
            ->middleware('platform.permission:'.PlatformPermission::FeedbackView->value)
            ->name('feedback');
        Route::get('/feedback/{feedback}', [FeedbackConversationController::class, 'supportShow'])
            ->middleware('platform.permission:'.PlatformPermission::FeedbackView->value)
            ->whereUuid('feedback')->name('feedback.show');
        Route::post('/feedback/{feedback}/reply', [FeedbackConversationController::class, 'supportReply'])
            ->middleware(['platform.permission:'.PlatformPermission::FeedbackManage->value, 'throttle:10,1'])
            ->whereUuid('feedback')->name('feedback.reply');
        Route::post('/feedback/{feedback}/resolve', [PlatformOperationsController::class, 'resolveFeedback'])
            ->middleware('platform.permission:'.PlatformPermission::FeedbackManage->value)
            ->whereUuid('feedback')->name('feedback.resolve');
        Route::get('/', [PlatformOperationsController::class, 'index'])
            ->middleware('platform.permission:'.PlatformPermission::CompaniesView->value)->name('index');
        Route::get('/observability', [PlatformOperationsController::class, 'observability'])
            ->middleware('platform.permission:'.PlatformPermission::AuditView->value)
            ->name('observability');
        Route::get('/companies/{company:uuid}', [PlatformOperationsController::class, 'showCompany'])
            ->middleware('platform.permission:'.PlatformPermission::CompaniesView->value)
            ->name('companies.show');
        Route::post('/companies/{company:uuid}/support-grants', [PlatformOperationsController::class, 'grantSupport'])
            ->middleware('platform.permission:'.PlatformPermission::CompaniesManage->value)
            ->name('support-grants.store');
        Route::post('/companies/{company:uuid}/support-grants/{grant}/revoke', [PlatformOperationsController::class, 'revokeSupport'])
            ->middleware('platform.permission:'.PlatformPermission::CompaniesManage->value)
            ->whereUuid('grant')
            ->name('support-grants.revoke');
        Route::post('/companies/{company:uuid}/feature-flags', [PlatformOperationsController::class, 'updateFlag'])
            ->middleware('platform.permission:'.PlatformPermission::CompaniesManage->value)
            ->name('feature-flags.update');
        Route::post('/companies/{company:uuid}/incidents', [PlatformOperationsController::class, 'reportIncident'])
            ->middleware('platform.permission:'.PlatformPermission::CompaniesManage->value)
            ->name('incidents.store');
        Route::post('/incidents/{incident}/resolve', [PlatformOperationsController::class, 'resolveIncident'])
            ->middleware('platform.permission:'.PlatformPermission::CompaniesManage->value)
            ->whereUuid('incident')
            ->name('incidents.resolve');
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
    Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding.index');
    Route::get('/onboarding/help', [OnboardingController::class, 'help'])->name('onboarding.help');
    Route::get('/onboarding/support', [OnboardingController::class, 'support'])->name('onboarding.support');
    Route::post('/onboarding/feedback', [OnboardingController::class, 'submitFeedback'])->middleware('throttle:5,1')->name('onboarding.feedback.store');
    Route::get('/onboarding/feedback/{feedback}', [FeedbackConversationController::class, 'customerShow'])->whereUuid('feedback')->name('onboarding.feedback.show');
    Route::post('/onboarding/feedback/{feedback}/reply', [FeedbackConversationController::class, 'customerReply'])->middleware('throttle:10,1')->whereUuid('feedback')->name('onboarding.feedback.reply');
    Route::post('/onboarding/sample-product', [OnboardingController::class, 'createSampleProduct'])->name('onboarding.sample-product.store');
    Route::get('/notifications', [NotificationCenterController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/mark-all-read', [NotificationCenterController::class, 'markAllRead'])->name('notifications.mark-all-read');
    Route::post('/notifications/preferences', [NotificationCenterController::class, 'updatePreferences'])->name('notifications.preferences.update');
    Route::post('/notifications/{notification}/read', [NotificationCenterController::class, 'markRead'])->whereUuid('notification')->name('notifications.read');
    Route::post('/notifications/{notification}/dismiss', [NotificationCenterController::class, 'dismiss'])->whereUuid('notification')->name('notifications.dismiss');
    Route::get('/job-center', [JobCenterController::class, 'index'])->name('job-center.index');
    Route::get('/job-center/{job}', [JobCenterController::class, 'show'])->whereUuid('job')->name('job-center.show');
    Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
    Route::get('/catalog/audit', [CatalogAuditController::class, 'index'])->name('catalog.audit.index');
    Route::get('/catalog/audit/{auditEvent}', [CatalogAuditController::class, 'show'])->whereNumber('auditEvent')->name('catalog.audit.show');
    Route::get('/catalog/media/{media}/content', MediaContentController::class)
        ->whereUuid('media')->name('catalog.media.content');

    Route::prefix('catalog/imports')->name('catalog.imports.')->middleware('feature.production-imports')->group(function (): void {
        Route::get('/', [ImportController::class, 'index'])->name('index');
        Route::post('/', [ImportController::class, 'store'])->name('store');
        Route::post('/templates', [ImportController::class, 'storeTemplate'])->name('templates.store');
        Route::get('/exports/products.{format}', [ImportController::class, 'export'])->whereIn('format', ['csv', 'xlsx'])->name('export');
        Route::get('/{importJob}', [ImportController::class, 'show'])->whereUuid('importJob')->name('show');
        Route::post('/{importJob}/preview', [ImportController::class, 'preview'])->whereUuid('importJob')->name('preview');
        Route::post('/{importJob}/apply', [ImportController::class, 'apply'])->whereUuid('importJob')->name('apply');
        Route::post('/{importJob}/cancel', [ImportController::class, 'cancel'])->whereUuid('importJob')->name('cancel');
        Route::get('/{importJob}/errors.csv', [ImportController::class, 'errors'])->whereUuid('importJob')->name('errors');
    });

    Route::prefix('catalog/attributes')->name('catalog.attributes.')->group(function (): void {
        Route::get('/', [AttributeDefinitionController::class, 'index'])->name('index');
        Route::get('/create', [AttributeDefinitionController::class, 'create'])->name('create');
        Route::post('/', [AttributeDefinitionController::class, 'store'])->name('store');
        Route::delete('/bulk-destroy', [AttributeDefinitionController::class, 'bulkDestroy'])->name('bulk-destroy');
        Route::get('/{attribute}/usage', [AttributeUsageController::class, 'show'])->whereUuid('attribute')->name('usage');
        Route::get('/{attribute}', [AttributeDefinitionController::class, 'show'])->whereUuid('attribute')->name('show');
        Route::get('/{attribute}/edit', [AttributeDefinitionController::class, 'edit'])->whereUuid('attribute')->name('edit');
        Route::patch('/{attribute}', [AttributeDefinitionController::class, 'update'])->whereUuid('attribute')->name('update');
        Route::delete('/{attribute}', [AttributeDefinitionController::class, 'destroy'])->whereUuid('attribute')->name('destroy');
        Route::post('/{attribute}/archive', [AttributeDefinitionController::class, 'archive'])->whereUuid('attribute')->name('archive');
        Route::post('/{attribute}/restore', [AttributeDefinitionController::class, 'restore'])->whereUuid('attribute')->name('restore');
        Route::post('/{attribute}/deprecate', [AttributeDefinitionController::class, 'deprecate'])->whereUuid('attribute')->name('deprecate');
        Route::post('/{attribute}/options', [AttributeOptionController::class, 'store'])->whereUuid('attribute')->name('options.store');
        Route::patch('/{attribute}/options/reorder', [AttributeOptionController::class, 'reorder'])->whereUuid('attribute')->name('options.reorder');
        Route::patch('/{attribute}/options/{option}', [AttributeOptionController::class, 'update'])->whereUuid('attribute')->whereNumber('option')->name('options.update');
        Route::post('/{attribute}/options/{option}/archive', [AttributeOptionController::class, 'archive'])->whereUuid('attribute')->whereNumber('option')->name('options.archive');
        Route::post('/{attribute}/options/{option}/restore', [AttributeOptionController::class, 'restore'])->whereUuid('attribute')->whereNumber('option')->name('options.restore');
        Route::post('/{attribute}/options/{option}/approve', [AttributeOptionController::class, 'approve'])->whereUuid('attribute')->whereNumber('option')->name('options.approve');
        Route::post('/{attribute}/options/{option}/deprecate', [AttributeOptionController::class, 'deprecate'])->whereUuid('attribute')->whereNumber('option')->name('options.deprecate');
    });

    Route::prefix('catalog/products')->name('catalog.products.')->group(function (): void {
        Route::post('/{product}/taxonomy/acknowledge', [ProductTaxonomyController::class, 'acknowledge'])->whereUuid('product')->name('taxonomy.acknowledge');
        Route::get('/', [ProductController::class, 'index'])->name('index');
        Route::get('/suggestions', [ProductController::class, 'suggestions'])->name('suggestions');
        Route::get('/create', [ProductController::class, 'create'])->name('create');
        Route::post('/', [ProductController::class, 'store'])->name('store');
        Route::patch('/bulk-status', [ProductController::class, 'bulkStatus'])->name('bulk-status');
        Route::delete('/bulk-destroy', [ProductController::class, 'bulkDestroy'])->name('bulk-destroy');
        Route::prefix('/{product}/variants')->whereUuid('product')->name('variants.')->group(function (): void {
            Route::get('/', [ProductVariantController::class, 'index'])->name('index');
            Route::get('/create', [ProductVariantController::class, 'create'])->name('create');
            Route::post('/', [ProductVariantController::class, 'store'])->name('store');
            Route::prefix('/{variant}/media')->whereUuid('variant')->name('media.')->group(function (): void {
                Route::get('/', [VariantMediaController::class, 'index'])->name('index');
                Route::post('/', [VariantMediaController::class, 'store'])->name('store');
                Route::patch('/reorder', [VariantMediaController::class, 'reorder'])->name('reorder');
                Route::patch('/{media}', [VariantMediaController::class, 'update'])->whereUuid('media')->name('update');
                Route::post('/{media}/set-primary', [VariantMediaController::class, 'setPrimary'])->whereUuid('media')->name('set-primary');
                Route::delete('/{media}', [VariantMediaController::class, 'destroy'])->whereUuid('media')->name('destroy');
            });
            Route::get('/{variant}/attributes/edit', [VariantAttributeController::class, 'edit'])->whereUuid('variant')->name('attributes.edit');
            Route::put('/{variant}/attributes', [VariantAttributeController::class, 'update'])->whereUuid('variant')->name('attributes.update');
            Route::get('/{variant}', [ProductVariantController::class, 'show'])->whereUuid('variant')->name('show');
            Route::get('/{variant}/edit', [ProductVariantController::class, 'edit'])->whereUuid('variant')->name('edit');
            Route::patch('/{variant}', [ProductVariantController::class, 'update'])->whereUuid('variant')->name('update');
            Route::post('/{variant}/set-default', SetDefaultProductVariantController::class)->whereUuid('variant')->name('set-default');
            Route::post('/{variant}/archive', [ProductVariantLifecycleController::class, 'archive'])->whereUuid('variant')->name('archive');
            Route::post('/{variant}/restore', [ProductVariantLifecycleController::class, 'restore'])->whereUuid('variant')->name('restore');
        });
        Route::prefix('/{product}/media')->whereUuid('product')->name('media.')->group(function (): void {
            Route::get('/', [ProductMediaController::class, 'index'])->name('index');
            Route::post('/', [ProductMediaController::class, 'store'])->name('store');
            Route::patch('/reorder', [ProductMediaController::class, 'reorder'])->name('reorder');
            Route::patch('/{media}', [ProductMediaController::class, 'update'])->whereUuid('media')->name('update');
            Route::post('/{media}/set-primary', [ProductMediaController::class, 'setPrimary'])->whereUuid('media')->name('set-primary');
            Route::delete('/{media}', [ProductMediaController::class, 'destroy'])->whereUuid('media')->name('destroy');
        });
        Route::prefix('/{product}/documents')->whereUuid('product')->name('documents.')->group(function (): void {
            Route::get('/', [ProductDocumentController::class, 'index'])->name('index');
            Route::get('/create', [ProductDocumentController::class, 'create'])->name('create');
            Route::post('/', [ProductDocumentController::class, 'store'])->name('store');
            Route::get('/{document}', [ProductDocumentController::class, 'show'])->whereUuid('document')->name('show');
            Route::post('/{document}/versions', [ProductDocumentController::class, 'addVersion'])->whereUuid('document')->name('versions.store');
            Route::get('/{document}/versions/{version}/download', [ProductDocumentController::class, 'downloadVersion'])->whereUuid('document')->whereUuid('version')->name('versions.download');
            Route::post('/{document}/versions/{version}/submit-review', [ProductDocumentController::class, 'submitReview'])->whereUuid('document')->whereUuid('version')->name('versions.submit-review');
            Route::post('/{document}/versions/{version}/cancel-review', [ProductDocumentController::class, 'cancelReview'])->whereUuid('document')->whereUuid('version')->name('versions.cancel-review');
            Route::post('/{document}/versions/{version}/approve', [ProductDocumentController::class, 'approveVersion'])->whereUuid('document')->whereUuid('version')->name('versions.approve');
            Route::post('/{document}/versions/{version}/reject', [ProductDocumentController::class, 'rejectVersion'])->whereUuid('document')->whereUuid('version')->name('versions.reject');
            Route::post('/{document}/archive', [ProductDocumentController::class, 'archive'])->whereUuid('document')->name('archive');
            Route::post('/{document}/restore', [ProductDocumentController::class, 'restore'])->whereUuid('document')->name('restore');
        });
        Route::get('/{product}/attributes/edit', [ProductAttributeController::class, 'edit'])->whereUuid('product')->name('attributes.edit');
        Route::put('/{product}/attributes', [ProductAttributeController::class, 'update'])->whereUuid('product')->name('attributes.update');
        Route::patch('/{product}/managed', [ProductManagedController::class, 'update'])->whereUuid('product')->name('managed');
        Route::post('/{product}/activate', [ProductLifecycleController::class, 'activate'])->whereUuid('product')->name('activate');
        Route::post('/{product}/return-to-draft', [ProductLifecycleController::class, 'returnToDraft'])->whereUuid('product')->name('return-to-draft');
        Route::post('/{product}/archive', [ProductLifecycleController::class, 'archive'])->whereUuid('product')->name('archive');
        Route::post('/{product}/restore', [ProductLifecycleController::class, 'restore'])->whereUuid('product')->name('restore');
        Route::delete('/{product}', [ProductController::class, 'destroy'])->whereUuid('product')->name('destroy');
        Route::get('/{product}', [ProductController::class, 'show'])->whereUuid('product')->name('show');
        Route::get('/{product}/edit', [ProductController::class, 'edit'])->whereUuid('product')->name('edit');
        Route::patch('/{product}', [ProductController::class, 'update'])->whereUuid('product')->name('update');

        Route::get('/{product}/passport/readiness', [PassportReadinessController::class, 'show'])->whereUuid('product')->name('passport.readiness');
        Route::get('/{product}/passport/registry', [PassportRegistryController::class, 'show'])->whereUuid('product')->name('passport.registry');
        Route::post('/{product}/passport/registry/prepare', [PassportRegistryController::class, 'prepare'])->whereUuid('product')->name('passport.registry.prepare');
        Route::post('/{product}/passport/readiness/context', [PassportReadinessController::class, 'updateContext'])->whereUuid('product')->name('passport.readiness.context');
        Route::get('/{product}/passport/preview', [ProductPassportController::class, 'preview'])->whereUuid('product')->name('passport.preview');
        Route::get('/{product}/passport/publish-confirm', [PassportPublicationController::class, 'publishConfirm'])->whereUuid('product')->name('passport.publish-confirm');
        Route::post('/{product}/passport/publish', [PassportPublicationController::class, 'publish'])->whereUuid('product')->name('passport.publish');
        Route::post('/{product}/passport/publication-approvals', [PassportPublicationController::class, 'requestPublicationApproval'])->whereUuid('product')->name('passport.publication-approvals.store');
        Route::post('/{product}/passport/publication-approvals/{approval}/approve', [PassportPublicationController::class, 'approvePublicationApproval'])->whereUuid('product')->whereUuid('approval')->name('passport.publication-approvals.approve');
        Route::post('/{product}/passport/unpublish', [PassportPublicationController::class, 'unpublish'])->whereUuid('product')->name('passport.unpublish');
        Route::post('/{product}/passport/archive', [PassportPublicationController::class, 'archive'])->whereUuid('product')->name('passport.archive');
        Route::post('/{product}/passport/restore', [PassportPublicationController::class, 'restore'])->whereUuid('product')->name('passport.restore');
        Route::get('/{product}/passport/versions', [PassportVersionController::class, 'index'])->whereUuid('product')->name('passport.versions.index');
        Route::get('/{product}/passport/versions/{version}', [PassportVersionController::class, 'show'])->whereUuid('product')->whereUuid('version')->name('passport.versions.show');
        Route::get('/{product}/passport', [ProductPassportController::class, 'show'])->whereUuid('product')->name('passport.show');
        Route::post('/{product}/passport', [ProductPassportController::class, 'store'])->whereUuid('product')->name('passport.store');
        Route::get('/{product}/passport/edit', [ProductPassportController::class, 'edit'])->whereUuid('product')->name('passport.edit');
        Route::put('/{product}/passport/sections/{section}', [ProductPassportController::class, 'updateSection'])->whereUuid('product')->name('passport.sections.update');
        Route::put('/{product}/passport/settings', [ProductPassportController::class, 'updateSettings'])->whereUuid('product')->name('passport.settings.update');
        Route::put('/{product}/passport/documents', [ProductPassportController::class, 'syncDocuments'])->whereUuid('product')->name('passport.documents.update');
        Route::post('/{product}/passport/sections/{section}/reset', [ProductPassportController::class, 'resetSection'])->whereUuid('product')->name('passport.sections.reset');
        Route::put('/{product}/passport/languages', [ProductPassportController::class, 'updateLanguages'])->whereUuid('product')->name('passport.languages.update');
        Route::get('/{product}/passport/qr', [ProductPassportQrController::class, 'show'])->whereUuid('product')->name('passport.qr.show');
        Route::get('/{product}/passport/qr/label', [ProductPassportQrController::class, 'label'])->whereUuid('product')->name('passport.qr.label');
        Route::get('/{product}/passport/qr.svg', [ProductPassportQrController::class, 'svg'])->whereUuid('product')->name('passport.qr.svg');
        Route::get('/{product}/passport/qr.png', [ProductPassportQrController::class, 'png'])->whereUuid('product')->name('passport.qr.png');
    });

    Route::prefix('settings/catalog/categories')->name('catalog.categories.')->group(function (): void {
        Route::get('/', [CategoryController::class, 'index'])->name('index');
        Route::get('/create', [CategoryController::class, 'create'])->name('create');
        Route::post('/', [CategoryController::class, 'store'])->name('store');
        Route::delete('/bulk-destroy', [CategoryController::class, 'bulkDestroy'])->name('bulk-destroy');
        Route::patch('/reorder', CategoryReorderController::class)->name('reorder');
        Route::get('/{category}/edit', [CategoryController::class, 'edit'])->whereUuid('category')->name('edit');
        Route::get('/{category}/template', [CategoryTemplateController::class, 'show'])->whereUuid('category')->name('template.show');
        Route::post('/{category}/templates', [CategoryTemplateController::class, 'store'])->whereUuid('category')->name('template.store');
        Route::put('/{category}/templates/{template}', [CategoryTemplateController::class, 'update'])->whereUuid('category')->whereUuid('template')->name('template.update');
        Route::post('/{category}/templates/{template}/activate', [CategoryTemplateController::class, 'activate'])->whereUuid('category')->whereUuid('template')->name('template.activate');
        Route::patch('/{category}', [CategoryController::class, 'update'])->whereUuid('category')->name('update');
        Route::delete('/{category}', [CategoryController::class, 'destroy'])->whereUuid('category')->name('destroy');
        Route::patch('/{category}/move', CategoryMoveController::class)->whereUuid('category')->name('move');
        Route::patch('/{category}/archive', CategoryArchiveController::class)->whereUuid('category')->name('archive');
        Route::patch('/{category}/restore', CategoryRestoreController::class)->whereUuid('category')->name('restore');
    });

    Route::prefix('settings')->name('settings.')->group(function (): void {
        Route::get('/company', [CompanySettingsController::class, 'edit'])->name('company.edit');
        Route::patch('/company', [CompanySettingsController::class, 'update'])->name('company.update');
        Route::prefix('billing')->name('billing.')->middleware('feature.billing')->group(function (): void {
            Route::get('/', [BillingController::class, 'index'])->name('index');
            Route::post('/checkout', [BillingController::class, 'checkout'])
                ->middleware('throttle:billing-checkout')
                ->name('checkout');
            Route::post('/checkout/cancel-pending', [BillingController::class, 'cancelPending'])
                ->middleware('throttle:billing-checkout')
                ->name('checkout.cancel-pending');
            Route::post('/portal', [BillingController::class, 'portal'])
                ->middleware('throttle:billing-portal')
                ->name('portal');
        });
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
        Route::prefix('integrations/fortnox')->name('integrations.fortnox.')->middleware('feature.fortnox')->group(function (): void {
            Route::get('/', [FortnoxController::class, 'index'])->name('index');
            Route::post('/connect', [FortnoxController::class, 'connect'])->middleware('throttle:fortnox-connect')->name('connect');
            Route::get('/callback', [FortnoxController::class, 'callback'])->middleware('throttle:fortnox-callback')->name('callback');
            Route::post('/disconnect', [FortnoxController::class, 'disconnect'])->name('disconnect');
            Route::post('/sync', [FortnoxController::class, 'sync'])->middleware('throttle:fortnox-sync')->name('sync');
            Route::post('/conflicts/{conflict}/resolve', [FortnoxController::class, 'resolve'])->whereUuid('conflict')->name('conflicts.resolve');
        });
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/export', [ProfileController::class, 'export'])->name('profile.export');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::prefix('p/{publicId}')->name('public.passports.')->middleware(['throttle:public-passport', 'public.passport.no_cookies'])->group(function (): void {
    Route::get('media/{asset}', [PublicPassportAssetController::class, 'media'])
        ->whereUuid('asset')
        ->name('media.show')
        ->middleware('throttle:public-passport-assets');
    Route::get('documents/{asset}', [PublicPassportAssetController::class, 'document'])
        ->whereUuid('asset')
        ->name('documents.download')
        ->middleware('throttle:public-passport-assets');
    Route::get('/', PublicPassportController::class)
        ->name('show');
});

Route::get('/ready', ReadinessController::class)
    ->name('ready');

require __DIR__.'/auth.php';
