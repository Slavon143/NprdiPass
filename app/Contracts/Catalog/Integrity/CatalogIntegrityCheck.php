<?php

namespace App\Contracts\Catalog\Integrity;

use App\Data\Catalog\Integrity\CatalogIntegrityIssue;
use App\Models\Company;

interface CatalogIntegrityCheck
{
    public function code(): string;

    /** @return CatalogIntegrityIssue[] */
    public function check(Company $company): array;
}
