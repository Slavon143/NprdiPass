<?php

namespace App\Enums\Catalog;

enum CatalogIntegritySeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';

    public function threshold(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Error => 2,
            self::Critical => 3,
        };
    }

    public function meetsOrExceeds(self $threshold): bool
    {
        return $this->threshold() >= $threshold->threshold();
    }
}
