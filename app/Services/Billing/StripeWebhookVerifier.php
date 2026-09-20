<?php

namespace App\Services\Billing;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StripeWebhookVerifier
{
    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function verifiedPayload(Request $request): array
    {
        $secret = (string) config('billing.stripe_webhook_secret');
        if ($secret === '') {
            throw ValidationException::withMessages(['signature' => 'Stripe webhook secret is not configured.']);
        }

        $payload = $request->getContent();
        $header = (string) $request->headers->get('Stripe-Signature', '');
        $timestamp = $this->signaturePart($header, 't');
        $signature = $this->signaturePart($header, 'v1');

        if ($timestamp === null || $signature === null || ! ctype_digit($timestamp)) {
            throw ValidationException::withMessages(['signature' => 'Stripe webhook signature is malformed.']);
        }

        $age = abs(time() - (int) $timestamp);
        if ($age > (int) config('billing.stripe_webhook_tolerance_seconds', 300)) {
            throw ValidationException::withMessages(['signature' => 'Stripe webhook signature timestamp is outside tolerance.']);
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        if (! hash_equals($expected, $signature)) {
            throw ValidationException::withMessages(['signature' => 'Stripe webhook signature is invalid.']);
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['payload' => 'Stripe webhook payload is unreadable.']);
        }

        return $decoded;
    }

    private function signaturePart(string $header, string $key): ?string
    {
        foreach (explode(',', $header) as $part) {
            [$candidate, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($candidate === $key && is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
