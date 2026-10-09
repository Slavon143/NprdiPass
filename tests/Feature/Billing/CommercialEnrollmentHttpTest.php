<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Companies\CreateCompanyForOwner;
use App\Enums\ApiTokenAbility;
use App\Enums\CompanyRole;
use App\Models\Catalog\Product;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Catalog\CatalogProductCapacity;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['billing.commercial_enabled' => true, 'billing.plans' => config('billing.commercial_plans')]);
    Http::preventStrayRequests();
    Queue::fake();
    $this->actor = User::factory()->create(['email_verified_at' => now()]);
    $this->company = app(CreateCompanyForOwner::class)->execute($this->actor, 'Enrollment HTTP');
    $this->actingAs($this->actor);
    app(CurrentCompany::class)->set($this->company);
    $this->product = app(CreateProductAction::class)->execute($this->actor, $this->company, ['name' => 'Imported source'], imported: true);
});

test('web enrollment is explicit idempotent slot admission with a working product control', function () {
    $url = route('catalog.products.managed', $this->product->uuid);
    $this->get(route('catalog.products.show', $this->product->uuid))->assertOk()->assertSee('Enroll in DPP');
    $this->patch($url, ['managed' => 1])->assertRedirect(route('catalog.products.show', $this->product->uuid));
    $this->patch($url, ['managed' => 1])->assertRedirect();
    expect($this->product->fresh()->managed)->toBeTrue()->and(app(CatalogProductCapacity::class)->count($this->company))->toBe(1);
    $this->patch($url, ['managed' => 0])->assertRedirect();
    expect($this->product->fresh()->managed)->toBeFalse()->and(app(CatalogProductCapacity::class)->count($this->company))->toBe(0);
});

test('an imported product offers enrollment before passport creation and stale submits return guidance', function () {
    $store = route('catalog.products.passport.store', $this->product->uuid);
    $response = $this->get(route('catalog.products.show', $this->product->uuid))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//form[@action="'.$store.'"]')->length)->toBe(0);
    $response->assertSee('Enroll this product in DPP before creating its Product Passport.');

    $this->postJson($store)->assertConflict();
    $this->post($store)
        ->assertRedirect(route('catalog.products.show', ['product' => $this->product->uuid, 'tab' => 'passport']))
        ->assertSessionHasErrors('passport');
    expect($this->product->fresh()->managed)->toBeFalse()
        ->and(ProductPassport::query()->forCompany($this->company)->count())->toBe(0)
        ->and(app(CatalogProductCapacity::class)->count($this->company))->toBe(0);

    $this->patch(route('catalog.products.managed', $this->product->uuid), ['managed' => true])->assertRedirect();
    $this->get(route('catalog.products.show', $this->product->uuid))->assertOk()->assertSee('Create Product Passport');
    $this->post($store)->assertRedirect(route('catalog.products.passport.edit', $this->product->uuid));
    $this->post($store)->assertRedirect(route('catalog.products.passport.edit', $this->product->uuid));
    expect(ProductPassport::query()->forCompany($this->company)->count())->toBe(1)
        ->and(ProductPassportVersion::query()->forCompany($this->company)->count())->toBe(1)
        ->and(app(CatalogProductCapacity::class)->count($this->company))->toBe(1);
});

test('API enrollment rejects foreign UUIDs and ignores submitted tenant and product fields', function () {
    $foreign = app(CreateCompanyForOwner::class)->execute(User::factory()->create(), 'Foreign company');
    $foreignProduct = new Product;
    $foreignProduct->forceFill(['company_id' => $foreign->id, 'name' => 'Foreign', 'slug' => 'foreign', 'slug_normalized' => 'foreign', 'status' => 'draft'])->save();
    $token = issueCompanyApiToken($this->actor, $this->company, [ApiTokenAbility::CatalogWrite->value]);
    $this->withToken($token->plainTextToken)->patchJson('/api/v1/catalog/products/'.$foreignProduct->uuid.'/managed', ['managed' => false])->assertNotFound();
    $this->withToken($token->plainTextToken)->patchJson('/api/v1/catalog/products/'.$this->product->uuid.'/managed', [
        'managed' => true, 'company_id' => $foreign->id, 'name' => 'Injected name',
    ])->assertOk()->assertJsonPath('data.managed', true)->assertJsonPath('data.name', 'Imported source')->assertHeader('X-Request-ID');
    expect($this->product->fresh()->company_id)->toBe($this->company->id);
});

test('enrollment denies read-only viewers expired trials and missing managed quota', function () {
    $subscription = $this->company->subscription()->firstOrFail();
    $url = route('catalog.products.managed', $this->product->uuid);
    $entitlements = $subscription->entitlements;
    unset($entitlements['catalog.products.max']);
    $subscription->update(['entitlements' => $entitlements]);
    $this->patch($url, ['managed' => true])->assertSessionHasErrors('entitlement');
    $subscription->update(['trial_ends_at' => now()->subSecond()]);
    $this->patch($url, ['managed' => true])->assertSessionHasErrors();
    CompanyMembership::query()->where('company_id', $this->company->id)->where('user_id', $this->actor->id)
        ->update(['role' => CompanyRole::Viewer->value]);
    $this->patch($url, ['managed' => true])->assertForbidden();
    expect($this->product->fresh()->managed)->toBeFalse();
});

test('a full managed quota rejects enrollment without changing the imported source', function () {
    app(CreateProductAction::class)->execute($this->actor, $this->company, ['name' => 'Existing managed']);
    $subscription = $this->company->subscription()->firstOrFail();
    $entitlements = $subscription->entitlements;
    $entitlements['catalog.products.max'] = 1;
    $subscription->update(['entitlements' => $entitlements]);
    $token = issueCompanyApiToken($this->actor, $this->company, [ApiTokenAbility::CatalogWrite->value]);
    $this->withToken($token->plainTextToken)->patchJson('/api/v1/catalog/products/'.$this->product->uuid.'/managed', ['managed' => true])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation_error');
    expect($this->product->fresh()->managed)->toBeFalse()->and(app(CatalogProductCapacity::class)->count($this->company))->toBe(1);
});
