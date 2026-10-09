<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Stringable;

class DisplayText
{
    public static function make(mixed $value, string $fallback = ''): string
    {
        if ($value === null || $value === false || $value === '') {
            return $fallback;
        }

        if (is_array($value)) {
            return self::fromArray($value, $fallback);
        }

        if ($value instanceof Collection) {
            return self::fromArray($value->all(), $fallback);
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        return $fallback;
    }

    private static function fromArray(array $value, string $fallback): string
    {
        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.fallback_locale', 'en');

        foreach ([$locale, $fallbackLocale, 'en'] as $key) {
            if (isset($value[$key]) && (is_scalar($value[$key]) || $value[$key] instanceof Stringable)) {
                return (string) $value[$key];
            }
        }

        foreach ($value as $item) {
            if (is_scalar($item) || $item instanceof Stringable) {
                return (string) $item;
            }

            if (is_array($item)) {
                $text = self::fromArray($item, '');

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return $fallback;
    }
}
