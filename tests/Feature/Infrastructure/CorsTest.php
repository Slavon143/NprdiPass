<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Config;

test('allowed API origin receives CORS headers', function () {
    Config::set('api.allowed_origins', ['http://localhost:3000']);

    $response = $this->withHeaders([
        'Origin' => 'http://localhost:3000',
    ])->getJson('/api/v1/health');

    $response->assertStatus(200)
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
});

test('unknown origin does not receive CORS access', function () {
    Config::set('api.allowed_origins', ['http://localhost:3000']);

    $response = $this->withHeaders([
        'Origin' => 'https://evil.com',
    ])->getJson('/api/v1/health');

    $response->assertHeaderMissing('Access-Control-Allow-Origin');
});

test('OPTIONS preflight works for allowed origins', function () {
    Config::set('api.allowed_origins', ['http://localhost:3000']);

    $response = $this->withHeaders([
        'Origin' => 'http://localhost:3000',
        'Access-Control-Request-Method' => 'GET',
    ])->options('/api/v1/health');

    $response->assertStatus(204)
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
        ->assertHeader('Access-Control-Allow-Methods');
});

test('CORS applies only to API paths', function () {
    Config::set('api.allowed_origins', ['http://localhost:3000']);

    $response = $this->withHeaders([
        'Origin' => 'http://localhost:3000',
    ])->get('/up');

    $response->assertHeaderMissing('Access-Control-Allow-Origin');
});

test('CORS credentials are disabled for bearer token API', function () {
    expect(Config::get('cors.supports_credentials'))->toBeFalse();
});

test('CORS is restricted to configured paths', function () {
    expect(Config::get('cors.paths'))->toBe(['api/*']);
});
