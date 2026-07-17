<?php

namespace App\Data\Passports\Localization;

readonly class PassportLocale
{
    public function __construct(
        public string $code,
        public string $label,
        public string $nativeLabel,
        public string $htmlLang,
        public string $dateLocale,
        public string $direction,
    ) {}
}
