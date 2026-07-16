<?php

namespace App\Data\Catalog\Audit;

use App\Enums\AuditEvent;
use Carbon\CarbonImmutable;

readonly class CatalogAuditSearchCriteria
{
    public function __construct(
        public ?AuditEvent $event = null,
        public ?string $actorUuid = null,
        public ?string $resourceType = null,
        public ?string $resourceUuid = null,
        public ?string $requestId = null,
        public ?CarbonImmutable $dateFrom = null,
        public ?CarbonImmutable $dateTo = null,
        public ?string $q = null,
        public int $perPage = 50,
        public string $sort = 'created_at',
        public string $direction = 'desc',
    ) {}

    public function hasFilters(): bool
    {
        return $this->event !== null
            || $this->actorUuid !== null
            || $this->resourceType !== null
            || $this->resourceUuid !== null
            || $this->requestId !== null
            || $this->dateFrom !== null
            || $this->dateTo !== null
            || $this->q !== null;
    }
}
