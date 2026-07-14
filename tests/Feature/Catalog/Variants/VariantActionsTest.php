<?php

use App\Actions\Catalog\Exceptions\InvalidDefaultVariant;
use App\Actions\Catalog\Products\ProductAggregateCreator;
use App\Actions\Catalog\SetDefaultProductVariantAction;
use App\Actions\Catalog\Variants\CreateProductVariantAction;
use App\Actions\Catalog\Variants\UpdateProductVariantAction;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Exceptions\Catalog\VariantOperationException;
use App\Models\AuditLog;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\ProductVariantService;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{User, Company, CompanyMembership} */
function r16VariantContext(CompanyRole $role = CompanyRole::Owner): array
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->create([
        'user_id' => $actor,
        'company_id' => $company,
        'role' => $role,
    ]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company, $membership];
}

function r16Product(Company $company, User $actor, string $slug = 'variant-product'): Product
{
    return app(ProductAggregateCreator::class)->create($actor, $company, [
        'name' => str($slug)->headline()->toString(),
        'slug' => $slug,
        'short_description' => null,
        'description' => null,
        'brand' => null,
        'manufacturer' => null,
    ], [
        'name' => 'Default',
        'sku' => null,
        'sku_normalized' => null,
        'gtin' => null,
        'mpn' => null,
        'sort_order' => 0,
    ]);
}

/** @param array<string, mixed> $overrides */
function r16VariantData(array $overrides = []): array
{
    return array_replace([
        'name' => 'Large Blue',
        'sku' => ' blue  large-01 ',
        'gtin' => '4006381333931',
        'mpn' => '  MPN Blue 01  ',
        'sort_order' => 20,
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function r16DirectVariant(
    Company $company,
    Product $product,
    User $actor,
    array $overrides = [],
): ProductVariant {
    $variant = new ProductVariant;
    $variant->forceFill(array_replace([
        'company_id' => $company->id,
        'product_id' => $product->id,
        'name' => 'Direct variant',
        'sku' => null,
        'sku_normalized' => null,
        'gtin' => null,
        'mpn' => null,
        'status' => ProductVariantStatus::Draft,
        'sort_order' => 10,
        'primary_media_id' => null,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ], $overrides))->save();

    return $variant->refresh();
}

test('owner admin and editor create a trusted draft variant without replacing the default', function (CompanyRole $role) {
    [$actor, $company] = r16VariantContext($role);
    $product = r16Product($company, $actor);
    $defaultId = $product->default_variant_id;
    $foreign = Company::factory()->create();

    $variant = app(CreateProductVariantAction::class)->execute($actor, $company, $product, [
        ...r16VariantData(),
        'company_id' => $foreign->id,
        'product_id' => 999999,
        'status' => ProductVariantStatus::Active->value,
        'primary_media_id' => 999999,
        'created_by' => 999999,
        'updated_by' => 999999,
        'is_default' => true,
    ]);

    expect($variant->company_id)->toBe($company->id)
        ->and($variant->product_id)->toBe($product->id)
        ->and($variant->status)->toBe(ProductVariantStatus::Draft)
        ->and($variant->name)->toBe('Large Blue')
        ->and($variant->sku)->toBe('blue  large-01')
        ->and($variant->getRawOriginal('sku_normalized'))->toBe('BLUELARGE-01')
        ->and($variant->gtin)->toBe('4006381333931')
        ->and($variant->mpn)->toBe('MPN Blue 01')
        ->and($variant->sort_order)->toBe(20)
        ->and($variant->primary_media_id)->toBeNull()
        ->and($variant->created_by)->toBe($actor->id)
        ->and($variant->updated_by)->toBe($actor->id)
        ->and($product->fresh()?->default_variant_id)->toBe($defaultId)
        ->and(ProductVariant::query()->where('is_default', true)->count())->toBe(0);

    $audit = AuditLog::query()->where('event', AuditEvent::CatalogVariantCreated->value)->sole();
    expect($audit->company_id)->toBe($company->id)
        ->and($audit->properties->get('product_uuid'))->toBe($product->uuid)
        ->and($audit->properties->get('variant_uuid'))->toBe($variant->uuid)
        ->and($audit->properties->get('sku'))->toBe('blue  large-01')
        ->and($audit->properties->get('gtin_present'))->toBeTrue()
        ->and($audit->properties->get('mpn_present'))->toBeTrue()
        ->and($audit->properties->has('gtin'))->toBeFalse();
})->with([
    'owner' => [CompanyRole::Owner],
    'admin' => [CompanyRole::Admin],
    'editor' => [CompanyRole::Editor],
]);

test('variant display name falls back to SKU and then a short UUID', function () {
    [$actor, $company] = r16VariantContext();
    $product = r16Product($company, $actor);
    $skuVariant = r16DirectVariant($company, $product, $actor, ['name' => null, 'sku' => 'SKU-FALLBACK']);
    $uuidVariant = r16DirectVariant($company, $product, $actor, ['name' => null, 'sku' => null]);

    expect($skuVariant->displayName())->toBe('SKU-FALLBACK')
        ->and($uuidVariant->displayName())->toBe(substr($uuidVariant->uuid, 0, 8));
});

test('duplicate SKU and GTIN are mapped by MySQL constraint while cross company values remain allowed', function () {
    [$actor, $company] = r16VariantContext();
    $product = r16Product($company, $actor, 'company-a-product');
    $action = app(CreateProductVariantAction::class);
    $action->execute($actor, $company, $product, r16VariantData());

    expect(fn () => $action->execute($actor, $company, $product, r16VariantData([
        'name' => 'Duplicate SKU',
        'gtin' => '036000291452',
    ])))->toThrow(VariantOperationException::class, 'A variant with this SKU already exists in this company.')
        ->and(fn () => $action->execute($actor, $company, $product, r16VariantData([
            'name' => 'Duplicate GTIN',
            'sku' => 'UNIQUE-SKU',
        ])))->toThrow(VariantOperationException::class, 'This GTIN is already assigned to another variant.');

    $otherCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $otherCompany]);
    app(CurrentCompany::class)->set($otherCompany);
    $otherProduct = r16Product($otherCompany, $actor, 'company-b-product');
    $crossTenant = $action->execute($actor, $otherCompany, $otherProduct, r16VariantData());

    expect($crossTenant->sku)->toBe('blue  large-01')
        ->and($crossTenant->gtin)->toBe('4006381333931')
        ->and(ProductVariant::query()->where('sku_normalized', 'BLUELARGE-01')->count())->toBe(2)
        ->and(ProductVariant::query()->where('gtin', '4006381333931')->count())->toBe(2);
});

test('invalid GTIN formats are rejected before any variant or audit is written', function (string $gtin, string $message) {
    [$actor, $company] = r16VariantContext();
    $product = r16Product($company, $actor);
    $before = ProductVariant::query()->count();

    expect(fn () => app(CreateProductVariantAction::class)->execute(
        $actor,
        $company,
        $product,
        r16VariantData(['gtin' => $gtin]),
    ))->toThrow(VariantOperationException::class, $message)
        ->and(ProductVariant::query()->count())->toBe($before)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'check digit' => ['4006381333932', 'The GTIN check digit is invalid.'],
    'letters' => ['ABC6381333931', 'The GTIN must contain only digits.'],
    'spaces' => ['4006 381333931', 'The GTIN must contain only digits.'],
    'hyphen' => ['400638-1333931', 'The GTIN must contain only digits.'],
    'length' => ['1234567', 'The GTIN must contain 8, 12, 13, or 14 digits.'],
]);

test('action enforces exact variant field boundaries without relying on HTTP validation', function (array $overrides, string $message) {
    [$actor, $company] = r16VariantContext();
    $product = r16Product($company, $actor);
    $before = ProductVariant::query()->count();

    expect(fn () => app(CreateProductVariantAction::class)->execute(
        $actor,
        $company,
        $product,
        r16VariantData($overrides),
    ))->toThrow(VariantOperationException::class, $message)
        ->and(ProductVariant::query()->count())->toBe($before);
})->with([
    'name' => [['name' => str_repeat('N', 256)], 'The name field may not exceed 255 characters.'],
    'display SKU' => [['sku' => str_repeat('S ', 51)], 'The SKU field may not exceed 100 characters.'],
    'MPN' => [['mpn' => str_repeat('M', 101)], 'MPN exceeds 100 characters.'],
    'sort order' => [['sort_order' => -1], 'The sort order must be a non-negative integer.'],
]);

test('viewer inactive company missing membership and current company mismatch cannot create variants', function () {
    [$viewer, $company] = r16VariantContext(CompanyRole::Viewer);
    $product = r16Product($company, $viewer);
    expect(fn () => app(CreateProductVariantAction::class)->execute($viewer, $company, $product, r16VariantData()))
        ->toThrow(AuthorizationException::class);

    [$owner, $activeCompany, $membership] = r16VariantContext();
    $activeProduct = r16Product($activeCompany, $owner, 'inactive-context-product');
    $activeCompany->forceFill(['status' => CompanyStatus::Suspended])->save();
    expect(fn () => app(CreateProductVariantAction::class)->execute($owner, $activeCompany, $activeProduct, r16VariantData()))
        ->toThrow(AuthorizationException::class);

    $activeCompany->forceFill(['status' => CompanyStatus::Active])->save();
    $membership->delete();
    expect(fn () => app(CreateProductVariantAction::class)->execute($owner, $activeCompany, $activeProduct, r16VariantData()))
        ->toThrow(AuthorizationException::class);

    $otherCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $owner, 'company_id' => $otherCompany]);
    app(CurrentCompany::class)->set($otherCompany);
    CompanyMembership::factory()->owner()->create(['user_id' => $owner, 'company_id' => $activeCompany]);
    expect(fn () => app(CreateProductVariantAction::class)->execute($owner, $activeCompany, $activeProduct, r16VariantData()))
        ->toThrow(AuthorizationException::class);
});

test('wrong tenant product is rejected without creating a variant', function () {
    [$actor, $company] = r16VariantContext();
    $foreign = Company::factory()->create();
    $foreignProduct = r16Product($foreign, $actor, 'foreign-product');
    $before = ProductVariant::query()->count();

    expect(fn () => app(CreateProductVariantAction::class)->execute(
        $actor,
        $company,
        $foreignProduct,
        r16VariantData(),
    ))->toThrow(VariantOperationException::class)
        ->and(ProductVariant::query()->count())->toBe($before)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('locked fresh variant count allows the limit and rejects the next stale create', function () {
    [$actor, $company] = r16VariantContext();
    $product = r16Product($company, $actor);
    $staleProduct = $product->fresh();

    foreach (range(1, ProductVariantService::MAX_VARIANTS_PER_PRODUCT - 2) as $number) {
        r16DirectVariant($company, $product, $actor, [
            'name' => "Capacity {$number}",
            'sort_order' => $number,
        ]);
    }

    $action = app(CreateProductVariantAction::class);
    $lastAllowed = $action->execute($actor, $company, $staleProduct, r16VariantData([
        'sku' => 'LAST-ALLOWED',
        'gtin' => null,
    ]));
    $defaultId = $product->default_variant_id;

    expect(ProductVariant::query()->where('product_id', $product->id)->count())
        ->toBe(ProductVariantService::MAX_VARIANTS_PER_PRODUCT)
        ->and($lastAllowed->sku)->toBe('LAST-ALLOWED')
        ->and(fn () => $action->execute($actor, $company, $staleProduct, r16VariantData([
            'sku' => 'OVER-LIMIT', 'gtin' => null,
        ])))->toThrow(VariantOperationException::class, 'The maximum number of variants has been reached.')
        ->and(ProductVariant::query()->where('product_id', $product->id)->count())
        ->toBe(ProductVariantService::MAX_VARIANTS_PER_PRODUCT)
        ->and($product->fresh()?->default_variant_id)->toBe($defaultId);
});

test('update changes only managed fields and keeps the default pointer unchanged', function () {
    [$actor, $company] = r16VariantContext();
    $product = r16Product($company, $actor);
    $variant = app(CreateProductVariantAction::class)->execute($actor, $company, $product, r16VariantData());
    AuditLog::query()->delete();
    $newActor = User::factory()->create();
    CompanyMembership::factory()->editor()->create(['user_id' => $newActor, 'company_id' => $company]);
    test()->actingAs($newActor);
    $foreign = Company::factory()->create();

    $updated = app(UpdateProductVariantAction::class)->execute($newActor, $company, $product, $variant, [
        'name' => ' Updated Variant ',
        'sku' => ' new sku-02 ',
        'gtin' => '036000291452',
        'mpn' => '  New MPN 02  ',
        'sort_order' => 30,
        'company_id' => $foreign->id,
        'product_id' => 999999,
        'status' => ProductVariantStatus::Archived->value,
        'primary_media_id' => 999999,
        'created_by' => $newActor->id,
        'is_default' => true,
    ]);

    expect($updated->name)->toBe('Updated Variant')
        ->and($updated->sku)->toBe('new sku-02')
        ->and($updated->getRawOriginal('sku_normalized'))->toBe('NEWSKU-02')
        ->and($updated->gtin)->toBe('036000291452')
        ->and($updated->mpn)->toBe('New MPN 02')
        ->and($updated->sort_order)->toBe(30)
        ->and($updated->company_id)->toBe($company->id)
        ->and($updated->product_id)->toBe($product->id)
        ->and($updated->status)->toBe(ProductVariantStatus::Draft)
        ->and($updated->primary_media_id)->toBeNull()
        ->and($updated->created_by)->toBe($actor->id)
        ->and($updated->updated_by)->toBe($newActor->id)
        ->and($product->fresh()?->default_variant_id)->not->toBe($updated->id)
        ->and(ProductVariant::query()->where('is_default', true)->count())->toBe(0);

    $audit = AuditLog::query()->where('event', AuditEvent::CatalogVariantUpdated->value)->sole();
    expect($audit->properties->get('changed_fields'))->toBe(['name', 'sku', 'gtin', 'mpn', 'sort_order'])
        ->and($audit->properties->has('gtin'))->toBeFalse()
        ->and($audit->properties->has('mpn'))->toBeFalse();
});

test('update can clear identifiers and exact no-op writes no audit', function () {
    [$actor, $company] = r16VariantContext();
    $product = r16Product($company, $actor);
    $variant = app(CreateProductVariantAction::class)->execute($actor, $company, $product, r16VariantData());
    AuditLog::query()->delete();

    $updated = app(UpdateProductVariantAction::class)->execute($actor, $company, $product, $variant, [
        'name' => $variant->name,
        'sku' => null,
        'gtin' => null,
        'mpn' => null,
        'sort_order' => $variant->sort_order,
    ]);
    expect($updated->sku)->toBeNull()
        ->and($updated->getRawOriginal('sku_normalized'))->toBeNull()
        ->and($updated->gtin)->toBeNull()
        ->and($updated->mpn)->toBeNull()
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogVariantUpdated->value)->count())->toBe(1);

    AuditLog::query()->delete();
    $timestamp = $updated->updated_at;
    app(UpdateProductVariantAction::class)->execute($actor, $company, $product, $updated, [
        'name' => $updated->name,
        'sku' => null,
        'gtin' => null,
        'mpn' => null,
        'sort_order' => $updated->sort_order,
    ]);

    expect(AuditLog::query()->count())->toBe(0)
        ->and($updated->fresh()?->updated_at?->equalTo($timestamp))->toBeTrue();
});

test('update rejects duplicate identifiers wrong product and wrong tenant variants', function () {
    [$actor, $company] = r16VariantContext();
    $product = r16Product($company, $actor, 'first-product');
    $otherProduct = r16Product($company, $actor, 'second-product');
    $first = app(CreateProductVariantAction::class)->execute($actor, $company, $product, r16VariantData());
    $second = app(CreateProductVariantAction::class)->execute($actor, $company, $product, r16VariantData([
        'name' => 'Second', 'sku' => 'SECOND-SKU', 'gtin' => '036000291452',
    ]));
    AuditLog::query()->delete();

    expect(fn () => app(UpdateProductVariantAction::class)->execute($actor, $company, $product, $second, [
        ...r16VariantData(['sku' => $first->sku, 'gtin' => $second->gtin]),
    ]))->toThrow(VariantOperationException::class, 'A variant with this SKU already exists in this company.')
        ->and(fn () => app(UpdateProductVariantAction::class)->execute($actor, $company, $product, $second, [
            ...r16VariantData(['sku' => $second->sku, 'gtin' => $first->gtin]),
        ]))->toThrow(VariantOperationException::class, 'This GTIN is already assigned to another variant.')
        ->and(fn () => app(UpdateProductVariantAction::class)->execute($actor, $company, $otherProduct, $second, r16VariantData()))
        ->toThrow(VariantOperationException::class, 'The selected variant does not belong to this product.');

    $foreign = Company::factory()->create();
    $foreignProduct = r16Product($foreign, $actor, 'foreign-update-product');
    $foreignVariant = r16DirectVariant($foreign, $foreignProduct, $actor);
    expect(fn () => app(UpdateProductVariantAction::class)->execute($actor, $company, $product, $foreignVariant, r16VariantData()))
        ->toThrow(VariantOperationException::class)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('audit failure rolls create and update mutations back', function () {
    [$actor, $company] = r16VariantContext();
    $product = r16Product($company, $actor);
    $logger = Mockery::mock(AuditLogger::class);
    $logger->shouldReceive('logTenant')->once()->andThrow(new RuntimeException('Audit unavailable'));
    app()->instance(AuditLogger::class, $logger);
    $before = ProductVariant::query()->count();

    try {
        expect(fn () => app(CreateProductVariantAction::class)->execute($actor, $company, $product, r16VariantData()))
            ->toThrow(RuntimeException::class, 'Audit unavailable');
    } finally {
        app()->forgetInstance(AuditLogger::class);
    }

    expect(ProductVariant::query()->count())->toBe($before);

    $variant = r16DirectVariant($company, $product, $actor, ['name' => 'Original']);
    $logger = Mockery::mock(AuditLogger::class);
    $logger->shouldReceive('logTenant')->once()->andThrow(new RuntimeException('Audit unavailable'));
    app()->instance(AuditLogger::class, $logger);

    try {
        expect(fn () => app(UpdateProductVariantAction::class)->execute(
            $actor,
            $company,
            $product,
            $variant,
            r16VariantData(['name' => 'Changed', 'gtin' => null]),
        ))->toThrow(RuntimeException::class, 'Audit unavailable');
    } finally {
        app()->forgetInstance(AuditLogger::class);
    }

    expect($variant->fresh()?->name)->toBe('Original');
});

test('editor sets a same product variant as default and repeated selection is an audit-free no-op', function () {
    [$editor, $company] = r16VariantContext(CompanyRole::Editor);
    $product = r16Product($company, $editor);
    $oldDefaultId = $product->default_variant_id;
    $variant = r16DirectVariant($company, $product, $editor, ['name' => 'New default']);
    $action = app(SetDefaultProductVariantAction::class);

    $updated = $action->execute($editor, $product, $variant);
    expect($updated->default_variant_id)->toBe($variant->id)
        ->and($updated->default_variant_id)->not->toBe($oldDefaultId)
        ->and(ProductVariant::query()->where('is_default', true)->count())->toBe(0)
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogVariantDefaultChanged->value)->count())->toBe(1);

    $action->execute($editor, $product, $variant);
    expect(AuditLog::query()->where('event', AuditEvent::CatalogVariantDefaultChanged->value)->count())->toBe(1);
});

test('default selection rejects archived wrong product wrong company and viewer variants', function () {
    [$owner, $company] = r16VariantContext();
    $product = r16Product($company, $owner, 'default-product');
    $otherProduct = r16Product($company, $owner, 'other-default-product');
    $archived = r16DirectVariant($company, $product, $owner, ['status' => ProductVariantStatus::Archived]);
    $wrongProduct = r16DirectVariant($company, $otherProduct, $owner);
    $foreign = Company::factory()->create();
    $foreignProduct = r16Product($foreign, $owner, 'foreign-default-product');
    $foreignVariant = r16DirectVariant($foreign, $foreignProduct, $owner);
    $action = app(SetDefaultProductVariantAction::class);

    expect(fn () => $action->execute($owner, $product, $archived))->toThrow(InvalidDefaultVariant::class)
        ->and(fn () => $action->execute($owner, $product, $wrongProduct))->toThrow(InvalidDefaultVariant::class)
        ->and(fn () => $action->execute($owner, $product, $foreignVariant))->toThrow(InvalidDefaultVariant::class);

    [$viewer, $viewerCompany] = r16VariantContext(CompanyRole::Viewer);
    $viewerProduct = r16Product($viewerCompany, $viewer, 'viewer-default-product');
    $viewerVariant = r16DirectVariant($viewerCompany, $viewerProduct, $viewer);
    expect(fn () => $action->execute($viewer, $viewerProduct, $viewerVariant))
        ->toThrow(AuthorizationException::class);
});
