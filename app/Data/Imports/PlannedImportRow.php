<?php

namespace App\Data\Imports;

final readonly class PlannedImportRow
{
    /** @param array<string, mixed> $mapped @param list<array{field: string|null, code: string, message: string}> $errors */
    public function __construct(
        public array $mapped,
        public string $operation,
        public ?int $targetProductId,
        public array $errors,
    ) {}
}
