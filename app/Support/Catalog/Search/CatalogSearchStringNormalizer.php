<?php

namespace App\Support\Catalog\Search;

class CatalogSearchStringNormalizer
{
    public const MAX_LENGTH = 200;

    public const MIN_GENERIC_LENGTH = 2;

    public function normalize(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $normalized = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_substr($normalized, 0, self::MAX_LENGTH);
    }

    public function isGenericSearchable(string $query): bool
    {
        return mb_strlen($query) >= self::MIN_GENERIC_LENGTH;
    }

    public function escapeLike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value,
        );
    }
}
