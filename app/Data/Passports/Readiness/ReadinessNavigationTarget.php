<?php

namespace App\Data\Passports\Readiness;

readonly class ReadinessNavigationTarget
{
    /**
     * @param  array<string, string>  $routeParameters
     */
    public function __construct(
        public string $type,
        public ?string $section,
        public string $routeName,
        public array $routeParameters,
        public string $label,
    ) {}
}
