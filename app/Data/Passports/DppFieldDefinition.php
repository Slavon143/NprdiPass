<?php

namespace App\Data\Passports;

use App\Enums\Passports\DppFieldType;
use App\Enums\Passports\DppSectionKey;

class DppFieldDefinition
{
    /** @param array<string, mixed> $bounds */
    public function __construct(
        public readonly string $key,
        public readonly DppFieldType $type,
        public readonly bool $translatable,
        public readonly bool $nullable,
        public readonly DppSectionKey $section,
        public readonly ?int $maxLength = null,
        public readonly ?int $maxItems = null,
        public readonly ?float $min = null,
        public readonly ?float $max = null,
        public readonly array $bounds = [],
    ) {}
}
