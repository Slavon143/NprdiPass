<?php

namespace App\Support;

use Carbon\CarbonInterface;

class LocalizedDisplay
{
    public static function integer(int $value, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return number_format($value, 0, '.', match ($locale) {
            'de' => '.', 'sv' => "\u{00A0}", default => ',',
        });
    }

    /** Human display only: persisted/API/import values remain canonical decimals. */
    public static function decimal(string $value, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        if (preg_match('/^-?\d+(?:\.\d+)?$/D', $value) !== 1) {
            return $value;
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return in_array($locale, ['sv', 'de'], true) ? str_replace('.', ',', $value) : $value;
    }

    public static function date(CarbonInterface $value, ?string $locale = null): string
    {
        return $value->format(match ($locale ?? app()->getLocale()) {
            'de' => 'd.m.Y', 'sv' => 'Y-m-d', default => 'd M Y',
        });
    }
}
