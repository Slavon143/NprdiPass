<?php

use App\Security\InvitationTokenGenerator;

test('invitation tokens are cryptographically strong url safe values', function () {
    $token = (new InvitationTokenGenerator)->generate();

    expect(strlen($token->plainText()))->toBeGreaterThanOrEqual(64)
        ->and($token->plainText())->toMatch('/\A[A-Za-z0-9_-]+\z/')
        ->and($token->hash())->toBe(hash('sha256', $token->plainText()))
        ->and(strlen($token->hash()))->toBe(64);
});

test('invitation token generation produces distinct values', function () {
    $generator = new InvitationTokenGenerator;
    $first = $generator->generate();
    $second = $generator->generate();

    expect($first->plainText())->not->toBe($second->plainText())
        ->and($first->hash())->not->toBe($second->hash());
});

test('invitation token value object does not expose secrets through json or debug output', function () {
    $token = (new InvitationTokenGenerator)->generate();

    expect(json_encode($token))->toBe('{}')
        ->and(print_r($token, true))->not->toContain($token->plainText());
});
