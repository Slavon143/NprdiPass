<?php

namespace App\Data\Fortnox;

use Carbon\CarbonImmutable;

class FortnoxArticle
{
    public function __construct(
        public readonly string $id,
        public readonly string $number,
        public readonly ?string $name,
        public readonly ?string $description,
        public readonly ?float $price,
        public readonly ?float $stock,
        public readonly ?string $gtin,
        public readonly CarbonImmutable $lastModified,
    ) {}
}
