<?php

namespace App\Data\Passports;

use App\Enums\Passports\DppSectionKey;

class DppSectionDefinition
{
    /** @param DppFieldDefinition[] $fields */
    public function __construct(
        public readonly DppSectionKey $key,
        public readonly bool $core,
        public readonly bool $translatable,
        public readonly array $fields,
    ) {}
}
