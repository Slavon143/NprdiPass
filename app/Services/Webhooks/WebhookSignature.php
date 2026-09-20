<?php

namespace App\Services\Webhooks;

use Illuminate\Support\Str;

class WebhookSignature
{
    public function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return "t={$timestamp},v1={$signature}";
    }

    public function verify(string $payload, string $secret, string $header, ?int $now = null): bool
    {
        $parts = $this->parseHeader($header);
        $timestamp = isset($parts['t']) ? (int) $parts['t'] : 0;
        $signature = $parts['v1'] ?? null;

        if ($timestamp <= 0 || ! is_string($signature) || $signature === '') {
            return false;
        }

        $tolerance = max(1, (int) config('webhooks.signature_tolerance_seconds', 300));

        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }

        $expected = $this->sign($payload, $secret, $timestamp);

        return hash_equals($this->parseHeader($expected)['v1'] ?? Str::random(64), $signature);
    }

    /**
     * @return array<string, string>
     */
    private function parseHeader(string $header): array
    {
        $parts = [];

        foreach (explode(',', $header) as $segment) {
            [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, null);

            if (is_string($key) && is_string($value) && $key !== '') {
                $parts[$key] = $value;
            }
        }

        return $parts;
    }
}
