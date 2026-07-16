<?php

namespace App\Console\Commands\Catalog;

use App\Enums\Catalog\CatalogIntegritySeverity;
use App\Models\Company;
use App\Services\Catalog\Integrity\CatalogIntegrityScanner;
use App\Services\Catalog\Integrity\Checks\AttributeIntegrityCheck;
use App\Services\Catalog\Integrity\Checks\CategoryIntegrityCheck;
use App\Services\Catalog\Integrity\Checks\IdentifierIntegrityCheck;
use App\Services\Catalog\Integrity\Checks\LifecycleIntegrityCheck;
use App\Services\Catalog\Integrity\Checks\MediaIntegrityCheck;
use App\Services\Catalog\Integrity\Checks\ProductIntegrityCheck;
use App\Services\Catalog\Integrity\Checks\TenantOwnershipIntegrityCheck;
use App\Services\Catalog\Integrity\Checks\VariantIntegrityCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CatalogIntegrityCheckCommand extends Command
{
    protected $signature = 'catalog:integrity-check
        {--company= : Company UUID to scan}
        {--all-companies : Scan all companies}
        {--format=table : Output format (table or json)}
        {--severity=warning : Minimum severity to display (warning, error, critical)}
        {--verify-files : Verify physical file existence}
        {--verify-checksums : Verify file checksums}
        {--fail-on=error : Severity threshold for non-zero exit code (warning, error, critical)}';

    protected $description = 'Run catalog integrity checks across one or all companies';

    public function handle(
        CatalogIntegrityScanner $scanner,
        AttributeIntegrityCheck $attributeCheck,
        CategoryIntegrityCheck $categoryCheck,
        IdentifierIntegrityCheck $identifierCheck,
        LifecycleIntegrityCheck $lifecycleCheck,
        MediaIntegrityCheck $mediaCheck,
        ProductIntegrityCheck $productCheck,
        TenantOwnershipIntegrityCheck $tenantCheck,
        VariantIntegrityCheck $variantCheck,
    ): int {
        $companyUuid = $this->option('company');
        $allCompanies = (bool) $this->option('all-companies');

        if (($companyUuid === null || $companyUuid === '') && ! $allCompanies) {
            $this->error('You must specify either --company or --all-companies.');

            return 2;
        }

        if (($companyUuid !== null && $companyUuid !== '') && $allCompanies) {
            $this->error('You cannot specify both --company and --all-companies.');

            return 2;
        }

        $format = $this->option('format') ?? 'table';
        if (! in_array($format, ['table', 'json'], true)) {
            $format = 'table';
        }

        $severityValue = $this->option('severity') ?? 'warning';
        $severity = CatalogIntegritySeverity::tryFrom($severityValue);

        if ($severity === null) {
            $this->error(sprintf('Invalid --severity value "%s". Expected one of: warning, error, critical.', $severityValue));

            return 2;
        }

        $failOnValue = $this->option('fail-on') ?? 'error';
        $failOn = CatalogIntegritySeverity::tryFrom($failOnValue);

        if ($failOn === null) {
            $this->error(sprintf('Invalid --fail-on value "%s". Expected one of: warning, error, critical.', $failOnValue));

            return 2;
        }

        $scanner->addCheck($attributeCheck);
        $scanner->addCheck($categoryCheck);
        $scanner->addCheck($identifierCheck);
        $scanner->addCheck($lifecycleCheck);
        $scanner->addCheck($mediaCheck);
        $scanner->addCheck($productCheck);
        $scanner->addCheck($tenantCheck);
        $scanner->addCheck($variantCheck);

        try {
            if ($companyUuid !== null && $companyUuid !== '') {
                if (! Str::isUuid($companyUuid)) {
                    $this->error('The --company option must be a valid company UUID.');

                    return 2;
                }

                $company = Company::withTrashed()->where('uuid', $companyUuid)->first();

                if ($company === null) {
                    $this->error('The requested company UUID was not found.');

                    return 3;
                }

                $report = $scanner->scanCompany($company);
            } else {
                $companies = [];
                Company::query()->chunkById(500, function ($chunk) use (&$companies): void {
                    foreach ($chunk as $company) {
                        $companies[] = $company;
                    }
                });

                if ($companies === []) {
                    $this->info('No companies found to scan.');

                    return 0;
                }

                $report = $scanner->scanCompanies($companies);
            }

            $issues = array_filter($report->issues(), fn ($issue) => $issue->severity->meetsOrExceeds($severity));

            if ($format === 'json') {
                $output = [
                    'summary' => $report->toArray()['summary'],
                    'issues' => array_map(fn ($issue) => $issue->toArray(), $issues),
                ];
                $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            } else {
                $rows = [];
                foreach ($issues as $issue) {
                    $rows[] = [
                        $issue->severity->value,
                        $issue->code,
                        $issue->resourceType,
                        $issue->resourceUuid,
                        $issue->message,
                    ];
                }

                if ($rows === []) {
                    $this->info('No integrity issues found at or above the specified severity.');
                } else {
                    $this->table(
                        ['Severity', 'Code', 'Resource Type', 'Resource UUID', 'Message'],
                        $rows,
                    );
                }

                $summary = $report->toArray()['summary'];
                $this->info(sprintf(
                    'Scanned %d companies. Total issues: %d (info: %d, warning: %d, error: %d, critical: %d).',
                    $summary['companies_scanned'],
                    $summary['issues_total'],
                    $summary['info'],
                    $summary['warning'],
                    $summary['error'],
                    $summary['critical'],
                ));
            }

            if ($report->hasIssuesAtOrAbove($failOn)) {
                return 1;
            }

            return 0;
        } catch (\Throwable $e) {
            $this->error(sprintf('Operational failure: %s', $e->getMessage()));

            return 3;
        }
    }
}
