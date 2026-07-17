<?php

namespace App\Services\Passports\Localization;

use App\Data\Passports\Localization\PassportLocale;

class PassportLocaleRegistry
{
    /** @var array<string, PassportLocale>|null */
    private ?array $locales = null;

    /** @return array<string, PassportLocale> */
    public function all(): array
    {
        if ($this->locales !== null) {
            return $this->locales;
        }

        $this->locales = [
            'en' => new PassportLocale(
                code: 'en',
                label: 'English',
                nativeLabel: 'English',
                htmlLang: 'en',
                dateLocale: 'en_GB',
                direction: 'ltr',
            ),
            'sv' => new PassportLocale(
                code: 'sv',
                label: 'Swedish',
                nativeLabel: 'Svenska',
                htmlLang: 'sv',
                dateLocale: 'sv_SE',
                direction: 'ltr',
            ),
        ];

        return $this->locales;
    }

    public function get(string $code): ?PassportLocale
    {
        return $this->all()[$code] ?? null;
    }

    public function supports(string $code): bool
    {
        return $this->get($code) !== null;
    }

    /** @return string[] */
    public function codes(): array
    {
        return array_keys($this->all());
    }

    /** @return string[] */
    public function htmlLangValues(): array
    {
        return array_map(fn (PassportLocale $l) => $l->htmlLang, $this->all());
    }

    public function defaultCode(): string
    {
        return config('passports.default_language', 'sv');
    }
}
