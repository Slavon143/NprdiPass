<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('public demo views need no account and keep their selected language', function (string $view) {
    $this->get(route('demo', ['view' => $view, 'lang' => 'sv']))->assertOk()
        ->assertSee('<html lang="sv">', false)
        ->assertSee('Fiktiva produkter')
        ->assertSee('data-demo-view="'.$view.'"', false)
        ->assertSee(route('demo', ['lang' => 'sv', 'view' => 'passport']))
        ->assertDontSee('settings/integrations/fortnox/connect')
        ->assertDontSee('settings/billing/checkout')
        ->assertDontSee('type="file"', false);
})->with(['dashboard', 'catalogue', 'product', 'readiness', 'import', 'passport']);

test('the showcase does not query tenant records or providers even for a signed in visitor', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create(['name' => 'Private Customer Name']);
    $company = Company::factory()->create(['name' => 'Private Customer Company']);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($user)->get(route('demo', ['company_id' => $company->id, 'tenant_id' => '1853390']))
        ->assertOk()->assertDontSee('Private Customer Name')->assertDontSee('Private Customer Company');
    $this->get('/')->assertOk()->assertDontSee('Private Customer Name')->assertDontSee('Private Customer Company');

    expect($queries)->toBe([]);
    Http::assertNothingSent();
});

test('demo has no server mutation endpoint and never creates a login session cookie', function () {
    $response = $this->get(route('demo'))->assertOk();
    expect($response->headers->getCookies())->toBe([]);
    $this->post(route('demo'), ['action' => 'publish', 'subscription' => '1853390'])->assertStatus(405);
});

test('unknown demo views and locale shapes are rejected', function () {
    foreach (['?view=checkout', '?view[]=passport', '?lang=production', '?lang[]=sv'] as $query) {
        $this->get('/demo'.$query)->assertNotFound();
    }
});

test('demo and landing retain enforced CSP without unsafe script execution', function () {
    config()->set('security.csp_enabled', true);
    config()->set('security.csp_report_only', false);
    $response = $this->get('/demo')->assertOk();
    expect($response->headers->get('Content-Security-Policy'))->toContain("script-src 'self' 'nonce-")
        ->not->toContain('unsafe-eval');
    $response->assertSee('id="showcase-copy" nonce="', false);
});
