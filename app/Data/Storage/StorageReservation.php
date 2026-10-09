<?php

namespace App\Data\Storage;

final readonly class StorageReservation
{
    public function __construct(
        public string $companyUuid,
        public string $disk,
        public string $key,
        public string $physicalKey,
        public string $operationUuid,
        public int $bytes,
    ) {}
}
