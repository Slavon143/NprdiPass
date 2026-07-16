<?php

namespace App\Data\Catalog\Integrity;

use App\Enums\Catalog\CatalogIntegritySeverity;

readonly class CatalogIntegrityIssue
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $code,
        public CatalogIntegritySeverity $severity,
        public string $companyUuid,
        public string $resourceType,
        public string $resourceUuid,
        public string $message,
        public array $context = [],
        public string $suggestedRemediation = '',
        public bool $repairable = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'company_uuid' => $this->companyUuid,
            'resource_type' => $this->resourceType,
            'resource_uuid' => $this->resourceUuid,
            'message' => $this->message,
            'context' => $this->context,
            'suggested_remediation' => $this->suggestedRemediation,
            'repairable' => $this->repairable,
        ];
    }
}
