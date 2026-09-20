<?php

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('CSP header is omitted while disabled', function () {
    config(['security.csp_enabled' => false]);

    $this->get('/login')->assertOk()->assertHeaderMissing('Content-Security-Policy');
});

test('CSP header is enforced with strict directives when enabled', function () {
    config(['security.csp_enabled' => true]);

    $response = $this->get('/login');

    $response->assertOk()->assertHeader('Content-Security-Policy');

    $header = (string) $response->headers->get('Content-Security-Policy');

    expect($header)->toContain("default-src 'self'")
        ->and($header)->toContain("script-src 'self' 'nonce-")
        ->and($header)->toContain("style-src 'self' 'unsafe-inline'")
        ->and($header)->toContain("object-src 'none'")
        ->and($header)->toContain("frame-ancestors 'self'")
        ->and($header)->toContain("base-uri 'self'")
        ->and($header)->toContain("form-action 'self'");
});

test('CSP report-only mode emits the report-only header', function () {
    config(['security.csp_enabled' => true, 'security.csp_report_only' => true]);

    $response = $this->get('/login');

    $response->assertHeader('Content-Security-Policy-Report-Only')
        ->assertHeaderMissing('Content-Security-Policy');
});

test('inline scripts render with a nonce matching the policy', function () {
    config(['security.csp_enabled' => true]);

    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);

    $response = $this->actingAs($user)->get(route('catalog.products.index'));

    $response->assertOk();

    $header = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", $header, $matches);

    expect($matches)->toHaveCount(2)
        ->and($response->getContent())->toContain('nonce="'.$matches[1].'"');
});

test('security baseline headers are present on every response', function () {
    $response = $this->get('/login');

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});
