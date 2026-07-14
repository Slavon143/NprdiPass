<?php

namespace App\Support\Catalog;

use App\Models\Catalog\AttributeDefinition;

final readonly class NormalizedAttributeValue
{
    /**
     * @param  array{value_text: string|null, value_integer: int|null, value_decimal: string|null, value_boolean: bool|null, value_date: string|null, value_option_id: int|null}  $columns
     * @param  list<int>  $optionIds
     */
    public function __construct(
        public AttributeDefinition $definition,
        public bool $clear,
        public array $columns,
        public array $optionIds = [],
    ) {}
}
