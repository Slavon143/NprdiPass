<?php

use App\Actions\Catalog\Products\ProductAggregateCreator;
use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Passports\Readiness\PassportReadinessDisplay;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\Http;

function productWorkspaceContext(CompanyRole $role = CompanyRole::Owner): array
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create(['name' => 'Workspace Company']);
    CompanyMembership::factory()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'role' => $role]);
    $product = app(ProductAggregateCreator::class)->create($actor, $company, [
        'name' => 'Real workspace article',
        'slug' => 'real-workspace-article',
        'short_description' => 'Actual catalogue description',
        'manufacturer' => 'Actual Manufacturer',
        'brand' => 'Actual Brand',
    ], ['name' => 'Default', 'sku' => 'REAL-001', 'sku_normalized' => 'real-001', 'gtin' => '07350012345679', 'sort_order' => 0]);

    app(CurrentCompany::class)->set($company);

    return [$actor, $company, $product];
}

test('real product opens the prototype workspace with actual catalogue data and no fictitious readiness', function () {
    Http::preventStrayRequests();
    [$actor, $company, $product] = productWorkspaceContext();
    $response = $this->actingAs($actor)->get(route('catalog.products.show', $product->uuid))->assertOk();
    $response->assertSee('PRODUCT WORKSPACE')->assertSee('Product overview')
        ->assertSee('Real workspace article')->assertSee('REAL-001')->assertSee('Actual Manufacturer')
        ->assertSee('Workspace Company')->assertSee('Model / SKU')->assertSee('Materials')
        ->assertSee('Data source')->assertSee('No Product Passport has been created')
        ->assertDontSee('Industrial Storage Case')->assertDontSee('GuardTec AB')->assertDontSee('89%');

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $grid = $xpath->query('//*[@data-testid="product-workspace-overview"]')->item(0);
    expect($xpath->query('./section', $grid)->length)->toBe(1)
        ->and($xpath->query('./aside[@data-testid="product-workspace-readiness"]', $grid)->length)->toBe(1)
        ->and($xpath->query('//*[@id="readiness"]/@open')->length)->toBe(0);
    expect(trim($xpath->query('//*[contains(@class,"workspace-title")]//p[@class="muted"]')->item(0)->textContent))->toBe('REAL-001 · Actual Manufacturer');
    Http::assertNothingSent();
});

test('workspace labels stored readiness without claiming current publication readiness', function () {
    [$actor, $company, $product] = productWorkspaceContext();
    app(CreateProductPassportDraftAction::class)->handle($actor, $company, $product);
    $stored = app(PassportReadinessDisplay::class)->result($company, $product->refresh());
    $this->mock(PassportReadinessDisplay::class, function ($mock) use ($stored): void {
        $mock->shouldReceive('result')->once()->andReturn($stored);
        $mock->shouldReceive('canEvaluate')->once()->andReturn(false);
    });

    $this->actingAs($actor)->get(route('catalog.products.show', $product->uuid))->assertOk()
        ->assertSee('Stored readiness evaluation')->assertSee('Recalculate readiness before making a publication decision.')
        ->assertDontSee('Ready for publication. Review the current draft before publishing.');
});

test('workspace draft metadata and passport facts come from the current company draft', function () {
    [$actor, $company, $product] = productWorkspaceContext();
    $passport = app(CreateProductPassportDraftAction::class)->handle($actor, $company, $product);
    $draft = $passport->currentDraftVersion;
    $payload = $draft->payload;
    $payload['data']['origin_and_traceability']['manufacturing_countries'] = ['SE'];
    $payload['data']['materials_and_composition']['materials'] = [['name' => 'Actual steel']];
    $draft->forceFill(['payload' => $payload, 'draft_revision' => 7])->save();

    $this->actingAs($actor)->get(route('catalog.products.show', $product->uuid))->assertOk()
        ->assertSee('Draft revision 7')->assertSee('Actual steel')->assertSee('Preview passport')
        ->assertSee(route('catalog.products.passport.preview', $product->uuid), false)
        ->assertSee(route('catalog.products.passport.readiness', $product->uuid), false);
});

test('workspace preserves viewer permissions and rejects a foreign product', function () {
    Http::preventStrayRequests();
    [$viewer, $company, $product] = productWorkspaceContext(CompanyRole::Viewer);
    $this->actingAs($viewer)->get(route('catalog.products.show', $product->uuid))->assertOk()
        ->assertSee('PRODUCT WORKSPACE')->assertDontSee('Edit product')->assertDontSee('Import data')
        ->assertDontSee(route('catalog.products.edit', $product->uuid), false);

    $foreign = Product::query()->forceCreate([
        'company_id' => Company::factory()->create()->id,
        'name' => 'Foreign product',
        'slug' => 'foreign-product',
        'slug_normalized' => 'foreign-product',
        'status' => ProductStatus::Draft,
        'created_by' => $viewer->id,
        'managed' => true,
    ]);
    $this->get(route('catalog.products.show', ['product' => $foreign->uuid, 'company_id' => $foreign->company_id]))->assertNotFound();
    Http::assertNothingSent();
});
