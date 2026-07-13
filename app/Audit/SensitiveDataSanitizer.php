<?php

namespace App\Audit;

class SensitiveDataSanitizer
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'token_hash',
        'plain_text_token',
        'api_token',
        'authorization',
        'cookie',
        'session',
        'remember_token',
        'secret',
        'client_secret',
        'webhook_secret',
    ];

    /**
     * @param  array<string|int, mixed>  $properties
     * @return array<string|int, mixed>
     */
    public function sanitize(array $properties): array
    {
        $sanitized = [];

        foreach ($properties as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }

            $sanitized[$key] = is_array($value)
                ? $this->sanitize($value)
                : $this->sanitizeValue($value);
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $key));

        return in_array(trim($normalized, '_'), self::SENSITIVE_KEYS, true);
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = (string) preg_replace(
            '/([?&](?:token|token_hash|api_token|secret|authorization)=)[^&\s]+/i',
            '$1[REDACTED]',
            $value,
        );

        return (string) preg_replace('/\bBearer\s+\S+/i', 'Bearer [REDACTED]', $value);
    }
}
