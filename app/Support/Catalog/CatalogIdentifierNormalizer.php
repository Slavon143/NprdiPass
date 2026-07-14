<?php

namespace App\Support\Catalog;

use Illuminate\Support\Str;
use InvalidArgumentException;

class CatalogIdentifierNormalizer
{
    public function normalizeProductSlug(string $value): string
    {
        return $this->slug($value);
    }

    public function normalizeCategorySlug(string $value): string
    {
        return $this->slug($value);
    }

    public function normalizeSku(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^[\pL\pN._\-\s]+$/u', $value) !== 1) {
            throw new InvalidArgumentException('SKU contains unsupported characters.');
        }

        $normalized = mb_strtoupper((string) preg_replace('/\s+/u', '', $value));

        return $this->within($normalized, 100, 'SKU');
    }

    public function normalizeGtin(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = trim($value);

        (new GtinValidator)->assertValid($normalized);

        return $normalized;
    }

    public function normalizeMpn(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->within(trim($value), 100, 'MPN');
    }

    public function normalizeAttributeCode(string $value): string
    {
        return $this->code($value, 'Attribute code');
    }

    public function normalizeOptionCode(string $value): string
    {
        return $this->code($value, 'Attribute option code');
    }

    private function slug(string $value): string
    {
        return $this->within(Str::slug(trim($value)), 255, 'Slug');
    }

    private function code(string $value, string $label): string
    {
        return $this->within(Str::slug(trim($value), '_'), 100, $label);
    }

    private function within(string $value, int $maximum, string $label): string
    {
        if (mb_strlen($value) > $maximum) {
            throw new InvalidArgumentException("{$label} exceeds {$maximum} characters.");
        }

        return $value;
    }
}
