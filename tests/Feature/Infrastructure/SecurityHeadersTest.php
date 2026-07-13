<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Config;

test('X-Content-Type-Options header is present on web responses', function () {
    $response = $this->get('/up');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('Referrer-Policy header is present on web responses', function () {
    $response = $this->get('/up');

    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('X-Frame-Options header is present on web responses', function () {
    $response = $this->get('/up');

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

test('security headers are present on ready endpoint', function () {
    $response = $this->getJson('/ready');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy')
        ->assertHeader('X-Frame-Options');
});

test('HSTS is not sent over HTTP', function () {
    Config::set('security.hsts_enabled', true);

    $response = $this->get('/up');

    $response->assertHeaderMissing('Strict-Transport-Security');
});

test('HSTS respects enabled flag', function () {
    Config::set('security.hsts_enabled', false);

    $response = $this->get('/up');

    $response->assertHeaderMissing('Strict-Transport-Security');
});
