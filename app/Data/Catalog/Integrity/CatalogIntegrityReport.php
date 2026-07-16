<?php

namespace App\Data\Catalog\Integrity;

use App\Enums\Catalog\CatalogIntegritySeverity;

class CatalogIntegrityReport
{
    /** @var CatalogIntegrityIssue[] */
    private array $issues = [];

    public int $companiesScanned = 0;

    public function addIssue(CatalogIntegrityIssue $issue): void
    {
        $this->issues[] = $issue;
    }

    /** @param CatalogIntegrityIssue[] $issues */
    public function addIssues(array $issues): void
    {
        foreach ($issues as $issue) {
            $this->addIssue($issue);
        }
    }

    public function issuesTotal(): int
    {
        return count($this->issues);
    }

    public function countBySeverity(CatalogIntegritySeverity $severity): int
    {
        return count(array_filter($this->issues, fn ($i) => $i->severity === $severity));
    }

    public function info(): int
    {
        return $this->countBySeverity(CatalogIntegritySeverity::Info);
    }

    public function warning(): int
    {
        return $this->countBySeverity(CatalogIntegritySeverity::Warning);
    }

    public function error(): int
    {
        return $this->countBySeverity(CatalogIntegritySeverity::Error);
    }

    public function critical(): int
    {
        return $this->countBySeverity(CatalogIntegritySeverity::Critical);
    }

    /** @return CatalogIntegrityIssue[] */
    public function issues(): array
    {
        return $this->issues;
    }

    public function hasIssuesAtOrAbove(CatalogIntegritySeverity $threshold): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity->meetsOrExceeds($threshold)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'summary' => [
                'companies_scanned' => $this->companiesScanned,
                'issues_total' => $this->issuesTotal(),
                'info' => $this->info(),
                'warning' => $this->warning(),
                'error' => $this->error(),
                'critical' => $this->critical(),
            ],
            'issues' => array_map(fn ($i) => $i->toArray(), $this->issues),
        ];
    }
}
