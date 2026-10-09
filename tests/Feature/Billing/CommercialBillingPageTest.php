<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Companies\CreateCompanyForOwner;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['billing.commercial_enabled' => true, 'billing.plans' => config('billing.commercial_plans')]);
    Http::preventStrayRequests();
    Queue::fake();
    $this->actor = User::factory()->create(['email_verified_at' => now()]);
    $this->company = app(CreateCompanyForOwner::class)->execute($this->actor, 'Commercial billing page');
    $this->actingAs($this->actor);
    app(CurrentCompany::class)->set($this->company);
});

test('billing displays real monthly annual prices and offers checkout for the trial plan itself', function () {
    $response = $this->get(route('settings.billing.index'))->assertOk()
        ->assertSee('€49')->assertSee('€490')->assertSee('€149')->assertSee('€1490')
        ->assertSee('€399')->assertSee('€3990')->assertSee('Contact sales to arrange an Enterprise contract.')
        ->assertDontSee('Pilot')->assertDontSee('name="plan" value="enterprise"', false)
        ->assertSee('Seats, including owner')->assertSee('2 GB')->assertSee('No automatic overage charges.');
    $html = $response->getContent();
    expect(substr_count($html, 'name="interval"'))->toBe(3)
        ->and(substr_count($html, '<option value="year">'))->toBe(3)
        ->and($html)->toContain('name="plan" value="growth"');
    expect($this->company->subscription()->firstOrFail()->trial_ends_at->isFuture())->toBeTrue();
});

test('expired trial is described as read only without hiding retained quota and purchase options', function () {
    $this->company->subscription()->firstOrFail()->update(['trial_ends_at' => now()->subSecond()]);
    $this->get(route('settings.billing.index'))->assertOk()
        ->assertSee('Your workspace is read-only.')
        ->assertSee('Existing products, documents and published history are retained.')
        ->assertSee('name="interval"', false);
});

test('managed capacity warning starts at ninety percent and does not promise overage', function () {
    $subscription = $this->company->subscription()->firstOrFail();
    $entitlements = $subscription->entitlements;
    $entitlements['catalog.products.max'] = 10;
    $subscription->update(['entitlements' => $entitlements]);
    for ($index = 0; $index < 8; $index++) {
        app(CreateProductAction::class)->execute($this->actor, $this->company, ['name' => 'Managed '.$index]);
    }
    $this->get(route('settings.billing.index'))->assertOk()->assertDontSee('Managed product capacity is almost full.');
    app(CreateProductAction::class)->execute($this->actor, $this->company, ['name' => 'Ninth managed']);
    $this->get(route('settings.billing.index'))->assertOk()->assertSee('Managed product capacity is almost full.');
});
