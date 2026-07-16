<?php

namespace App\Console\Commands\Catalog;

use App\Models\Company;
use App\Services\Catalog\Operations\CatalogSummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CatalogSummaryCommand extends Command
{
    protected $signature = 'catalog:summary
        {--company= : Company UUID to summarize}
        {--all-companies : Summarize all companies}
        {--format=table : Output format (table or json)}
        {--verify-files : Verify physical file existence}';

    protected $description = 'Display catalog statistics summary for one or all companies';

    public function handle(CatalogSummaryService $summaryService): int
    {
        $companyUuid = $this->option('company');
        $allCompanies = (bool) $this->option('all-companies');
        $verifyFiles = (bool) $this->option('verify-files');

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

                $summaries = [$summaryService->summarize($company, $verifyFiles)];
            } else {
                $summaries = [];
                Company::query()->chunkById(500, function ($chunk) use ($summaryService, $verifyFiles, &$summaries): void {
                    foreach ($chunk as $company) {
                        $summaries[] = $summaryService->summarize($company, $verifyFiles);
                    }
                });
            }

            if ($summaries === []) {
                $this->info('No companies found.');

                return 0;
            }

            if ($format === 'json') {
                $output = array_map(fn ($summary) => $summary->toArray(), $summaries);
                $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $rows = [];
                foreach ($summaries as $summary) {
                    $rows[] = [
                        $summary->companyName,
                        $summary->categoriesCount,
                        $summary->activeProducts,
                        $summary->draftProducts,
                        $summary->archivedProducts,
                        $summary->activeVariants,
                        $summary->draftVariants,
                        $summary->archivedVariants,
                        $summary->mediaCount,
                        $summary->staleDraftsCount,
                    ];
                }

                $this->table(
                    ['Company', 'Categories', 'Active Prod', 'Draft Prod', 'Arch Prod', 'Active Var', 'Draft Var', 'Arch Var', 'Media', 'Stale Drafts'],
                    $rows,
                );

                $this->info(sprintf('%d companies summarized.', count($summaries)));
            }

            return 0;
        } catch (\Throwable $e) {
            $this->error(sprintf('Operational failure: %s', $e->getMessage()));

            return 3;
        }
    }
}
