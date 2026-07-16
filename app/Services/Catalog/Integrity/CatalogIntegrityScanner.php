<?php

namespace App\Services\Catalog\Integrity;

use App\Contracts\Catalog\Integrity\CatalogIntegrityCheck;
use App\Data\Catalog\Integrity\CatalogIntegrityReport;
use App\Models\Company;

class CatalogIntegrityScanner
{
    /** @var CatalogIntegrityCheck[] */
    private array $checks = [];

    public function addCheck(CatalogIntegrityCheck $check): void
    {
        $this->checks[] = $check;
    }

    public function scanCompany(Company $company): CatalogIntegrityReport
    {
        $report = new CatalogIntegrityReport;
        $report->companiesScanned = 1;

        foreach ($this->checks as $check) {
            $issues = $check->check($company);
            $report->addIssues($issues);
        }

        return $report;
    }

    /** @param Company[] $companies */
    public function scanCompanies(iterable $companies): CatalogIntegrityReport
    {
        $report = new CatalogIntegrityReport;

        foreach ($companies as $company) {
            $report->companiesScanned++;
            foreach ($this->checks as $check) {
                $issues = $check->check($company);
                $report->addIssues($issues);
            }
        }

        return $report;
    }

    /** @return CatalogIntegrityCheck[] */
    public function registeredChecks(): array
    {
        return $this->checks;
    }
}
