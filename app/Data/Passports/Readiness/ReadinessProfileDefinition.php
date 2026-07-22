<?php

namespace App\Data\Passports\Readiness;

readonly class ReadinessProfileDefinition
{
    /**
     * @param  array{blocker: int, warning: int, recommendation: int}  $weights
     * @param  array<int, class-string>  $ruleClasses
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $code,
        public int $version,
        public string $name,
        public string $description,
        public string $status,
        public int $ruleSetVersion,
        public string $scoreAlgorithm,
        public int $scoreAlgorithmVersion,
        public array $weights,
        public array $ruleClasses,
        public string $fingerprint,
        public array $metadata = [],
    ) {}
}
