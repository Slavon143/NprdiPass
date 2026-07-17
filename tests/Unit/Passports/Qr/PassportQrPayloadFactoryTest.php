<?php

use App\Services\Passports\Qr\PassportQrPayloadFactory;

test('payload contains canonical public url', function (): void {
    $factory = new PassportQrPayloadFactory;

    $payload = $factory->create('0198abc123');
    $baseUrl = rtrim(config('passports.public_base_url'), '/');

    expect($payload)->toBe("{$baseUrl}/p/0198abc123");
});

test('payload uses configured base url', function (): void {
    config()->set('passports.public_base_url', 'https://custom.test');

    $factory = new PassportQrPayloadFactory;
    $payload = $factory->create('public-uuid');

    expect($payload)->toBe('https://custom.test/p/public-uuid');
});

test('payload strips trailing slash from base url', function (): void {
    config()->set('passports.public_base_url', 'https://example.test/');

    $factory = new PassportQrPayloadFactory;
    $payload = $factory->create('abc');

    expect($payload)->toBe('https://example.test/p/abc');
});

test('payload contains no version uuid', function (): void {
    $factory = new PassportQrPayloadFactory;
    $payload = $factory->create('0198abc123');

    expect($payload)->not()->toContain('version');
});

test('payload contains no product uuid', function (): void {
    $factory = new PassportQrPayloadFactory;
    $payload = $factory->create('0198abc123');
    $baseUrl = rtrim(config('passports.public_base_url'), '/');

    expect($payload)->toBe("{$baseUrl}/p/0198abc123");
    expect($payload)->not()->toContain('product');
});

test('payload contains no numeric id', function (): void {
    $factory = new PassportQrPayloadFactory;
    $payload = $factory->create('0198abc123');

    expect($payload)->not()->toMatch('/\d{10,}/');
});

test('same public id produces deterministic payload', function (): void {
    $factory = new PassportQrPayloadFactory;

    $payload1 = $factory->create('same-id');
    $payload2 = $factory->create('same-id');

    expect($payload1)->toBe($payload2);
});

test('different public ids produce different payloads', function (): void {
    $factory = new PassportQrPayloadFactory;

    expect($factory->create('id-1'))->not()->toBe($factory->create('id-2'));
});
