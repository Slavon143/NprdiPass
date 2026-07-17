<?php

namespace App\Data\Passports;

use App\Data\Passports\Readiness\PassportReadinessResult;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;

readonly class PublicationResult
{
    public function __construct(
        public ProductPassport $passport,
        public ProductPassportVersion $publishedVersion,
        public ProductPassportVersion $newDraftVersion,
        public PassportReadinessResult $readinessResult,
    ) {}
}
