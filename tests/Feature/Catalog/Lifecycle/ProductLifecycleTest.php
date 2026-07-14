<?php

use App\Actions\Catalog\Lifecycle\ActivateProductAction;
use App\Actions\Catalog\Lifecycle\ArchiveProductAction;
use App\Actions\Catalog\Lifecycle\ArchiveProductVariantAction;
use App\Actions\Catalog\Lifecycle\RestoreProductAction;
use App\Actions\Catalog\Lifecycle\RestoreProductVariantAction;
use App\Actions\Catalog\Lifecycle\ReturnProductToDraftAction;
use App\Actions\Catalog\Media\UploadProductMediaAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Products\UpdateProductAction;
use App\Actions\Catalog\Variants\CreateProductVariantAction;
use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Exceptions\Catalog\LifecycleOperationException;
use App\Exceptions\Catalog\ProductActivationBlocked;
use App\Models\AuditLog;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\ProductActivationReadinessService;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('catalog_media'));

/** @return array{User, Company} */
function r19Context(CompanyRole $role = CompanyRole::Owner): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'user_id' => $actor,
        'company_id' => $company,
        'role' => $role,
        'is_owner' => $role === CompanyRole::Owner,
    ]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company];
}

function r19Category(Company $company, User $actor, string $name = 'Lifecycle Category'): Category
{
    $slug = str($name)->slug()->toString();
    $category = new Category;
    $category->forceFill([
        'company_id' => $company->id,
        'parent_id' => null,
        'depth' => 0,
        'name' => $name,
        'slug' => $slug,
        'slug_normalized' => $slug,
        'description' => null,
        'sort_order' => 10,
        'status' => CategoryStatus::Active,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();

    return $category->refresh();
}

function r19Product(User $actor, Company $company, bool $withCategory = true): Product
{
    $category = $withCategory ? r19Category($company, $actor) : null;

    return app(CreateProductAction::class)->execute($actor, $company, [
        'name' => 'Lifecycle Product',
        'slug' => 'lifecycle-product',
        'short_description' => 'Lifecycle test product',
        'description' => null,
        'brand' => null,
        'manufacturer' => null,
    ], $category?->uuid);
}

function r19RequiredDefinition(Company $company, User $actor, AttributeScope $scope): AttributeDefinition
{
    $definition = new AttributeDefinition;
    $definition->forceFill([
        'company_id' => $company->id,
        'name' => $scope === AttributeScope::Product ? 'Material' : 'Size',
        'code' => $scope === AttributeScope::Product ? 'material' : 'size',
        'description' => null,
        'type' => AttributeDataType::Text,
        'scope' => $scope,
        'unit' => null,
        'required' => true,
        'filterable' => false,
        'searchable' => false,
        'validation_rules' => ['min_length' => 2],
        'sort_order' => 10,
        'status' => AttributeDefinitionStatus::Active,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();

    return $definition->refresh();
}

test('readiness is read only structured and separates hard gates from warnings', function () {
    [$actor, $company] = r19Context();
    $product = r19Product($actor, $company, false);
    r19RequiredDefinition($company, $actor, AttributeScope::Product);
    r19RequiredDefinition($company, $actor, AttributeScope::Variant);
    $before = $product->fresh()->toArray();
    $auditCount = AuditLog::query()->count();

    $result = app(ProductActivationReadinessService::class)->evaluate($company, $product);

    expect($result->ready)->toBeFalse()
        ->and($result->blockerCodes())->toContain('missing_primary_category', 'missing_required_product_attribute', 'missing_required_variant_attribute')
        ->and($result->warningCodes())->toContain('missing_variant_sku', 'missing_primary_media', 'missing_product_brand', 'missing_product_manufacturer')
        ->and($result->toArray())->toHaveKeys(['ready', 'blockers', 'warnings', 'checked_at'])
        ->and($product->fresh()->toArray())->toBe($before)
        ->and(AuditLog::query()->count())->toBe($auditCount);
});

test('readiness blocks archived selected options and a missing physical primary image', function () {
    [$actor, $company] = r19Context();
    $product = r19Product($actor, $company);
    $definition = new AttributeDefinition;
    $definition->forceFill([
        'company_id' => $company->id, 'name' => 'Material', 'code' => 'material', 'description' => null,
        'type' => AttributeDataType::Select, 'scope' => AttributeScope::Product, 'unit' => null,
        'required' => true, 'filterable' => false, 'searchable' => false, 'validation_rules' => null,
        'sort_order' => 10, 'status' => AttributeDefinitionStatus::Active, 'created_by' => $actor->id, 'updated_by' => $actor->id,
    ])->save();
    $option = new AttributeOption;
    $option->forceFill([
        'company_id' => $company->id, 'attribute_definition_id' => $definition->id,
        'label' => 'Steel', 'code' => 'steel', 'sort_order' => 10, 'status' => AttributeOptionStatus::Archived,
    ])->save();
    $value = new ProductAttributeValue;
    $value->forceFill([
        'company_id' => $company->id, 'product_id' => $product->id,
        'attribute_definition_id' => $definition->id, 'value_text' => null, 'value_integer' => null,
        'value_decimal' => null, 'value_boolean' => null, 'value_date' => null, 'value_option_id' => $option->id,
    ])->save();
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    $file = UploadedFile::fake()->createWithContent('readiness.png', is_string($bytes) ? $bytes : '');
    $media = app(UploadProductMediaAction::class)->execute($actor, $company, $product, $file);
    Storage::disk('catalog_media')->delete($media->storage_path);

    $result = app(ProductActivationReadinessService::class)->evaluate($company, $product);
    expect($result->blockerCodes())->toContain('archived_attribute_option', 'missing_primary_media_file')
        ->and($result->warningCodes())->not->toContain('missing_primary_media');
});

test('readiness query count stays bounded as required definition count grows', function () {
    [$actor, $company] = r19Context();
    $product = r19Product($actor, $company);
    foreach (range(1, 12) as $index) {
        $definition = new AttributeDefinition;
        $definition->forceFill([
            'company_id' => $company->id, 'name' => "Required {$index}", 'code' => "required_{$index}",
            'description' => null, 'type' => AttributeDataType::Text, 'scope' => AttributeScope::Product,
            'unit' => null, 'required' => true, 'filterable' => false, 'searchable' => false,
            'validation_rules' => null, 'sort_order' => $index, 'status' => AttributeDefinitionStatus::Active,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ])->save();
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = app(ProductActivationReadinessService::class)->evaluate($company, $product);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($result->blockers)->toHaveCount(12)
        ->and($queryCount)->toBeLessThan(20);
});

test('owner and admin activate a ready product transactionally and activation is idempotent', function (CompanyRole $role) {
    [$actor, $company] = r19Context($role);
    $product = r19Product($actor, $company);
    $result = app(ProductActivationReadinessService::class)->evaluate($company, $product);

    expect($result->ready)->toBeTrue()
        ->and($result->blockers)->toBeEmpty()
        ->and($result->warningCodes())->toContain('missing_variant_sku', 'missing_primary_media');

    $activated = app(ActivateProductAction::class)->execute($actor, $company, $product);
    expect($activated->status)->toBe(ProductStatus::Active)
        ->and($activated->published_at)->not->toBeNull()
        ->and($activated->updated_by)->toBe($actor->id)
        ->and($activated->variants()->pluck('status')->unique()->all())->toBe([ProductVariantStatus::Active])
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogProductActivated->value)->count())->toBe(1);

    app(ActivateProductAction::class)->execute($actor, $company, $activated);
    expect(AuditLog::query()->where('event', AuditEvent::CatalogProductActivated->value)->count())->toBe(1);
})->with([
    'owner' => [CompanyRole::Owner],
    'admin' => [CompanyRole::Admin],
]);

test('activation re-evaluates stale readiness and rolls back without audit', function () {
    [$actor, $company] = r19Context();
    $product = r19Product($actor, $company);
    expect(app(ProductActivationReadinessService::class)->evaluate($company, $product)->ready)->toBeTrue();
    $category = $product->primaryCategory()->firstOrFail();
    $category->forceFill(['status' => CategoryStatus::Archived])->save();
    $beforeUpdatedBy = $product->updated_by;

    expect(fn () => app(ActivateProductAction::class)->execute($actor, $company, $product))
        ->toThrow(ProductActivationBlocked::class);

    expect($product->fresh()?->status)->toBe(ProductStatus::Draft)
        ->and($product->fresh()?->updated_by)->toBe($beforeUpdatedBy)
        ->and($product->variants()->pluck('status')->unique()->all())->toBe([ProductVariantStatus::Draft])
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogProductActivated->value)->exists())->toBeFalse();
});

test('editor cannot publish or archive lifecycle records', function () {
    [$actor, $company] = r19Context(CompanyRole::Editor);
    $product = r19Product($actor, $company);

    expect(fn () => app(ActivateProductAction::class)->execute($actor, $company, $product))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ArchiveProductAction::class)->execute($actor, $company, $product))
        ->toThrow(AuthorizationException::class);
});

test('return archive and restore preserve the aggregate and create one audit per real transition', function () {
    [$actor, $company] = r19Context();
    $product = r19Product($actor, $company);
    $product = app(ActivateProductAction::class)->execute($actor, $company, $product);
    $variantIds = $product->variants()->pluck('id')->all();
    $categoryIds = $product->categories()->pluck('categories.id')->all();
    $defaultId = $product->default_variant_id;

    $product = app(ReturnProductToDraftAction::class)->execute($actor, $company, $product);
    app(ReturnProductToDraftAction::class)->execute($actor, $company, $product);
    expect($product->status)->toBe(ProductStatus::Draft)
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogProductReturnedToDraft->value)->count())->toBe(1);

    $product = app(ArchiveProductAction::class)->execute($actor, $company, $product);
    app(ArchiveProductAction::class)->execute($actor, $company, $product);
    expect($product->status)->toBe(ProductStatus::Archived)
        ->and($product->variants()->pluck('id')->all())->toBe($variantIds)
        ->and($product->variants()->pluck('status')->unique()->all())->toBe([ProductVariantStatus::Active])
        ->and($product->categories()->pluck('categories.id')->all())->toBe($categoryIds)
        ->and($product->default_variant_id)->toBe($defaultId)
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogProductArchived->value)->count())->toBe(1);

    $product = app(RestoreProductAction::class)->execute($actor, $company, $product);
    app(RestoreProductAction::class)->execute($actor, $company, $product);
    expect($product->status)->toBe(ProductStatus::Draft)
        ->and($product->variants()->pluck('status')->unique()->all())->toBe([ProductVariantStatus::Active])
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogProductRestored->value)->count())->toBe(1);
});

test('variant archive protects default and last available variants and restore preserves identifiers', function () {
    [$actor, $company] = r19Context();
    $product = r19Product($actor, $company);
    $second = app(CreateProductVariantAction::class)->execute($actor, $company, $product, [
        'name' => 'Second', 'sku' => 'R19-SECOND', 'gtin' => '4006381333931', 'mpn' => 'MPN-R19', 'sort_order' => 20,
    ]);
    $product = app(ActivateProductAction::class)->execute($actor, $company, $product);
    $default = $product->defaultVariant()->firstOrFail();

    expect(fn () => app(ArchiveProductVariantAction::class)->execute($actor, $company, $product, $default))
        ->toThrow(LifecycleOperationException::class, 'Select another default variant');

    $archived = app(ArchiveProductVariantAction::class)->execute($actor, $company, $product, $second);
    app(ArchiveProductVariantAction::class)->execute($actor, $company, $product, $archived);
    expect($archived->status)->toBe(ProductVariantStatus::Archived)
        ->and($archived->sku)->toBe('R19-SECOND')
        ->and($archived->gtin)->toBe('4006381333931')
        ->and($archived->mpn)->toBe('MPN-R19')
        ->and($product->fresh()?->status)->toBe(ProductStatus::Active)
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogVariantArchived->value)->count())->toBe(1);

    $restored = app(RestoreProductVariantAction::class)->execute($actor, $company, $product, $archived);
    app(RestoreProductVariantAction::class)->execute($actor, $company, $product, $restored);
    expect($restored->status)->toBe(ProductVariantStatus::Active)
        ->and($product->fresh()?->default_variant_id)->toBe($default->id)
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogVariantRestored->value)->count())->toBe(1);
});

test('archived products reject ordinary action mutations while active products remain editable under R1 policy', function () {
    [$actor, $company] = r19Context();
    $product = r19Product($actor, $company);
    $active = app(ActivateProductAction::class)->execute($actor, $company, $product);
    $updated = app(UpdateProductAction::class)->execute($actor, $company, $active, [
        'name' => 'Active Product Edited', 'slug' => 'active-product-edited',
    ], $active->primaryCategory?->uuid);
    expect($updated->name)->toBe('Active Product Edited');

    $archived = app(ArchiveProductAction::class)->execute($actor, $company, $updated);
    expect(fn () => app(UpdateProductAction::class)->execute($actor, $company, $archived, ['name' => 'Forbidden']))
        ->toThrow(LifecycleOperationException::class)
        ->and(fn () => app(CreateProductVariantAction::class)->execute($actor, $company, $archived, ['name' => 'Forbidden']))
        ->toThrow(LifecycleOperationException::class);
});

test('wrong tenant lifecycle routes are concealed and lifecycle controls match authorization', function () {
    [$owner, $company] = r19Context();
    $product = r19Product($owner, $company);
    $foreignCompany = Company::factory()->create();
    $foreignOwner = User::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $foreignCompany, 'user_id' => $foreignOwner]);
    $this->actingAs($foreignOwner);
    app(CurrentCompany::class)->set($foreignCompany);
    $foreignProduct = r19Product($foreignOwner, $foreignCompany);
    $this->actingAs($owner);
    app(CurrentCompany::class)->set($company);

    $this->post(route('catalog.products.activate', $foreignProduct->uuid))->assertNotFound();
    $this->get(route('catalog.products.activate', $product->uuid))->assertMethodNotAllowed();
    $this->get(route('catalog.products.show', $product->uuid))
        ->assertOk()->assertSee('Activation readiness')->assertSee('Ready')->assertSee('Activate');

    $viewer = User::factory()->create();
    CompanyMembership::factory()->viewer()->create(['company_id' => $company, 'user_id' => $viewer]);
    app(CurrentCompany::class)->set($company);
    $this->actingAs($viewer);
    $this->get(route('catalog.products.show', $product->uuid))
        ->assertOk()->assertSee('Activation readiness')->assertDontSee('>Activate<', false)->assertDontSee('>Archive<', false);
    $this->post(route('catalog.products.activate', $product->uuid))->assertForbidden();
});
