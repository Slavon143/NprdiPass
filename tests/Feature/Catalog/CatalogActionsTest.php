<?php

use App\Actions\Catalog\CreateProductWithDefaultVariantAction;
use App\Actions\Catalog\Exceptions\CatalogIdentifierConflict;
use App\Actions\Catalog\Exceptions\InvalidDefaultVariant;
use App\Actions\Catalog\SetDefaultProductVariantAction;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Models\AuditLog;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{User, Company, CompanyMembership} */
function r13ActionContext(CompanyRole $role = CompanyRole::Owner): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->create([
        'company_id' => $company->id,
        'user_id' => $actor->id,
        'role' => $role,
    ]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company, $membership];
}

function r13CreateProductAction(
    User $actor,
    Company $company,
    string $suffix,
    array $productOverrides = [],
    array $variantOverrides = [],
): Product {
    return app(CreateProductWithDefaultVariantAction::class)->execute(
        $actor,
        $company,
        array_merge([
            'name' => "Product {$suffix}",
            'slug' => "Product {$suffix}",
        ], $productOverrides),
        array_merge([
            'sku' => "SKU {$suffix}",
            'gtin' => null,
            'mpn' => null,
        ], $variantOverrides),
    );
}

function r13AdditionalVariant(Product $product, User $actor, string $suffix): ProductVariant
{
    $variant = new ProductVariant;
    $variant->forceFill([
        'company_id' => $product->company_id,
        'product_id' => $product->id,
        'name' => "Variant {$suffix}",
        'sku' => "EXTRA {$suffix}",
        'sku_normalized' => 'EXTRA'.mb_strtoupper($suffix),
        'status' => ProductVariantStatus::Draft,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();

    return $variant;
}

test('product creation atomically creates and returns one normalized default variant with audit', function () {
    [$actor, $company] = r13ActionContext();
    $foreign = Company::factory()->create();

    $product = app(CreateProductWithDefaultVariantAction::class)->execute(
        $actor,
        $company,
        [
            'company_id' => $foreign->id,
            'name' => '  Nordic Lamp  ',
            'slug' => '  Nordisk Ångström Lamp  ',
            'status' => ProductStatus::Active->value,
            'default_variant_id' => 999,
            'description' => 'Safe description',
        ],
        [
            'company_id' => $foreign->id,
            'product_id' => 999,
            'name' => null,
            'sku' => "  ab  \t 12-c  ",
            'gtin' => ' 4006381333931 ',
            'mpn' => '  Ab  12  ',
        ],
    );

    $variant = $product->defaultVariant;

    expect($product->company_id)->toBe($company->id)
        ->and($product->name)->toBe('Nordic Lamp')
        ->and($product->slug)->toBe('nordisk-angstrom-lamp')
        ->and($product->getRawOriginal('slug_normalized'))->toBe('nordisk-angstrom-lamp')
        ->and($product->status)->toBe(ProductStatus::Draft)
        ->and($product->published_at)->toBeNull()
        ->and($product->created_by)->toBe($actor->id)
        ->and($product->updated_by)->toBe($actor->id)
        ->and($product->relationLoaded('defaultVariant'))->toBeTrue()
        ->and($variant)->toBeInstanceOf(ProductVariant::class)
        ->and($variant->company_id)->toBe($company->id)
        ->and($variant->product_id)->toBe($product->id)
        ->and($variant->name)->toBe('Default')
        ->and($variant->sku)->toBe("ab  \t 12-c")
        ->and($variant->getRawOriginal('sku_normalized'))->toBe('AB12-C')
        ->and($variant->gtin)->toBe('4006381333931')
        ->and($variant->mpn)->toBe('Ab  12')
        ->and($variant->status)->toBe(ProductVariantStatus::Draft)
        ->and($variant->created_by)->toBe($actor->id)
        ->and(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1);

    $productAudit = AuditLog::query()
        ->where('event', AuditEvent::CatalogProductCreated->value)
        ->sole();
    $variantAudit = AuditLog::query()
        ->where('event', AuditEvent::CatalogVariantCreated->value)
        ->sole();

    expect($productAudit->company_id)->toBe($company->id)
        ->and($productAudit->getProperty('product_uuid'))->toBe($product->uuid)
        ->and($productAudit->getProperty('product_name'))->toBe('Nordic Lamp')
        ->and($productAudit->getProperty('status'))->toBe(ProductStatus::Draft->value)
        ->and($productAudit->getProperty('default_variant_uuid'))->toBe($variant->uuid)
        ->and($variantAudit->getProperty('variant_uuid'))->toBe($variant->uuid);
});

test('product creation rejects permission and tenant context failures without orphans', function () {
    [$viewer, $company] = r13ActionContext(CompanyRole::Viewer);

    expect(fn () => r13CreateProductAction($viewer, $company, 'denied'))
        ->toThrow(AuthorizationException::class);

    $owner = User::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $company->id,
        'user_id' => $owner->id,
    ]);
    $other = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $other->id,
        'user_id' => $owner->id,
    ]);
    $this->actingAs($owner);
    app(CurrentCompany::class)->set($other);

    expect(fn () => r13CreateProductAction($owner, $company, 'wrong-current'))
        ->toThrow(AuthorizationException::class)
        ->and(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('inactive company and removed membership cannot create products', function () {
    [$actor, $company, $membership] = r13ActionContext();
    $company->forceFill(['status' => CompanyStatus::Suspended])->save();

    expect(fn () => r13CreateProductAction($actor, $company->refresh(), 'inactive'))
        ->toThrow(AuthorizationException::class);

    $company->forceFill(['status' => CompanyStatus::Active])->save();
    $membership->delete();

    expect(fn () => r13CreateProductAction($actor, $company->refresh(), 'removed'))
        ->toThrow(AuthorizationException::class)
        ->and(Product::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('invalid GTIN is rejected before persistence', function () {
    [$actor, $company] = r13ActionContext();

    expect(fn () => r13CreateProductAction($actor, $company, 'bad-gtin', [], [
        'gtin' => 'ABC4006381333931',
    ]))->toThrow(InvalidArgumentException::class)
        ->and(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('duplicate product slug rolls back the complete second aggregate', function () {
    [$actor, $company] = r13ActionContext();
    r13CreateProductAction($actor, $company, 'first', ['slug' => 'same slug']);

    expect(fn () => r13CreateProductAction($actor, $company, 'second', ['slug' => 'same slug']))
        ->toThrow(CatalogIdentifierConflict::class)
        ->and(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(2);
});

test('duplicate normalized SKU rolls back the complete second aggregate', function () {
    [$actor, $company] = r13ActionContext();
    r13CreateProductAction($actor, $company, 'sku-a', [], ['sku' => 'SKU 001']);

    expect(fn () => r13CreateProductAction($actor, $company, 'sku-b', [], ['sku' => 'sku001']))
        ->toThrow(CatalogIdentifierConflict::class)
        ->and(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(2);
});

test('duplicate GTIN rolls back the complete second aggregate', function () {
    [$actor, $company] = r13ActionContext();
    r13CreateProductAction($actor, $company, 'gtin-a', [], ['gtin' => '4006381333931']);

    expect(fn () => r13CreateProductAction($actor, $company, 'gtin-b', [], [
        'gtin' => '4006381333931',
    ]))->toThrow(CatalogIdentifierConflict::class)
        ->and(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(2);
});

test('audit failure rolls product and variant creation back together', function () {
    [$actor, $company] = r13ActionContext();
    $auditLogger = Mockery::mock(AuditLogger::class);
    $auditLogger->shouldReceive('logTenant')->once()->andThrow(new RuntimeException('audit unavailable'));
    $this->app->instance(AuditLogger::class, $auditLogger);

    expect(fn () => r13CreateProductAction($actor, $company, 'audit-failure'))
        ->toThrow(RuntimeException::class, 'audit unavailable')
        ->and(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('default variant change is transactional audited idempotent and pointer-authoritative', function () {
    [$actor, $company] = r13ActionContext();
    $product = r13CreateProductAction($actor, $company, 'default-change');
    $old = $product->defaultVariant;
    $new = r13AdditionalVariant($product, $actor, 'new');
    $action = app(SetDefaultProductVariantAction::class);

    $changed = $action->execute($actor, $product, $new);
    $audit = AuditLog::query()
        ->where('event', AuditEvent::CatalogVariantDefaultChanged->value)
        ->sole();

    expect($changed->default_variant_id)->toBe($new->id)
        ->and($changed->defaultVariant->is($new))->toBeTrue()
        ->and($audit->getProperty('product_uuid'))->toBe($product->uuid)
        ->and($audit->getProperty('old_default_variant_uuid'))->toBe($old->uuid)
        ->and($audit->getProperty('new_default_variant_uuid'))->toBe($new->uuid)
        ->and(ProductVariant::query()->where('is_default', true)->count())->toBe(0);

    $action->execute($actor, $changed, $new);
    expect(AuditLog::query()
        ->where('event', AuditEvent::CatalogVariantDefaultChanged->value)
        ->count())->toBe(1);
});

test('default variant action rejects wrong product foreign company archived variant and denied actor', function () {
    [$actor, $company] = r13ActionContext();
    $product = r13CreateProductAction($actor, $company, 'protected');
    $otherProduct = r13CreateProductAction($actor, $company, 'other-product');
    $wrongProductVariant = r13AdditionalVariant($otherProduct, $actor, 'wrong-product');
    $archived = r13AdditionalVariant($product, $actor, 'archived');
    $archived->forceFill(['status' => ProductVariantStatus::Archived])->save();
    $action = app(SetDefaultProductVariantAction::class);

    expect(fn () => $action->execute($actor, $product, $wrongProductVariant))
        ->toThrow(InvalidDefaultVariant::class)
        ->and(fn () => $action->execute($actor, $product, $archived))
        ->toThrow(InvalidDefaultVariant::class);

    $foreignCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $foreignCompany->id,
        'user_id' => $actor->id,
    ]);
    app(CurrentCompany::class)->set($foreignCompany);
    $foreignProduct = r13CreateProductAction($actor, $foreignCompany, 'foreign');
    app(CurrentCompany::class)->set($company);

    expect(fn () => $action->execute($actor, $product, $foreignProduct->defaultVariant))
        ->toThrow(InvalidDefaultVariant::class);

    $viewer = User::factory()->create();
    CompanyMembership::factory()->viewer()->create([
        'company_id' => $company->id,
        'user_id' => $viewer->id,
    ]);
    $this->actingAs($viewer);
    app(CurrentCompany::class)->set($company);
    $candidate = r13AdditionalVariant($product, $actor, 'denied');

    expect(fn () => $action->execute($viewer, $product, $candidate))
        ->toThrow(AuthorizationException::class)
        ->and($product->fresh()->default_variant_id)->toBe($product->default_variant_id);
});

test('default variant action re-reads a stale product row under lock', function () {
    [$actor, $company] = r13ActionContext();
    $product = r13CreateProductAction($actor, $company, 'stale-lock');
    $original = $product->defaultVariant;
    $intermediate = r13AdditionalVariant($product, $actor, 'intermediate');
    $staleProduct = Product::query()->findOrFail($product->id);

    Product::query()->whereKey($product->id)->update(['default_variant_id' => $intermediate->id]);
    app(SetDefaultProductVariantAction::class)->execute($actor, $staleProduct, $original);

    $audit = AuditLog::query()
        ->where('event', AuditEvent::CatalogVariantDefaultChanged->value)
        ->sole();

    expect($product->fresh()->default_variant_id)->toBe($original->id)
        ->and($audit->getProperty('old_default_variant_uuid'))->toBe($intermediate->uuid)
        ->and($audit->getProperty('new_default_variant_uuid'))->toBe($original->uuid);
});
