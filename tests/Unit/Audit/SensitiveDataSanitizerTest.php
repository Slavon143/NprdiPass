<?php

use App\Audit\SensitiveDataSanitizer;

test('OAuth tokens are recursively removed from audit properties', function () {
    $result = (new SensitiveDataSanitizer)->sanitize([
        'safe' => 'retained',
        'connection' => ['access_token' => 'access-secret', 'refresh_token' => 'refresh-secret', 'token_payload' => ['secret' => 'nested-secret']],
    ]);
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('access-secret', 'refresh-secret', 'nested-secret')
        ->and($result['safe'])->toBe('retained');
});

test('sensitive data sanitizer removes nested secrets and redacts secret urls', function () {
    $knownToken = 'known-secret-token';
    $properties = (new SensitiveDataSanitizer)->sanitize([
        'email' => 'member@example.com',
        'password' => 'not-allowed',
        'nested' => [
            'token_hash' => hash('sha256', $knownToken),
            'safe' => 'value',
        ],
        'url' => "https://example.test/accept?token={$knownToken}&safe=yes",
        'header' => "Bearer {$knownToken}",
    ]);

    $serialized = json_encode($properties, JSON_THROW_ON_ERROR);

    expect($properties)->not->toHaveKey('password')
        ->and($properties['nested'])->not->toHaveKey('token_hash')
        ->and($properties['nested']['safe'])->toBe('value')
        ->and($serialized)->not->toContain($knownToken)
        ->and($serialized)->toContain('[REDACTED]');
});
