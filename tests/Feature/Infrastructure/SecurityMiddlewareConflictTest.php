<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Config;

test('web route has baseline security headers', function () {
    $response = $this->get('/up');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

test('web route does not have API-specific Cache-Control', function () {
    $response = $this->get('/up');

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->not->toContain('no-store');
});

test('API route has API-specific headers', function () {
    $response = $this->withHeaders([
        'Accept' => 'application/json',
    ])->getJson('/api/v1/health');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('no-store');
});

test('invitation route has invitation-specific headers', function () {
    Config::set('session.driver', 'array');

    $response = $this->get('/invitations/some-uuid?token=test');

    expect($response->status())->toBe(404);
});

test('/up has web baseline headers not API headers', function () {
    $response = $this->get('/up');

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

test('/ready has web baseline headers', function () {
    $response = $this->getJson('/ready');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('HSTS is not sent on HTTP even when enabled', function () {
    Config::set('security.hsts_enabled', true);

    $response = $this->get('/up');

    $response->assertHeaderMissing('Strict-Transport-Security');
});
