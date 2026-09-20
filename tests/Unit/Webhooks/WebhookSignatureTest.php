<?php

use App\Services\Webhooks\WebhookSignature;

test('webhook signatures validate hmac and reject replayed timestamps', function () {
    config(['webhooks.signature_tolerance_seconds' => 300]);

    $signer = new WebhookSignature;
    $payload = '{"id":"evt_test","type":"passport.published"}';
    $secret = 'signature-secret-value';
    $timestamp = 1_788_000_000;
    $header = $signer->sign($payload, $secret, $timestamp);

    expect($signer->verify($payload, $secret, $header, $timestamp + 60))->toBeTrue()
        ->and($signer->verify($payload, $secret, $header, $timestamp + 301))->toBeFalse()
        ->and($signer->verify('{"id":"evt_other"}', $secret, $header, $timestamp + 60))->toBeFalse()
        ->and($signer->verify($payload, 'wrong-secret', $header, $timestamp + 60))->toBeFalse();
});
