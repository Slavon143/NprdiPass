<?php

use App\Actions\Catalog\Products\ProductAggregateCreator;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;

uses(RefreshDatabase::class);

/** @return array{User, Company, CompanyMembership} */
function r16UiVariantContext(CompanyRole $role = CompanyRole::Owner): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->create([
        'user_id' => $user,
        'company_id' => $company,
        'role' => $role,
    ]);

    return [$user, $company, $membership];
}

function r16UiVariantProduct(Company $company, User $actor, string $slug = 'ui-variant-product'): Product
{
    return app(ProductAggregateCreator::class)->create($actor, $company, [
        'name' => str($slug)->headline()->toString(),
        'slug' => $slug,
        'short_description' => null,
        'description' => null,
        'brand' => null,
        'manufacturer' => null,
    ], [
        'name' => 'Default UI',
        'sku' => 'DEFAULT-'.str($slug)->upper()->toString(),
        'sku_normalized' => 'DEFAULT-'.str($slug)->upper()->toString(),
        'gtin' => null,
        'mpn' => null,
        'sort_order' => 0,
    ]);
}

/** @param array<string, mixed> $overrides */
function r16UiDirectVariant(
    Company $company,
    Product $product,
    User $actor,
    string $name,
    array $overrides = [],
): ProductVariant {
    $variant = new ProductVariant;
    $variant->forceFill(array_replace([
        'company_id' => $company->id,
        'product_id' => $product->id,
        'name' => $name,
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

test('viewer sees only nested product variants and no mutation controls', function () {
    [$viewer, $company] = r16UiVariantContext(CompanyRole::Viewer);
    $product = r16UiVariantProduct($company, $viewer);
    $visible = r16UiDirectVariant($company, $product, $viewer, 'Visible variant', [
        'sku' => 'VISIBLE-SKU', 'sku_normalized' => 'VISIBLE-SKU', 'gtin' => '4006381333931', 'mpn' => 'VISIBLE-MPN',
    ]);
    $otherProduct = r16UiVariantProduct($company, $viewer, 'other-ui-product');
    r16UiDirectVariant($company, $otherProduct, $viewer, 'Wrong product secret');
    $this->actingAs($viewer);

    $this->get(route('catalog.products.variants.index', $product->uuid))
        ->assertOk()
        ->assertSee('Visible variant')
        ->assertDontSee('Wrong product secret')
        ->assertSee('Default')
        ->assertDontSee('Add variant')
        ->assertDontSee('Set as default')
        ->assertDontSee('Edit');

    $this->get(route('catalog.products.variants.show', [$product->uuid, $visible->uuid]))
        ->assertOk()
        ->assertSee('VISIBLE-SKU')
        ->assertSee('4006381333931')
        ->assertSee('VISIBLE-MPN')
        ->assertDontSee('Edit variant')
        ->assertDontSee('Set as default');
});

test('owner create form preserves input and store uses trusted product and system fields', function () {
    [$owner, $company] = r16UiVariantContext();
    $product = r16UiVariantProduct($company, $owner);
    $foreign = Company::factory()->create();
    $this->actingAs($owner);

    $this->get(route('catalog.products.variants.create', $product->uuid))
        ->assertOk()
        ->assertSee($product->name)
        ->assertSee('Default UI')
        ->assertDontSee('Status', false);

    $this->from(route('catalog.products.variants.create', $product->uuid))
        ->post(route('catalog.products.variants.store', $product->uuid), [
            'name' => 'Remember variant',
            'sku' => str_repeat('A', 101),
            'gtin' => null,
            'mpn' => null,
            'sort_order' => 10,
        ])->assertRedirect(route('catalog.products.variants.create', $product->uuid))
        ->assertSessionHasErrors('sku')
        ->assertSessionHasInput('name', 'Remember variant');

    $response = $this->post(route('catalog.products.variants.store', $product->uuid), [
        'name' => 'Created via UI',
        'sku' => ' ui sku-01 ',
        'gtin' => '4006381333931',
        'mpn' => ' UI MPN 01 ',
        'sort_order' => 20,
        'company_id' => $foreign->id,
        'product_id' => 999999,
        'status' => 'active',
        'is_default' => true,
    ]);
    $variant = ProductVariant::query()->where('sku_normalized', 'UISKU-01')->sole();

    $response->assertRedirect(route('catalog.products.variants.show', [$product->uuid, $variant->uuid]))
        ->assertSessionHas('success', 'Variant created.');
    expect($variant->company_id)->toBe($company->id)
        ->and($variant->product_id)->toBe($product->id)
        ->and($variant->status)->toBe(ProductVariantStatus::Draft)
        ->and($product->fresh()?->default_variant_id)->not->toBe($variant->id);

    $this->get(route('catalog.products.variants.show', [$product->uuid, $variant->uuid]))
        ->assertOk()
        ->assertSee('Variant created.')
        ->assertSee('Created via UI');
});

test('editor edits identifiers and explicitly changes the default through POST', function () {
    [$editor, $company] = r16UiVariantContext(CompanyRole::Editor);
    $product = r16UiVariantProduct($company, $editor);
    $variant = r16UiDirectVariant($company, $product, $editor, 'Editable variant', [
        'sku' => 'OLD-SKU', 'sku_normalized' => 'OLD-SKU', 'gtin' => '4006381333931', 'mpn' => 'OLD-MPN',
    ]);
    $this->actingAs($editor);

    $this->get(route('catalog.products.variants.edit', [$product->uuid, $variant->uuid]))
        ->assertOk()
        ->assertSee('OLD-SKU')
        ->assertSee('4006381333931')
        ->assertSee('OLD-MPN');

    $this->patch(route('catalog.products.variants.update', [$product->uuid, $variant->uuid]), [
        'name' => 'Updated UI variant',
        'sku' => 'NEW-UI-SKU',
        'gtin' => '036000291452',
        'mpn' => 'NEW-UI-MPN',
        'sort_order' => 30,
        'product_id' => 999999,
        'status' => 'archived',
    ])->assertRedirect(route('catalog.products.variants.show', [$product->uuid, $variant->uuid]))
        ->assertSessionHas('success', 'Variant updated.');

    expect($variant->fresh()?->name)->toBe('Updated UI variant')
        ->and($variant->fresh()?->sku)->toBe('NEW-UI-SKU')
        ->and($variant->fresh()?->gtin)->toBe('036000291452')
        ->and($variant->fresh()?->mpn)->toBe('NEW-UI-MPN')
        ->and($variant->fresh()?->product_id)->toBe($product->id)
        ->and($variant->fresh()?->status)->toBe(ProductVariantStatus::Draft);

    $oldDefault = $product->default_variant_id;
    $this->get(route('catalog.products.variants.set-default', [$product->uuid, $variant->uuid]))
        ->assertMethodNotAllowed();
    expect($product->fresh()?->default_variant_id)->toBe($oldDefault);

    $this->post(route('catalog.products.variants.set-default', [$product->uuid, $variant->uuid]))
        ->assertRedirect()
        ->assertSessionHas('success', 'Default variant updated.');
    expect($product->fresh()?->default_variant_id)->toBe($variant->id);
});

test('viewer cannot open or submit variant mutations', function () {
    [$viewer, $company] = r16UiVariantContext(CompanyRole::Viewer);
    $product = r16UiVariantProduct($company, $viewer);
    $variant = r16UiDirectVariant($company, $product, $viewer, 'Protected variant');
    $this->actingAs($viewer);

    $this->get(route('catalog.products.variants.create', $product->uuid))->assertForbidden();
    $this->post(route('catalog.products.variants.store', $product->uuid), [
        'name' => 'Denied', 'sort_order' => 0,
    ])->assertForbidden();
    $this->get(route('catalog.products.variants.edit', [$product->uuid, $variant->uuid]))->assertForbidden();
    $this->patch(route('catalog.products.variants.update', [$product->uuid, $variant->uuid]), [
        'name' => 'Changed', 'sort_order' => 0,
    ])->assertForbidden();
    $this->post(route('catalog.products.variants.set-default', [$product->uuid, $variant->uuid]))->assertForbidden();

    expect($variant->fresh()?->name)->toBe('Protected variant');
});

test('wrong tenant and wrong product UUID combinations are concealed on nested routes', function () {
    [$owner, $company] = r16UiVariantContext();
    $product = r16UiVariantProduct($company, $owner, 'visible-nested-product');
    $otherProduct = r16UiVariantProduct($company, $owner, 'other-nested-product');
    $wrongProductVariant = r16UiDirectVariant($company, $otherProduct, $owner, 'Wrong nested variant');
    $foreignCompany = Company::factory()->create();
    $foreignProduct = r16UiVariantProduct($foreignCompany, $owner, 'foreign-nested-product');
    $foreignVariant = r16UiDirectVariant($foreignCompany, $foreignProduct, $owner, 'Foreign nested variant');
    $payload = ['name' => 'Attempt', 'sort_order' => 0];
    $this->actingAs($owner);

    $this->get(route('catalog.products.variants.index', $foreignProduct->uuid))->assertNotFound();
    $this->get(route('catalog.products.variants.show', [$product->uuid, $wrongProductVariant->uuid]))->assertNotFound();
    $this->get(route('catalog.products.variants.edit', [$product->uuid, $foreignVariant->uuid]))->assertNotFound();
    $this->patch(route('catalog.products.variants.update', [$product->uuid, $wrongProductVariant->uuid]), $payload)->assertNotFound();
    $this->post(route('catalog.products.variants.set-default', [$product->uuid, $wrongProductVariant->uuid]))->assertNotFound();
});

test('variant routes enforce authentication verification company selection and active company', function () {
    [$user, $company, $membership] = r16UiVariantContext();
    $product = r16UiVariantProduct($company, $user);

    $this->get(route('catalog.products.variants.index', $product->uuid))->assertRedirect(route('login'));
    $user->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($user)->get(route('catalog.products.variants.index', $product->uuid))
        ->assertRedirect(route('verification.notice'));

    $user->forceFill(['email_verified_at' => now()])->save();
    $membership->delete();
    $this->actingAs($user)->get(route('catalog.products.variants.index', $product->uuid))
        ->assertRedirect(route('companies.none'));

    CompanyMembership::factory()->owner()->create(['user_id' => $user, 'company_id' => $company]);
    $company->forceFill(['status' => CompanyStatus::Suspended])->save();
    $this->actingAs($user)->get(route('catalog.products.variants.index', $product->uuid))
        ->assertRedirect(route('company.suspended'));
});

test('variant index paginates a bounded ordered product-only query', function () {
    [$owner, $company] = r16UiVariantContext();
    $product = r16UiVariantProduct($company, $owner, 'paginated-variant-product');
    $otherProduct = r16UiVariantProduct($company, $owner, 'hidden-variant-product');
    r16UiDirectVariant($company, $otherProduct, $owner, 'Hidden wrong product row');

    foreach (range(1, 25) as $number) {
        r16UiDirectVariant($company, $product, $owner, sprintf('Variant %02d', $number), [
            'sort_order' => $number,
        ]);
    }

    $this->actingAs($owner)->get(route('catalog.products.variants.index', $product->uuid))
        ->assertOk()
        ->assertDontSee('Hidden wrong product row')
        ->assertViewHas('variants', fn (LengthAwarePaginator $variants): bool => $variants->perPage() === 25 && $variants->total() === 26 && $variants->count() === 25);

    $this->get(route('catalog.products.variants.index', [$product->uuid, 'page' => 2]))
        ->assertOk()
        ->assertViewHas('variants', fn (LengthAwarePaginator $variants): bool => $variants->count() === 1);
});

test('product pages expose bounded variant summaries and management links', function () {
    [$owner, $company] = r16UiVariantContext();
    $product = r16UiVariantProduct($company, $owner, 'product-summary');

    foreach (range(1, 6) as $number) {
        r16UiDirectVariant($company, $product, $owner, "Summary {$number}", ['sort_order' => $number]);
    }

    $this->actingAs($owner)->get(route('catalog.products.show', $product->uuid))
        ->assertOk()
        ->assertSee('7 variants')
        ->assertSee('Manage variants')
        ->assertSee('Add variant')
        ->assertSee('Showing the first 5 variants')
        ->assertViewHas('product', fn (Product $viewProduct): bool => $viewProduct->variants_count === 7 && $viewProduct->variants->count() === 5);

    $this->get(route('catalog.products.index'))
        ->assertOk()
        ->assertSee('7 variants');
});

test('variant route names exist without delete archive restore or GET mutation routes', function () {
    [$owner, $company] = r16UiVariantContext();
    $product = r16UiVariantProduct($company, $owner);
    $variant = r16UiDirectVariant($company, $product, $owner, 'Route variant');
    $this->actingAs($owner);

    expect(route('catalog.products.variants.index', $product->uuid))->toEndWith('/catalog/products/'.$product->uuid.'/variants')
        ->and(route('catalog.products.variants.create', $product->uuid))->toEndWith('/variants/create')
        ->and(route('catalog.products.variants.show', [$product->uuid, $variant->uuid]))->toEndWith('/variants/'.$variant->uuid)
        ->and(route('catalog.products.variants.edit', [$product->uuid, $variant->uuid]))->toEndWith('/variants/'.$variant->uuid.'/edit')
        ->and(route('catalog.products.variants.set-default', [$product->uuid, $variant->uuid]))->toEndWith('/variants/'.$variant->uuid.'/set-default');

    $this->delete(route('catalog.products.variants.show', [$product->uuid, $variant->uuid]))->assertMethodNotAllowed();
    $this->post('/catalog/products/'.$product->uuid.'/variants/'.$variant->uuid.'/archive')->assertNotFound();
    $this->post('/catalog/products/'.$product->uuid.'/variants/'.$variant->uuid.'/restore')->assertNotFound();
});
