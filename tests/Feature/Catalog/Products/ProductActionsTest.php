<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Products\UpdateProductAction;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Exceptions\Catalog\ProductOperationException;
use App\Models\AuditLog;
use App\Models\Catalog\Category;
use App\Models\Catalog\CategoryProduct;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\ProductCategoryService;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array{User, Company, CompanyMembership} */
function r15ProductContext(CompanyRole $role = CompanyRole::Owner): array
{
    $actor = User::factory()->create();
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

function r15ProductCategory(
    Company $company,
    User $actor,
    string $name,
    CategoryStatus $status = CategoryStatus::Active,
): Category {
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
        'status' => $status,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();

    return $category->refresh();
}

/** @param array<string, mixed> $overrides */
function r15ProductData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Nordic Product',
        'slug' => 'Nordic Product',
        'short_description' => 'Short summary',
        'description' => 'Full safe description',
        'brand' => 'NordiBrand',
        'manufacturer' => 'Nordi Manufacturer AB',
    ], $overrides);
}

test('owner admin and editor create an atomic draft product aggregate', function (CompanyRole $role) {
    [$actor, $company] = r15ProductContext($role);
    $primary = r15ProductCategory($company, $actor, 'Primary');
    $additional = r15ProductCategory($company, $actor, 'Additional');
    $foreign = Company::factory()->create();
    $product = app(CreateProductAction::class)->execute($actor, $company, [
        ...r15ProductData(),
        'company_id' => $foreign->id,
        'status' => ProductStatus::Active->value,
        'published_at' => now(),
        'default_variant_id' => 999,
        'primary_media_id' => 999,
        'created_by' => 999,
        'updated_by' => 999,
    ], $primary->uuid, [$additional->uuid, $primary->uuid, $additional->uuid]);

    expect($product->company_id)->toBe($company->id)
        ->and($product->status)->toBe(ProductStatus::Draft)
        ->and($product->published_at)->toBeNull()
        ->and($product->primary_media_id)->toBeNull()
        ->and($product->created_by)->toBe($actor->id)
        ->and($product->updated_by)->toBe($actor->id)
        ->and($product->slug)->toBe('nordic-product')
        ->and($product->primary_category_id)->toBe($primary->id)
        ->and($product->default_variant_id)->toBe($product->defaultVariant?->id)
        ->and($product->defaultVariant)->toBeInstanceOf(ProductVariant::class)
        ->and($product->defaultVariant?->company_id)->toBe($company->id)
        ->and($product->defaultVariant?->product_id)->toBe($product->id)
        ->and($product->defaultVariant?->name)->toBe('Default')
        ->and($product->defaultVariant?->sku)->toBeNull()
        ->and($product->categories)->toHaveCount(2)
        ->and(CategoryProduct::query()->where('product_id', $product->id)->where('company_id', $company->id)->count())->toBe(2);

    $audit = AuditLog::query()->where('event', AuditEvent::CatalogProductCreated->value)->sole();
    expect($audit->getProperty('product_uuid'))->toBe($product->uuid)
        ->and($audit->getProperty('default_variant_uuid'))->toBe($product->defaultVariant?->uuid)
        ->and($audit->getProperty('primary_category_uuid'))->toBe($primary->uuid)
        ->and($audit->getProperty('category_count'))->toBe(2)
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogVariantCreated->value)->count())->toBe(0);
})->with([
    'owner' => [CompanyRole::Owner],
    'admin' => [CompanyRole::Admin],
    'editor' => [CompanyRole::Editor],
]);

test('draft product may be created without categories and blank slug is generated', function () {
    [$actor, $company] = r15ProductContext();
    $product = app(CreateProductAction::class)->execute($actor, $company, r15ProductData([
        'name' => 'Ångström Work Light',
        'slug' => '',
    ]));

    expect($product->slug)->toBe('angstrom-work-light')
        ->and($product->primary_category_id)->toBeNull()
        ->and($product->categories)->toHaveCount(0)
        ->and($product->defaultVariant)->not->toBeNull();
});

test('product slug is unique per company while the same normalized slug is allowed cross-company', function () {
    [$actor, $company] = r15ProductContext();
    $action = app(CreateProductAction::class);
    $action->execute($actor, $company, r15ProductData(['slug' => 'Shared Slug']));

    expect(fn () => $action->execute($actor, $company, r15ProductData([
        'name' => 'Duplicate', 'slug' => 'SHARED-SLUG',
    ])))->toThrow(ProductOperationException::class, 'already exists')
        ->and(Product::query()->forCompany($company)->count())->toBe(1)
        ->and(ProductVariant::query()->forCompany($company)->count())->toBe(1);

    $otherCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $otherCompany]);
    app(CurrentCompany::class)->set($otherCompany);
    $other = $action->execute($actor, $otherCompany, r15ProductData(['slug' => 'Shared Slug']));
    expect($other->slug)->toBe('shared-slug')->and(Product::query()->count())->toBe(2);
});

test('product creation returns refreshed state without reading the default variant twice at return', function (bool $withCategories) {
    [$actor, $company] = r15ProductContext();
    $primary = $withCategories ? r15ProductCategory($company, $actor, 'Primary') : null;
    $additional = $withCategories ? r15ProductCategory($company, $actor, 'Additional') : null;

    // Change persisted state after the aggregate's initial relation load.
    // Removing refresh or reusing that cached variant must fail this regression.
    AuditLog::query();
    $dispatcher = AuditLog::getEventDispatcher();
    AuditLog::setEventDispatcher(clone $dispatcher);
    AuditLog::created(function (AuditLog $audit) use ($company, $primary): void {
        if ($audit->company_id !== $company->id || $audit->event !== AuditEvent::CatalogProductCreated->value) {
            return;
        }

        DB::table('products')->where('company_id', $company->id)
            ->where('uuid', $audit->getProperty('product_uuid'))->update(['brand' => 'Refreshed brand']);
        DB::table('product_variants')->where('company_id', $company->id)
            ->where('uuid', $audit->getProperty('default_variant_uuid'))->update(['name' => 'Refreshed default']);

        if ($primary !== null) {
            DB::table('categories')->where('company_id', $company->id)
                ->where('id', $primary->id)->update(['name' => 'Refreshed primary']);
        }
    });
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $product = app(CreateProductAction::class)->execute(
            $actor,
            $company,
            r15ProductData(),
            $primary?->uuid,
            $additional === null ? [] : [$additional->uuid],
        );
        $variantReads = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'select ') && str_contains($query['query'], 'from `product_variants`')
        );

        expect($product->brand)->toBe('Refreshed brand')
            ->and($product->relationLoaded('defaultVariant'))->toBeTrue()
            ->and($product->relationLoaded('primaryCategory'))->toBeTrue()
            ->and($product->relationLoaded('categories'))->toBeTrue()
            ->and($product->defaultVariant?->name)->toBe('Refreshed default')
            ->and($product->defaultVariant?->company_id)->toBe($company->id)
            ->and($product->defaultVariant?->product_id)->toBe($product->id)
            ->and($product->primary_category_id)->toBe($primary?->id)
            ->and($product->primaryCategory?->name)->toBe($withCategories ? 'Refreshed primary' : null)
            ->and($product->categories->modelKeys())->toEqualCanonicalizing(
                $withCategories ? [$primary->id, $additional->id] : [],
            )
            ->and($variantReads)->toHaveCount(2);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
        AuditLog::setEventDispatcher($dispatcher);
    }
})->with(['without categories' => false, 'with categories' => true]);

test('invalid category assignment rolls the product variant pivot and audit back together', function (string $case) {
    [$actor, $company] = r15ProductContext();
    $foreign = Company::factory()->create();
    $foreignCategory = r15ProductCategory($foreign, $actor, 'Foreign');
    $archived = r15ProductCategory($company, $actor, 'Archived', CategoryStatus::Archived);
    $primary = null;
    $additional = [];

    if ($case === 'foreign-primary') {
        $primary = $foreignCategory->uuid;
    } elseif ($case === 'foreign-additional') {
        $additional = [$foreignCategory->uuid];
    } else {
        $primary = $archived->uuid;
    }

    expect(fn () => app(CreateProductAction::class)->execute(
        $actor,
        $company,
        r15ProductData(),
        $primary,
        $additional,
    ))->toThrow(ProductOperationException::class)
        ->and(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0)
        ->and(CategoryProduct::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with(['foreign-primary', 'foreign-additional', 'archived']);

test('category assignment enforces the maximum after safe deduplication', function () {
    [$actor, $company] = r15ProductContext();
    $categories = collect(range(1, ProductCategoryService::MAX_CATEGORIES_PER_PRODUCT + 1))
        ->map(fn (int $index): Category => r15ProductCategory($company, $actor, "Category {$index}"));

    expect(fn () => app(CreateProductAction::class)->execute(
        $actor,
        $company,
        r15ProductData(),
        null,
        $categories->pluck('uuid')->all(),
    ))->toThrow(ProductOperationException::class, 'Too many categories')
        ->and(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

test('viewer and inactive tenant contexts cannot create products', function () {
    [$viewer, $company] = r15ProductContext(CompanyRole::Viewer);

    expect(fn () => app(CreateProductAction::class)->execute($viewer, $company, r15ProductData()))
        ->toThrow(AuthorizationException::class);

    [$owner, $activeCompany, $membership] = r15ProductContext();
    $activeCompany->forceFill(['status' => CompanyStatus::Suspended])->save();
    expect(fn () => app(CreateProductAction::class)->execute($owner, $activeCompany, r15ProductData()))
        ->toThrow(AuthorizationException::class);

    $activeCompany->forceFill(['status' => CompanyStatus::Active])->save();
    $membership->delete();
    expect(fn () => app(CreateProductAction::class)->execute($owner, $activeCompany, r15ProductData()))
        ->toThrow(AuthorizationException::class);
});

test('current company mismatch cannot create products', function () {
    [$actor, $company] = r15ProductContext();
    $other = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $other]);
    app(CurrentCompany::class)->set($other);

    expect(fn () => app(CreateProductAction::class)->execute($actor, $company, r15ProductData()))
        ->toThrow(AuthorizationException::class)
        ->and(Product::query()->count())->toBe(0);
});

test('update changes managed fields and atomically replaces primary and additional categories', function () {
    [$actor, $company] = r15ProductContext();
    $oldPrimary = r15ProductCategory($company, $actor, 'Old primary');
    $oldAdditional = r15ProductCategory($company, $actor, 'Old additional');
    $newPrimary = r15ProductCategory($company, $actor, 'New primary');
    $newAdditional = r15ProductCategory($company, $actor, 'New additional');
    $product = app(CreateProductAction::class)->execute(
        $actor,
        $company,
        r15ProductData(['slug' => 'original']),
        $oldPrimary->uuid,
        [$oldAdditional->uuid],
    );
    $originalDefaultVariantId = $product->default_variant_id;
    $originalCreatedBy = $product->created_by;
    $foreign = Company::factory()->create();
    $updated = app(UpdateProductAction::class)->execute($actor, $company, $product, [
        ...r15ProductData([
            'name' => ' Updated Product ',
            'slug' => 'Ångström Updated',
            'short_description' => ' Updated summary ',
            'description' => ' Updated description ',
            'brand' => ' Updated Brand ',
            'manufacturer' => ' Updated Manufacturer ',
        ]),
        'company_id' => $foreign->id,
        'status' => ProductStatus::Archived->value,
        'published_at' => now(),
        'default_variant_id' => 999,
        'primary_media_id' => 999,
        'created_by' => 999,
    ], $newPrimary->uuid, [$newAdditional->uuid, $newPrimary->uuid], syncCategories: true);

    expect($updated->name)->toBe('Updated Product')
        ->and($updated->slug)->toBe('angstrom-updated')
        ->and($updated->short_description)->toBe('Updated summary')
        ->and($updated->description)->toBe('Updated description')
        ->and($updated->brand)->toBe('Updated Brand')
        ->and($updated->manufacturer)->toBe('Updated Manufacturer')
        ->and($updated->company_id)->toBe($company->id)
        ->and($updated->status)->toBe(ProductStatus::Draft)
        ->and($updated->published_at)->toBeNull()
        ->and($updated->default_variant_id)->toBe($originalDefaultVariantId)
        ->and($updated->primary_media_id)->toBeNull()
        ->and($updated->created_by)->toBe($originalCreatedBy)
        ->and($updated->updated_by)->toBe($actor->id)
        ->and($updated->primary_category_id)->toBe($newPrimary->id)
        ->and($updated->categories->pluck('id')->all())->toEqualCanonicalizing([$newPrimary->id, $newAdditional->id]);

    $pivot = CategoryProduct::query()->where('product_id', $product->id)->get();
    expect($pivot)->toHaveCount(2)
        ->and($pivot->pluck('company_id')->unique()->all())->toBe([$company->id])
        ->and($pivot->pluck('category_id')->all())->not->toContain($oldPrimary->id, $oldAdditional->id);

    $audit = AuditLog::query()->where('event', AuditEvent::CatalogProductUpdated->value)->sole();
    expect($audit->getProperty('old_primary_category_uuid'))->toBe($oldPrimary->uuid)
        ->and($audit->getProperty('new_primary_category_uuid'))->toBe($newPrimary->uuid)
        ->and($audit->getProperty('old_category_count'))->toBe(2)
        ->and($audit->getProperty('new_category_count'))->toBe(2)
        ->and($audit->properties->has('description'))->toBeFalse();
});

test('no-op update writes neither product nor audit', function () {
    [$actor, $company] = r15ProductContext();
    $primary = r15ProductCategory($company, $actor, 'Primary');
    $product = app(CreateProductAction::class)->execute($actor, $company, r15ProductData(), $primary->uuid);
    $updatedAt = $product->updated_at;

    $updated = app(UpdateProductAction::class)->execute(
        $actor,
        $company,
        $product,
        r15ProductData(['slug' => 'nordic-product']),
        $primary->uuid,
        [],
    );

    expect($updated->updated_at?->equalTo($updatedAt))->toBeTrue()
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogProductUpdated->value)->count())->toBe(0);
});

test('update without category intent preserves taxonomy and explicit category sync replaces it', function () {
    [$actor, $company] = r15ProductContext();
    $oldPrimary = r15ProductCategory($company, $actor, 'Old primary');
    $oldAdditional = r15ProductCategory($company, $actor, 'Old additional');
    $newPrimary = r15ProductCategory($company, $actor, 'New primary');
    $product = app(CreateProductAction::class)->execute($actor, $company, r15ProductData([
        'name' => 'Original', 'slug' => 'original',
    ]), $oldPrimary->uuid, [$oldAdditional->uuid]);

    // Field-only update (e.g. the Fortnox applier writing name/description)
    // must not touch primary or additional categories.
    $updated = app(UpdateProductAction::class)->execute(
        $actor,
        $company,
        $product,
        ['name' => 'Renamed without category intent'],
    );

    expect($updated->name)->toBe('Renamed without category intent')
        ->and($updated->primary_category_id)->toBe($oldPrimary->id)
        ->and($updated->categories()->pluck('categories.id')->all())->toEqualCanonicalizing([$oldPrimary->id, $oldAdditional->id]);

    // Explicit category intent still replaces the taxonomy, and empty lists clear it.
    $cleared = app(UpdateProductAction::class)->execute(
        $actor,
        $company,
        $product,
        [],
        null,
        [],
        true,
    );

    expect($cleared->primary_category_id)->toBeNull()
        ->and($cleared->categories()->count())->toBe(0);

    $replaced = app(UpdateProductAction::class)->execute(
        $actor,
        $company,
        $product,
        [],
        $newPrimary->uuid,
        [],
        true,
    );

    expect($replaced->primary_category_id)->toBe($newPrimary->id)
        ->and($replaced->categories()->pluck('categories.id')->all())->toBe([$newPrimary->id]);
});

test('duplicate slug and unavailable category roll the complete update back', function (string $case) {
    [$actor, $company] = r15ProductContext();
    $oldPrimary = r15ProductCategory($company, $actor, 'Old primary');
    $product = app(CreateProductAction::class)->execute($actor, $company, r15ProductData([
        'name' => 'Original', 'slug' => 'original',
    ]), $oldPrimary->uuid);
    app(CreateProductAction::class)->execute($actor, $company, r15ProductData([
        'name' => 'Reserved', 'slug' => 'reserved',
    ]));
    $archived = r15ProductCategory($company, $actor, 'Archived', CategoryStatus::Archived);
    $newSlug = $case === 'duplicate-slug' ? 'reserved' : 'changed';
    $newPrimary = $case === 'duplicate-slug' ? $oldPrimary->uuid : $archived->uuid;

    expect(fn () => app(UpdateProductAction::class)->execute(
        $actor,
        $company,
        $product,
        r15ProductData(['name' => 'Must roll back', 'slug' => $newSlug]),
        $newPrimary,
        [],
        true,
    ))->toThrow(ProductOperationException::class)
        ->and($product->fresh()?->name)->toBe('Original')
        ->and($product->fresh()?->slug)->toBe('original')
        ->and($product->fresh()?->primary_category_id)->toBe($oldPrimary->id)
        ->and($product->fresh()?->categories()->pluck('categories.id')->all())->toBe([$oldPrimary->id])
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogProductUpdated->value)->count())->toBe(0);
})->with(['duplicate-slug', 'archived-category']);

test('audit failure rolls product fields category pointers and pivots back', function () {
    [$actor, $company] = r15ProductContext();
    $oldPrimary = r15ProductCategory($company, $actor, 'Old primary');
    $newPrimary = r15ProductCategory($company, $actor, 'New primary');
    $product = app(CreateProductAction::class)->execute($actor, $company, r15ProductData([
        'name' => 'Original', 'slug' => 'original',
    ]), $oldPrimary->uuid);
    $logger = Mockery::mock(AuditLogger::class);
    $logger->shouldReceive('logTenant')->once()->andThrow(new RuntimeException('Audit unavailable'));
    app()->instance(AuditLogger::class, $logger);

    try {
        expect(fn () => app(UpdateProductAction::class)->execute(
            $actor,
            $company,
            $product,
            r15ProductData(['name' => 'Changed', 'slug' => 'changed']),
            $newPrimary->uuid,
            [],
            true,
        ))->toThrow(RuntimeException::class, 'Audit unavailable');
    } finally {
        app()->forgetInstance(AuditLogger::class);
    }

    expect($product->fresh()?->name)->toBe('Original')
        ->and($product->fresh()?->slug)->toBe('original')
        ->and($product->fresh()?->primary_category_id)->toBe($oldPrimary->id)
        ->and($product->fresh()?->categories()->pluck('categories.id')->all())->toBe([$oldPrimary->id])
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogProductUpdated->value)->count())->toBe(0);
});

test('update rejects a product from another company', function () {
    [$actor, $company] = r15ProductContext();
    $other = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $other]);
    app(CurrentCompany::class)->set($other);
    $foreignProduct = app(CreateProductAction::class)->execute($actor, $other, r15ProductData());
    app(CurrentCompany::class)->set($company);

    expect(fn () => app(UpdateProductAction::class)->execute(
        $actor,
        $company,
        $foreignProduct,
        r15ProductData(),
    ))->toThrow(ProductOperationException::class);
});
