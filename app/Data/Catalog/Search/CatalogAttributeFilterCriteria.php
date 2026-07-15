<?php

namespace App\Data\Catalog\Search;

use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeScope;

final readonly class CatalogAttributeFilterCriteria
{
    /**
     * @param  list<int>  $optionIds
     */
    public function __construct(
        public int $definitionId,
        public string $definitionUuid,
        public string $label,
        public AttributeDataType $type,
        public AttributeScope $scope,
        public array $optionIds = [],
        public ?string $boolean = null,
        public ?string $min = null,
        public ?string $max = null,
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    public function hasValue(): bool
    {
        return $this->optionIds !== []
            || $this->boolean !== null
            || $this->min !== null
            || $this->max !== null
            || $this->from !== null
            || $this->to !== null;
    }
}
