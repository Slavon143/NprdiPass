<?php

use App\Audit\SensitiveDataSanitizer;

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
