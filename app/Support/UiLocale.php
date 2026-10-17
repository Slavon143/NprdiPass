<?php

namespace App\Support;

class UiLocale
{
    public const LABELS = ['en' => 'English', 'sv' => 'Svenska', 'de' => 'Deutsch'];

    public static function supports(mixed $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, self::LABELS);
    }
}
