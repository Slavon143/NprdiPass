<?php

use App\Actions\Catalog\Attributes\CreateAttributeDefinitionAction;
use App\Actions\Catalog\Attributes\CreateAttributeOptionAction;
use App\Actions\Catalog\Attributes\UpdateAttributeDefinitionAction;
use App\Actions\Catalog\Categories\ArchiveCategoryAction;
use App\Actions\Catalog\Categories\CreateCategoryAction;
use App\Actions\Catalog\Categories\MoveCategoryAction;
use App\Actions\Catalog\Categories\RestoreCategoryAction;
use App\Actions\Catalog\Categories\UpdateCategoryAction;
use App\Actions\Catalog\Lifecycle\ActivateProductAction;
use App\Actions\Catalog\Lifecycle\ArchiveProductAction;
use App\Actions\Catalog\Lifecycle\ArchiveProductVariantAction;
use App\Actions\Catalog\Lifecycle\ReturnProductToDraftAction;
use App\Actions\Catalog\Media\DeleteProductMediaAction;
use App\Actions\Catalog\Media\ReorderProductMediaAction;
use App\Actions\Catalog\Media\SetPrimaryProductMediaAction;
use App\Actions\Catalog\Media\UploadProductMediaAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Products\UpdateProductAction;
use App\Actions\Catalog\SetDefaultProductVariantAction;
use App\Actions\Catalog\Variants\CreateProductVariantAction;
use App\Actions\Catalog\Variants\UpdateProductVariantAction;
use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeScope;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;

function auditActorAndCompany(): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->admin()->create([
        'company_id' => $company->getKey(),
        'user_id' => $actor->getKey(),
    ]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company];
}

function auditAssertEvent(AuditEvent $event, int $companyId, ?User $actor = null): AuditLog
{
    $query = AuditLog::query()->where('event', $event->value);
    $log = $query->sole();

    expect($log->company_id)->toBe($companyId);

    if ($actor !== null) {
        $userMorphType = (new User)->getMorphClass();
        expect($log->causer_type)->toBe($userMorphType)
            ->and((int) $log->causer_id)->toBe($actor->getKey());
    }

    return $log;
}

test('CreateCategoryAction emits catalog.category.created', function () {
    [$actor, $company] = auditActorAndCompany();
    $countBefore = AuditLog::query()->count();

    app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'Test Category',
        'slug' => 'test-category',
    ]);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogCategoryCreated, $company->getKey(), $actor);
});

test('UpdateCategoryAction emits catalog.category.updated', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'Original Category',
        'slug' => 'original-category',
    ]);
    $countBefore = AuditLog::query()->count();

    app(UpdateCategoryAction::class)->execute($actor, $company, $category, [
        'name' => 'Updated Category',
    ]);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogCategoryUpdated, $company->getKey(), $actor);
});

test('ArchiveCategoryAction emits catalog.category.archived', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'To Archive',
        'slug' => 'to-archive',
    ]);
    $countBefore = AuditLog::query()->count();

    app(ArchiveCategoryAction::class)->execute($actor, $company, $category);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogCategoryArchived, $company->getKey(), $actor);
});

test('RestoreCategoryAction emits catalog.category.restored', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'To Restore',
        'slug' => 'to-restore',
    ]);
    app(ArchiveCategoryAction::class)->execute($actor, $company, $category);
    $countBefore = AuditLog::query()->count();

    app(RestoreCategoryAction::class)->execute($actor, $company, $category->fresh());

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogCategoryRestored, $company->getKey(), $actor);
});

test('MoveCategoryAction emits catalog.category.moved', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'Child Category',
        'slug' => 'child-category',
    ]);
    $newParent = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'New Parent',
        'slug' => 'new-parent',
    ]);
    $countBefore = AuditLog::query()->count();

    app(MoveCategoryAction::class)->execute($actor, $company, $category, $newParent);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogCategoryMoved, $company->getKey(), $actor);
});

test('CreateProductAction emits catalog.product.created', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'Product Category',
        'slug' => 'product-category',
    ]);
    $countBefore = AuditLog::query()->count();

    app(CreateProductAction::class)->execute($actor, $company, [
        'name' => 'New Product',
        'slug' => 'new-product',
    ], $category->uuid);

    expect(AuditLog::query()->count())->toBeGreaterThan($countBefore);
    $log = AuditLog::query()->where('event', AuditEvent::CatalogProductCreated->value)->sole();
    expect($log->company_id)->toBe($company->getKey());
});

test('UpdateProductAction emits catalog.product.updated', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'Update Cat',
        'slug' => 'update-cat',
    ]);
    $product = app(CreateProductAction::class)->execute($actor, $company, [
        'name' => 'Updatable Product',
        'slug' => 'updatable-product',
    ], $category->uuid);
    $countBefore = AuditLog::query()->count();

    app(UpdateProductAction::class)->execute($actor, $company, $product, [
        'name' => 'Updated Product Name',
    ], $category->uuid);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogProductUpdated, $company->getKey(), $actor);
});

test('ActivateProductAction emits catalog.product.activated', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'Activation Cat',
        'slug' => 'activation-cat',
    ]);
    $product = app(CreateProductAction::class)->execute($actor, $company, [
        'name' => 'Activatable Product',
        'slug' => 'activatable-product',
    ], $category->uuid);
    $countBefore = AuditLog::query()->count();

    app(ActivateProductAction::class)->execute($actor, $company, $product);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogProductActivated, $company->getKey(), $actor);
});

test('ReturnProductToDraftAction emits catalog.product.returned_to_draft', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'Return Cat',
        'slug' => 'return-cat',
    ]);
    $product = app(CreateProductAction::class)->execute($actor, $company, [
        'name' => 'Returnable Product',
        'slug' => 'returnable-product',
    ], $category->uuid);
    app(ActivateProductAction::class)->execute($actor, $company, $product);
    $countBefore = AuditLog::query()->count();

    app(ReturnProductToDraftAction::class)->execute($actor, $company, $product->fresh());

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogProductReturnedToDraft, $company->getKey(), $actor);
});

test('ArchiveProductAction emits catalog.product.archived', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'Archive Cat',
        'slug' => 'archive-cat',
    ]);
    $product = app(CreateProductAction::class)->execute($actor, $company, [
        'name' => 'Archive Me',
        'slug' => 'archive-me',
    ], $category->uuid);
    $countBefore = AuditLog::query()->count();

    app(ArchiveProductAction::class)->execute($actor, $company, $product);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogProductArchived, $company->getKey(), $actor);
});

test('CreateVariantAction emits catalog.variant.created', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'Variant Cat',
        'slug' => 'variant-cat',
    ]);
    $product = app(CreateProductAction::class)->execute($actor, $company, [
        'name' => 'Variant Product',
        'slug' => 'variant-product',
    ], $category->uuid);
    $countBefore = AuditLog::query()->count();

    app(CreateProductVariantAction::class)->execute($actor, $company, $product, [
        'name' => 'Extra Variant',
        'sku' => 'EXTRA-SKU',
        'sort_order' => 10,
    ]);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogVariantCreated, $company->getKey(), $actor);
});

test('UpdateVariantAction emits catalog.variant.updated', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'UpdVariant Cat',
        'slug' => 'upd-variant-cat',
    ]);
    $product = app(CreateProductAction::class)->execute($actor, $company, [
        'name' => 'UpdVariant Product',
        'slug' => 'upd-variant-product',
    ], $category->uuid);
    $variant = app(CreateProductVariantAction::class)->execute($actor, $company, $product, [
        'name' => 'Updatable Variant',
        'sku' => 'UPD-SKU',
        'sort_order' => 20,
    ]);
    $countBefore = AuditLog::query()->count();

    app(UpdateProductVariantAction::class)->execute($actor, $company, $product, $variant, [
        'name' => 'Updated Variant Name',
    ]);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogVariantUpdated, $company->getKey(), $actor);
});

test('ArchiveVariantAction emits catalog.variant.archived', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'ArchVar Cat',
        'slug' => 'archvar-cat',
    ]);
    $product = app(CreateProductAction::class)->execute($actor, $company, [
        'name' => 'ArchVar Product',
        'slug' => 'archvar-product',
    ], $category->uuid);
    $variant = app(CreateProductVariantAction::class)->execute($actor, $company, $product, [
        'name' => 'To Archive Variant',
        'sku' => 'ARCHV-SKU',
        'sort_order' => 30,
    ]);
    $countBefore = AuditLog::query()->count();

    app(ArchiveProductVariantAction::class)->execute($actor, $company, $product->fresh(), $variant->fresh());

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    $log = AuditLog::query()->where('event', AuditEvent::CatalogVariantArchived->value)->sole();
    expect($log->company_id)->toBe($company->getKey());
});

test('SetDefaultVariantAction emits catalog.variant.default_changed', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'Default Cat',
        'slug' => 'default-cat',
    ]);
    $product = app(CreateProductAction::class)->execute($actor, $company, [
        'name' => 'Default Product',
        'slug' => 'default-product',
    ], $category->uuid);
    $variant = app(CreateProductVariantAction::class)->execute($actor, $company, $product, [
        'name' => 'New Default',
        'sku' => 'DEF-SKU',
        'sort_order' => 10,
    ]);
    $countBefore = AuditLog::query()->count();

    app(SetDefaultProductVariantAction::class)->execute($actor, $product->fresh(), $variant);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogVariantDefaultChanged, $company->getKey(), $actor);
});

test('CreateAttributeDefinitionAction emits catalog.attribute.created', function () {
    [$actor, $company] = auditActorAndCompany();
    $countBefore = AuditLog::query()->count();

    app(CreateAttributeDefinitionAction::class)->execute($actor, $company, [
        'name' => 'Color',
        'code' => 'color',
        'type' => AttributeDataType::Select->value,
        'scope' => AttributeScope::Both->value,
    ]);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogAttributeCreated, $company->getKey(), $actor);
});

test('UpdateAttributeDefinitionAction emits catalog.attribute.updated', function () {
    [$actor, $company] = auditActorAndCompany();
    $definition = app(CreateAttributeDefinitionAction::class)->execute($actor, $company, [
        'name' => 'Size',
        'code' => 'size',
        'type' => AttributeDataType::Select->value,
        'scope' => AttributeScope::Both->value,
    ]);
    $countBefore = AuditLog::query()->count();

    app(UpdateAttributeDefinitionAction::class)->execute($actor, $company, $definition, [
        'name' => 'Size Updated',
    ]);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogAttributeUpdated, $company->getKey(), $actor);
});

test('CreateAttributeOptionAction emits catalog.attribute.option.created', function () {
    [$actor, $company] = auditActorAndCompany();
    $definition = app(CreateAttributeDefinitionAction::class)->execute($actor, $company, [
        'name' => 'Material',
        'code' => 'material',
        'type' => AttributeDataType::Select->value,
        'scope' => AttributeScope::Both->value,
    ]);
    $countBefore = AuditLog::query()->count();

    app(CreateAttributeOptionAction::class)->execute($actor, $company, $definition, [
        'label' => 'Cotton',
        'code' => 'cotton',
    ]);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogAttributeOptionCreated, $company->getKey(), $actor);
});

test('UploadProductMediaAction emits catalog.media.uploaded', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'Media Cat',
        'slug' => 'media-cat',
    ]);
    $product = app(CreateProductAction::class)->execute($actor, $company, [
        'name' => 'Media Product',
        'slug' => 'media-product',
    ], $category->uuid);
    $countBefore = AuditLog::query()->count();
    $file = UploadedFile::fake()->image('product.jpg', 100, 100);

    app(UploadProductMediaAction::class)->execute($actor, $company, $product, $file);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogMediaUploaded, $company->getKey(), $actor);
});

test('SetPrimaryProductMediaAction emits catalog.media.primary_changed', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'Primary Cat',
        'slug' => 'primary-cat',
    ]);
    $product = app(CreateProductAction::class)->execute($actor, $company, [
        'name' => 'Primary Product',
        'slug' => 'primary-product',
    ], $category->uuid);
    $file1 = UploadedFile::fake()->image('first.jpg', 100, 100);
    $file2 = UploadedFile::fake()->image('second.jpg', 100, 100);
    app(UploadProductMediaAction::class)->execute($actor, $company, $product, $file1);
    $secondMedia = app(UploadProductMediaAction::class)->execute($actor, $company, $product, $file2);
    $countBefore = AuditLog::query()->count();

    app(SetPrimaryProductMediaAction::class)->execute($actor, $company, $product, $secondMedia);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogMediaPrimaryChanged, $company->getKey(), $actor);
});

test('DeleteProductMediaAction emits catalog.media.deleted', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'DeleteMedia Cat',
        'slug' => 'deletemedia-cat',
    ]);
    $product = app(CreateProductAction::class)->execute($actor, $company, [
        'name' => 'DeleteMedia Product',
        'slug' => 'deletemedia-product',
    ], $category->uuid);
    $file1 = UploadedFile::fake()->image('keep.jpg', 100, 100);
    $file2 = UploadedFile::fake()->image('delete.jpg', 100, 100);
    app(UploadProductMediaAction::class)->execute($actor, $company, $product, $file1);
    $deleteMedia = app(UploadProductMediaAction::class)->execute($actor, $company, $product, $file2);
    $countBefore = AuditLog::query()->count();

    app(DeleteProductMediaAction::class)->execute($actor, $company, $product, $deleteMedia);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogMediaDeleted, $company->getKey(), $actor);
});

test('ReorderProductMediaAction emits catalog.media.reordered', function () {
    [$actor, $company] = auditActorAndCompany();
    $category = app(CreateCategoryAction::class)->execute($actor, $company, [
        'name' => 'Reorder Cat',
        'slug' => 'reorder-cat',
    ]);
    $product = app(CreateProductAction::class)->execute($actor, $company, [
        'name' => 'Reorder Product',
        'slug' => 'reorder-product',
    ], $category->uuid);
    $file1 = UploadedFile::fake()->image('a.jpg', 100, 100);
    $file2 = UploadedFile::fake()->image('b.jpg', 100, 100);
    $media1 = app(UploadProductMediaAction::class)->execute($actor, $company, $product, $file1);
    $media2 = app(UploadProductMediaAction::class)->execute($actor, $company, $product, $file2);
    $countBefore = AuditLog::query()->count();

    app(ReorderProductMediaAction::class)->execute($actor, $company, $product, [
        $media2->uuid,
        $media1->uuid,
    ]);

    expect(AuditLog::query()->count())->toBe($countBefore + 1);
    auditAssertEvent(AuditEvent::CatalogMediaReordered, $company->getKey(), $actor);
});

test('failed validation creates no audit event', function () {
    [$actor, $company] = auditActorAndCompany();
    $countBefore = AuditLog::query()->count();

    try {
        app(CreateCategoryAction::class)->execute($actor, $company, [
            'name' => '',
            'slug' => '',
        ]);
    } catch (Throwable) {
    }

    expect(AuditLog::query()->count())->toBe($countBefore);
});

test('authorization denial creates no audit event', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->viewer()->create([
        'company_id' => $company->getKey(),
        'user_id' => $viewer->getKey(),
    ]);
    test()->actingAs($viewer);
    app(CurrentCompany::class)->set($company);
    $countBefore = AuditLog::query()->count();

    try {
        app(CreateCategoryAction::class)->execute($viewer, $company, [
            'name' => 'Unauthorized',
            'slug' => 'unauthorized',
        ]);
    } catch (AuthorizationException) {
    }

    expect(AuditLog::query()->count())->toBe($countBefore);
});

test('wrong tenant creates no business audit event', function () {
    $actor = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    CompanyMembership::factory()->admin()->create([
        'company_id' => $companyA->getKey(),
        'user_id' => $actor->getKey(),
    ]);
    CompanyMembership::factory()->admin()->create([
        'company_id' => $companyB->getKey(),
        'user_id' => $actor->getKey(),
    ]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($companyA);
    $categoryA = app(CreateCategoryAction::class)->execute($actor, $companyA, [
        'name' => 'T Cat A',
        'slug' => 't-cat-a',
    ]);
    $countBefore = AuditLog::query()->count();

    try {
        app(ArchiveCategoryAction::class)->execute($actor, $companyB, $categoryA);
    } catch (Throwable) {
    }

    expect(AuditLog::query()->count())->toBe($countBefore);
});
