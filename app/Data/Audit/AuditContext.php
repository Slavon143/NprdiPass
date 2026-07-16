<?php

namespace App\Data\Audit;

use App\Enums\AuditSource;

readonly class AuditContext
{
    public function __construct(
        public ?string $requestId = null,
        public ?string $operationRunId = null,
        public AuditSource $source = AuditSource::System,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'request_id' => $this->requestId,
            'operation_run_id' => $this->operationRunId,
            'source' => $this->source->value,
        ], fn ($v) => $v !== null);
    }
}
