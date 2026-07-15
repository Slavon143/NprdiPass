<?php

namespace App\Data\Catalog\Search;

final readonly class CatalogProductSearchCriteria
{
    /**
     * @param  list<string>  $productStatuses
     * @param  list<string>  $variantStatuses
     * @param  list<int>  $categoryIds
     * @param  list<string>  $categoryUuids
     * @param  list<string>  $missingData
     * @param  list<CatalogAttributeFilterCriteria>  $attributeFilters
     */
    public function __construct(
        public string $query = '',
        public array $productStatuses = ['draft', 'active'],
        public array $variantStatuses = [],
        public array $categoryIds = [],
        public array $categoryUuids = [],
        public string $categoryMode = 'primary',
        public bool $includeDescendants = false,
        public ?string $brand = null,
        public ?string $manufacturer = null,
        public string $readiness = 'any',
        public array $missingData = [],
        public array $attributeFilters = [],
        public string $sort = 'updated',
        public string $direction = 'desc',
        public int $perPage = 25,
    ) {}

    public function hasFilters(): bool
    {
        return $this->query !== ''
            || $this->productStatuses !== ['draft', 'active']
            || $this->variantStatuses !== []
            || $this->categoryIds !== []
            || $this->brand !== null
            || $this->manufacturer !== null
            || $this->readiness !== 'any'
            || $this->missingData !== []
            || $this->attributeFilters !== []
            || $this->sort !== 'updated'
            || $this->direction !== 'desc'
            || $this->perPage !== 25;
    }
}
