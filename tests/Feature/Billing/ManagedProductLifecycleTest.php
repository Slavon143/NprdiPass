<?php

use App\Actions\Catalog\Lifecycle\ArchiveProductAction;
use App\Actions\Catalog\Lifecycle\RestoreProductAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Products\SetProductManagedAction;
use App\Actions\Companies\CreateCompanyForOwner;
use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Enums\Catalog\ProductStatus;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function () {
    config(['billing.commercial_enabled' => true, 'billing.plans' => config('billing.commercial_plans')]);
    Http::preventStrayRequests();
    Queue::fake();
    $this->actor = User::factory()->create();
    $this->company = app(CreateCompanyForOwner::class)->execute($this->actor, 'Managed lifecycle', '1234567890', 'SE');
    $this->actingAs($this->actor);
    app(CurrentCompany::class)->set($this->company);
    // RefreshDatabase wraps this fixture in a transaction; the root-only reconciler
    // is covered by the independent concurrency suite. This new tenant is empty.
    DB::table('catalog_product_capacities')->where('company_id', $this->company->id)->update(['products_count' => 0]);
});

test('manual creation uses a managed slot while imported commercial products only use the pool', function () {
    $creator = app(CreateProductAction::class);
    $manual = $creator->execute($this->actor, $this->company, ['name' => 'Manual', 'managed' => false]);
    $imported = $creator->execute($this->actor, $this->company, ['name' => 'Imported', 'managed' => true], imported: true);
    expect($manual->managed)->toBeTrue()->and($imported->managed)->toBeFalse()
        ->and($imported->defaultVariant)->not->toBeNull()
        ->and($this->company->catalogCapacity()->value('products_count'))->toBe(1)
        ->and($this->company->catalogCapacity()->value('total_products_count'))->toBe(2);
    expect(fn () => app(CreateProductPassportDraftAction::class)->handle($this->actor, $this->company, $imported))
        ->toThrow(ConflictHttpException::class);
});

test('enrollment checks the current limit and duplicate enrollment never consumes another slot', function () {
    $this->company->subscription()->firstOrFail()->update(['entitlements' => ['catalog.products.max' => 1, 'catalog.total.max' => 100]]);
    $creator = app(CreateProductAction::class);
    $first = $creator->execute($this->actor, $this->company, ['name' => 'First'], imported: true);
    $second = $creator->execute($this->actor, $this->company, ['name' => 'Second'], imported: true);
    $action = app(SetProductManagedAction::class);
    $action->execute($this->actor, $this->company, $first, true);
    $action->execute($this->actor, $this->company, $first, true);
    expect(fn () => $action->execute($this->actor, $this->company, $second, true))->toThrow(ValidationException::class);
    expect($second->fresh()->managed)->toBeFalse()->and($this->company->catalogCapacity()->value('products_count'))->toBe(1);
    $action->execute($this->actor, $this->company, $first, false);
    $action->execute($this->actor, $this->company, $second, true);
    expect($this->company->catalogCapacity()->value('products_count'))->toBe(1)
        ->and($this->company->catalogCapacity()->value('total_products_count'))->toBe(2);
});

test('pool admission bounds imports independently from managed product slots and counts retained rows', function () {
    $this->company->subscription()->firstOrFail()->update(['entitlements' => ['catalog.products.max' => 1, 'catalog.total.max' => 1]]);
    $creator = app(CreateProductAction::class);
    $product = $creator->execute($this->actor, $this->company, ['name' => 'Retained'], imported: true);
    $product->delete();
    expect(fn () => $creator->execute($this->actor, $this->company, ['name' => 'Over pool'], imported: true))->toThrow(ValidationException::class);
    expect($this->company->catalogCapacity()->value('products_count'))->toBe(0)
        ->and($this->company->catalogCapacity()->value('total_products_count'))->toBe(1);
});

test('unmanaged archived products can be restored without using an unavailable managed slot', function () {
    $product = app(CreateProductAction::class)->execute($this->actor, $this->company, ['name' => 'Pool archive'], imported: true);
    app(ArchiveProductAction::class)->execute($this->actor, $this->company, $product);
    $this->company->subscription()->firstOrFail()->update(['entitlements' => ['catalog.products.max' => 0, 'catalog.total.max' => 100]]);
    $restored = app(RestoreProductAction::class)->execute($this->actor, $this->company, $product);
    expect($restored->managed)->toBeFalse()->and($restored->status)->toBe(ProductStatus::Draft)
        ->and($this->company->catalogCapacity()->value('products_count'))->toBe(0);
});

test('a stale managed product object cannot create a draft after unenrollment', function () {
    $product = app(CreateProductAction::class)->execute($this->actor, $this->company, ['name' => 'Stale managed']);
    app(SetProductManagedAction::class)->execute($this->actor, $this->company, $product, false);
    expect($product->managed)->toBeTrue();
    expect(fn () => app(CreateProductPassportDraftAction::class)->handle($this->actor, $this->company, $product))
        ->toThrow(ConflictHttpException::class);
    expect(ProductPassport::query()->forCompany($this->company)->count())->toBe(0);
});

test('enrollment cannot modify a foreign tenant product', function () {
    $other = app(CreateCompanyForOwner::class)->execute($this->actor, 'Foreign company', '1234567891', 'SE');
    app(CurrentCompany::class)->set($other);
    $foreign = app(CreateProductAction::class)->execute($this->actor, $other, ['name' => 'Foreign product'], imported: true);
    app(CurrentCompany::class)->set($this->company);
    expect(fn () => app(SetProductManagedAction::class)->execute($this->actor, $this->company, $foreign, true))
        ->toThrow(ModelNotFoundException::class);
    expect($this->company->catalogCapacity()->value('products_count'))->toBe(0)
        ->and($foreign->fresh()->managed)->toBeFalse();
});
