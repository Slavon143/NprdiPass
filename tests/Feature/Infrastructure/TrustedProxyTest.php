<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

test('TRUSTED_PROXIES is not * by default', function () {
    $proxies = (string) env('TRUSTED_PROXIES', '');

    expect($proxies)->not->toBe('*');
});

test('empty TRUSTED_PROXIES does not trust any forwarder', function () {
    Config::set('security.trusted_proxies', '');

    $request = Request::create('/up', 'GET', [], [], [], [
        'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        'REMOTE_ADDR' => '127.0.0.1',
    ]);

    expect($request->ip())->toBe('127.0.0.1');
});

test('TRUSTED_PROXIES can be a single IP as string', function () {
    Config::set('security.trusted_proxies', '10.0.0.1');

    expect(Config::get('security.trusted_proxies'))->toBe('10.0.0.1');
});

test('TRUSTED_PROXIES can be comma-separated CIDR', function () {
    Config::set('security.trusted_proxies', '10.0.0.0/8,172.16.0.0/12');

    $proxies = Config::get('security.trusted_proxies');
    expect($proxies)->toContain('10.0.0.0/8')
        ->and($proxies)->toContain('172.16.0.0/12');
});
