<?php

namespace App\Data\Passports\Readiness;

readonly class ReadinessSummary
{
    public function __construct(
        public int $passed,
        public int $blockers,
        public int $warnings,
        public int $recommendations,
        public int $notApplicable,
    ) {}
}
