<?php

namespace App\Support\Catalog;

use InvalidArgumentException;

class GtinValidator
{
    /** @var list<int> */
    private const SUPPORTED_LENGTHS = [8, 12, 13, 14];

    public function isValid(string $gtin): bool
    {
        try {
            $this->assertValid($gtin);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function assertValid(string $gtin): void
    {
        if ($gtin === '' || preg_match('/^[0-9]+$/D', $gtin) !== 1) {
            throw new InvalidArgumentException('The GTIN must contain only digits.');
        }

        if (! in_array(strlen($gtin), self::SUPPORTED_LENGTHS, true)) {
            throw new InvalidArgumentException('The GTIN must contain 8, 12, 13, or 14 digits.');
        }

        $body = substr($gtin, 0, -1);

        if ($this->calculateCheckDigit($body) !== (int) substr($gtin, -1)) {
            throw new InvalidArgumentException('The GTIN check digit is invalid.');
        }
    }

    public function calculateCheckDigit(string $body): int
    {
        if ($body === '' || preg_match('/^[0-9]+$/D', $body) !== 1) {
            throw new InvalidArgumentException('A GTIN body must contain only digits.');
        }

        $sum = 0;
        $weight = 3;

        for ($index = strlen($body) - 1; $index >= 0; $index--) {
            $sum += ((int) $body[$index]) * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - ($sum % 10)) % 10;
    }
}
