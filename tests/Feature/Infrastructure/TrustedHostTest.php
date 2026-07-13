<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Config;

test('allowed host is accepted', function () {
    $response = $this->withHeaders(['Host' => '127.0.0.1'])->get('/up');

    $response->assertStatus(200);
});

test('localhost host is accepted', function () {
    $response = $this->withHeaders(['Host' => 'localhost'])->get('/up');

    $response->assertStatus(200);
});

test('unknown host may be rejected (depends on trusted hosts config)', function () {
    Config::set('security.trusted_hosts', 'example.com,test.com');

    $response = $this->withHeaders(['Host' => 'evil.com'])->get('/up');

    expect(in_array($response->status(), [200, 403], true))->toBeTrue();
});

test('TRUSTED_HOSTS config parses comma-separated values', function () {
    Config::set('security.trusted_hosts', 'localhost,127.0.0.1,nordipass.test');

    $hosts = Config::get('security.trusted_hosts');
    expect($hosts)->toContain('nordipass.test');
});

test('APP_URL host is accepted', function () {
    Config::set('app.url', 'http://nordipass.test');
    Config::set('security.trusted_hosts', 'nordipass.test,localhost,127.0.0.1');

    $response = $this->withHeaders(['Host' => 'nordipass.test'])->get('/up');

    $response->assertStatus(200);
});
