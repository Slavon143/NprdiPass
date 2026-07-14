<?php

namespace App\Data\Catalog\Lifecycle;

final readonly class ReadinessItem
{
    public function __construct(
        public string $code,
        public string $message,
        public string $section,
        public string $entityType,
        public ?string $entityUuid = null,
    ) {}

    /** @return array{code: string, message: string, section: string, entity_type: string, entity_uuid: string|null} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'section' => $this->section,
            'entity_type' => $this->entityType,
            'entity_uuid' => $this->entityUuid,
        ];
    }
}
