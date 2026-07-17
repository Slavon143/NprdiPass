<?php

namespace App\Data\Passports\Localization;

readonly class TranslationCompletenessResult
{
    /** @param  array<int, array{section: string, field: string, required: bool}>  $missing */
    public function __construct(
        public string $locale,
        public string $status,
        public int $completion,
        public int $requiredTotal,
        public int $requiredComplete,
        public int $optionalTotal,
        public int $optionalComplete,
        public array $missing,
    ) {}

    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }

    public function hasRequiredMissing(): bool
    {
        return $this->requiredComplete < $this->requiredTotal;
    }
}
