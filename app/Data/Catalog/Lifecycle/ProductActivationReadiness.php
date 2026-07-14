<?php

namespace App\Data\Catalog\Lifecycle;

use Carbon\CarbonImmutable;

final readonly class ProductActivationReadiness
{
    /**
     * @param  list<ReadinessItem>  $blockers
     * @param  list<ReadinessItem>  $warnings
     */
    public function __construct(
        public bool $ready,
        public array $blockers,
        public array $warnings,
        public CarbonImmutable $checkedAt,
        public int $requiredProductAttributesChecked,
        public int $requiredVariantAttributesChecked,
        public int $variantCount,
    ) {}

    /** @return list<string> */
    public function blockerCodes(): array
    {
        return array_values(array_unique(array_map(
            fn (ReadinessItem $item): string => $item->code,
            $this->blockers,
        )));
    }

    /** @return list<string> */
    public function warningCodes(): array
    {
        return array_values(array_unique(array_map(
            fn (ReadinessItem $item): string => $item->code,
            $this->warnings,
        )));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'blockers' => array_map(fn (ReadinessItem $item): array => $item->toArray(), $this->blockers),
            'warnings' => array_map(fn (ReadinessItem $item): array => $item->toArray(), $this->warnings),
            'checked_at' => $this->checkedAt->toIso8601String(),
        ];
    }
}
