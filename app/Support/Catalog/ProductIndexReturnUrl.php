<?php

namespace App\Support\Catalog;

final class ProductIndexReturnUrl
{
    public function resolve(mixed $candidate): string
    {
        $fallback = route('catalog.products.index');

        if (! is_string($candidate) || $candidate === '') {
            return $fallback;
        }

        $expectedParts = parse_url($fallback);
        $candidateParts = parse_url($candidate);

        if (! is_array($expectedParts) || ! is_array($candidateParts)) {
            return $fallback;
        }

        if (isset($candidateParts['user'], $candidateParts['pass']) || isset($candidateParts['fragment'])) {
            return $fallback;
        }

        if (($candidateParts['path'] ?? null) !== ($expectedParts['path'] ?? null)) {
            return $fallback;
        }

        foreach (['scheme', 'host', 'port'] as $part) {
            if (array_key_exists($part, $candidateParts)
                && ($candidateParts[$part] !== ($expectedParts[$part] ?? null))) {
                return $fallback;
            }
        }

        return $candidate;
    }
}
